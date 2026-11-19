<?php
/**
 * Translation tooling without external dependencies.
 *
 *   (without PHP on the command line: node tools/php.mjs bin/i18n.php pot)
 *
 *   php bin/i18n.php pot    Extract strings from the PHP sources into languages/upgrade-warden.pot
 *   php bin/i18n.php ja     Build languages/upgrade-warden-ja.po and .mo from languages/src/ja.php
 *   php bin/i18n.php check  Fail when a string has no Japanese translation or a placeholder differs
 *
 * WP-CLI's "wp i18n make-pot" and gettext's msgfmt do the same jobs; this
 * script exists so the files can be rebuilt anywhere PHP runs.
 *
 * @package UpgradeWarden
 */

// phpcs:disable

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

const UPGW_I18N_DOMAIN = 'upgrade-warden';

$root    = dirname( __DIR__ );
$command = $argv[1] ?? '';

/**
 * Finds translation calls in a PHP file.
 *
 * @return array[] Entries with: singular, plural (or null), comment (or ''), ref.
 */
function upgw_i18n_extract( string $file, string $root ): array {
	$tokens    = token_get_all( (string) file_get_contents( $file ) );
	$functions = array( '__' => 1, 'esc_html__' => 1, 'esc_attr__' => 1, '_n' => 2 );
	$found     = array();
	$comment   = '';
	$count     = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( is_array( $token ) && T_COMMENT === $token[0] && false !== stripos( $token[1], 'translators:' ) ) {
			$comment = trim( preg_replace( '/^\/\*\s*|\s*\*\/$|^\/\/\s*/', '', $token[1] ) );
			continue;
		}

		if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( $functions[ $token[1] ] ) ) {
			continue;
		}

		// Collect the arguments up to the matching parenthesis.
		$j = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			$j++;
		}
		if ( $j >= $count || '(' !== $tokens[ $j ] ) {
			continue;
		}

		$depth   = 0;
		$args    = array();
		$current = array();

		for ( ; $j < $count; $j++ ) {
			$t = $tokens[ $j ];

			if ( '(' === $t ) {
				if ( $depth > 0 ) {
					$current[] = $t;
				}
				$depth++;
			} elseif ( ')' === $t ) {
				$depth--;
				if ( 0 === $depth ) {
					$args[] = $current;
					break;
				}
				$current[] = $t;
			} elseif ( ',' === $t && 1 === $depth ) {
				$args[]  = $current;
				$current = array();
			} else {
				$current[] = $t;
			}
		}

		$strings = array();
		foreach ( $args as $arg ) {
			$arg = array_values( array_filter( $arg, static function ( $t ) {
				return ! ( is_array( $t ) && T_WHITESPACE === $t[0] );
			} ) );
			$strings[] = 1 === count( $arg ) && is_array( $arg[0] ) && T_CONSTANT_ENCAPSED_STRING === $arg[0][0]
				? stripcslashes( substr( $arg[0][1], 1, -1 ) ) === '' ? '' : upgw_i18n_unquote( $arg[0][1] )
				: null;
		}

		$needed = $functions[ $token[1] ];
		$domain = 1 === $needed ? ( $strings[1] ?? null ) : ( $strings[3] ?? null );

		if ( UPGW_I18N_DOMAIN !== $domain || null === ( $strings[0] ?? null ) ) {
			continue;
		}

		$found[] = array(
			'singular' => $strings[0],
			'plural'   => 2 === $needed ? $strings[1] : null,
			'comment'  => $comment,
			'ref'      => ltrim( str_replace( $root, '', $file ), '/' ) . ':' . $token[2],
		);
		$comment = '';
	}

	return $found;
}

/** Value of a single- or double-quoted PHP string literal without interpolation. */
function upgw_i18n_unquote( string $literal ): string {
	$body = substr( $literal, 1, -1 );

	if ( "'" === $literal[0] ) {
		return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $body );
	}

	return stripcslashes( $body );
}

/** All strings of the plugin, merged by msgid. */
function upgw_i18n_catalog( string $root ): array {
	$files = array( $root . '/upgrade-warden.php' );

	foreach ( array( 'src', 'premium' ) as $dir ) {
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
	}

	sort( $files );
	$catalog = array();

	foreach ( $files as $file ) {
		foreach ( upgw_i18n_extract( $file, $root ) as $entry ) {
			$key = $entry['singular'];

			if ( ! isset( $catalog[ $key ] ) ) {
				$catalog[ $key ] = array( 'singular' => $entry['singular'], 'plural' => $entry['plural'], 'comment' => $entry['comment'], 'refs' => array() );
			}
			if ( null !== $entry['plural'] ) {
				$catalog[ $key ]['plural'] = $entry['plural'];
			}
			if ( '' !== $entry['comment'] && '' === $catalog[ $key ]['comment'] ) {
				$catalog[ $key ]['comment'] = $entry['comment'];
			}
			$catalog[ $key ]['refs'][] = $entry['ref'];
		}
	}

	return $catalog;
}

function upgw_i18n_po_string( string $text ): string {
	return '"' . addcslashes( $text, "\0..\37\"\\" ) . '"';
}

/** Writes a .pot or .po file. $translations: msgid => string, or [singular form, ...] for plurals. */
function upgw_i18n_write_po( string $path, array $catalog, array $header, array $translations = array() ): void {
	$out = "# Copyright (C) TYStudio\n# This file is distributed under the GPL-2.0-or-later.\nmsgid \"\"\nmsgstr \"\"\n";

	foreach ( $header as $name => $value ) {
		$out .= upgw_i18n_po_string( $name . ': ' . $value . "\n" ) . "\n";
	}

	foreach ( $catalog as $entry ) {
		$out .= "\n";
		if ( '' !== $entry['comment'] ) {
			$out .= '#. ' . $entry['comment'] . "\n";
		}
		foreach ( array_unique( $entry['refs'] ) as $ref ) {
			$out .= '#: ' . $ref . "\n";
		}
		$out .= 'msgid ' . upgw_i18n_po_string( $entry['singular'] ) . "\n";

		$translation = $translations[ $entry['singular'] ] ?? null;

		if ( null !== $entry['plural'] ) {
			$out .= 'msgid_plural ' . upgw_i18n_po_string( $entry['plural'] ) . "\n";
			if ( $translations ) {
				// Japanese has a single plural form.
				$out .= 'msgstr[0] ' . upgw_i18n_po_string( (string) $translation ) . "\n";
			} else {
				$out .= "msgstr[0] \"\"\nmsgstr[1] \"\"\n";
			}
		} else {
			$out .= 'msgstr ' . upgw_i18n_po_string( (string) $translation ) . "\n";
		}
	}

	file_put_contents( $path, $out );
}

/** Writes a GNU gettext .mo file. */
function upgw_i18n_write_mo( string $path, array $catalog, array $header, array $translations ): void {
	$head = '';
	foreach ( $header as $name => $value ) {
		$head .= $name . ': ' . $value . "\n";
	}

	$pairs = array( '' => $head );

	foreach ( $catalog as $entry ) {
		$translation = $translations[ $entry['singular'] ] ?? '';
		if ( '' === $translation ) {
			continue;
		}
		$id           = null !== $entry['plural'] ? $entry['singular'] . "\0" . $entry['plural'] : $entry['singular'];
		$pairs[ $id ] = $translation;
	}

	ksort( $pairs, SORT_STRING );

	$count   = count( $pairs );
	$ids     = '';
	$strings = '';
	$id_tab  = array();
	$str_tab = array();

	foreach ( $pairs as $id => $string ) {
		$id_tab[]  = array( strlen( $id ), strlen( $ids ) );
		$ids      .= $id . "\0";
		$str_tab[] = array( strlen( $string ), strlen( $strings ) );
		$strings  .= $string . "\0";
	}

	$id_offset  = 28 + 16 * $count;
	$str_offset = $id_offset + strlen( $ids );

	$mo = pack( 'V7', 0x950412de, 0, $count, 28, 28 + 8 * $count, 0, 28 + 16 * $count );
	foreach ( $id_tab as $row ) {
		$mo .= pack( 'VV', $row[0], $id_offset + $row[1] );
	}
	foreach ( $str_tab as $row ) {
		$mo .= pack( 'VV', $row[0], $str_offset + $row[1] );
	}

	file_put_contents( $path, $mo . $ids . $strings );
}

/** Placeholders such as %s, %d, %1$s in a string, sorted. */
function upgw_i18n_placeholders( string $text ): array {
	preg_match_all( '/%(?:\d+\$)?[sd]/', $text, $matches );
	$found = $matches[0];
	sort( $found );
	return $found;
}

$catalog = upgw_i18n_catalog( $root );
$version = preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents( $root . '/upgrade-warden.php' ), $m ) ? $m[1] : '0';
$base    = array(
	'Project-Id-Version' => 'Upgrade Warden ' . $version,
	'MIME-Version'       => '1.0',
	'Content-Type'       => 'text/plain; charset=UTF-8',
	'Content-Transfer-Encoding' => '8bit',
	'X-Domain'           => UPGW_I18N_DOMAIN,
);

if ( ! is_dir( $root . '/languages' ) ) {
	mkdir( $root . '/languages' );
}

if ( 'pot' === $command ) {
	upgw_i18n_write_po( $root . '/languages/upgrade-warden.pot', $catalog, $base + array( 'Plural-Forms' => 'nplurals=2; plural=(n != 1);' ) );
	echo count( $catalog ) . " strings written to languages/upgrade-warden.pot\n";
	exit( 0 );
}

if ( 'ja' === $command || 'check' === $command ) {
	// ja.php is written by hand; ja-rules.php is generated by tools/build-ruletext.mjs.
	$translations = ( require $root . '/languages/src/ja.php' ) + ( require $root . '/languages/src/ja-rules.php' );
	$problems     = array();

	foreach ( $catalog as $id => $entry ) {
		if ( ! isset( $translations[ $id ] ) || '' === $translations[ $id ] ) {
			$problems[] = 'missing: ' . $id;
			continue;
		}

		$expected = upgw_i18n_placeholders( null !== $entry['plural'] ? $entry['plural'] : $id );
		if ( upgw_i18n_placeholders( $translations[ $id ] ) !== $expected ) {
			$problems[] = 'placeholders differ: ' . $id;
		}
	}

	foreach ( array_keys( $translations ) as $id ) {
		if ( ! isset( $catalog[ $id ] ) ) {
			$problems[] = 'unused: ' . $id;
		}
	}

	if ( $problems ) {
		echo implode( "\n", $problems ) . "\n" . count( $problems ) . " problems\n";
		exit( 1 );
	}

	if ( 'ja' === $command ) {
		$header = $base + array( 'Language' => 'ja', 'Plural-Forms' => 'nplurals=1; plural=0;' );
		upgw_i18n_write_po( $root . '/languages/upgrade-warden-ja.po', $catalog, $header, $translations );
		upgw_i18n_write_mo( $root . '/languages/upgrade-warden-ja.mo', $catalog, $header, $translations );
	}

	echo count( $catalog ) . " strings, all translated\n";
	exit( 0 );
}

if ( 'list' === $command ) {
	foreach ( $catalog as $entry ) {
		echo json_encode( null !== $entry['plural'] ? array( $entry['singular'], $entry['plural'] ) : $entry['singular'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
	}
	exit( 0 );
}

fwrite( STDERR, "Usage: php bin/i18n.php pot|ja|check|list\n" );
exit( 2 );
