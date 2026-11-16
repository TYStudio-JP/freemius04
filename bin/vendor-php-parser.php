<?php
/**
 * Copies nikic/PHP-Parser into lib/php-parser/ under this plugin's own namespace.
 *
 *   php bin/vendor-php-parser.php /path/to/PHP-Parser v5.9.0
 *
 * The first argument is a checkout of https://github.com/nikic/PHP-Parser at
 * the tag given as the second argument.
 *
 * Why a renamed copy: another plugin on the same site may bundle a different
 * version of PHP-Parser. PHP can load only one class of a given name, so two
 * copies under the original namespace would break one of the two plugins.
 * Under TYStudio\UpgradeWarden\Vendor\PhpParser this copy cannot collide.
 *
 * PHP-Parser is BSD-3-Clause; its licence file is copied alongside.
 *
 * @package UpgradeWarden
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

if ( $argc < 3 ) {
	fwrite( STDERR, "Usage: php bin/vendor-php-parser.php /path/to/PHP-Parser <tag>\n" );
	exit( 2 );
}

$upstream = rtrim( $argv[1], '/' );
$tag      = $argv[2];
$source   = $upstream . '/lib/PhpParser';
$target   = dirname( __DIR__ ) . '/lib/php-parser';
$prefix   = 'TYStudio\\UpgradeWarden\\Vendor\\';

if ( ! is_dir( $source ) || ! is_file( $upstream . '/LICENSE' ) ) {
	fwrite( STDERR, "Not a PHP-Parser checkout: {$upstream}\n" );
	exit( 2 );
}

/**
 * Rewrites every reference to the PhpParser namespace in one file.
 *
 * @param string $code   File contents.
 * @param string $prefix Namespace to put in front, with a trailing backslash.
 * @return string
 */
function upgw_rename_namespace( $code, $prefix ) {
	$escaped = str_replace( '\\', '\\\\', $prefix );

	// Inside string literals the separator is written as two backslashes.
	$code = str_replace( 'PhpParser\\\\', "\0STRING\0", $code );

	$code = preg_replace( '/^namespace PhpParser(?=[;\\\\])/m', 'namespace ' . $escaped . 'PhpParser', $code );
	$code = preg_replace( '/^use PhpParser(?=[;\\\\])/m', 'use ' . $escaped . 'PhpParser', $code );
	$code = preg_replace( '/(?<![A-Za-z0-9_\\\\])\\\\PhpParser\\\\/', '\\\\' . $escaped . 'PhpParser\\\\', $code );

	// A name written in a quoted string with a single backslash, as in function_exists( 'PhpParser\name' ).
	$code = preg_replace( '/([\'"])PhpParser\\\\(?=[A-Za-z_])/', '$1' . $escaped . 'PhpParser\\\\', $code );

	return str_replace( "\0STRING\0", str_replace( '\\', '\\\\', $prefix ) . 'PhpParser\\\\', $code );
}

/**
 * Lists what still points at the original namespace, looking at code only.
 *
 * @param string $code   Renamed file contents.
 * @param string $prefix Namespace put in front, with a trailing backslash.
 * @return string[] The offending pieces of code.
 */
function upgw_leftovers( $code, $prefix ) {
	$imports_alias = false !== strpos( $code, 'use ' . $prefix . 'PhpParser;' );
	$found         = array();

	foreach ( token_get_all( $code ) as $token ) {
		if ( ! is_array( $token ) ) {
			continue;
		}

		list( $type, $text ) = $token;

		if ( T_NAME_FULLY_QUALIFIED === $type && 0 === strpos( $text, '\\PhpParser\\' ) ) {
			$found[] = $text;
		} elseif ( T_NAME_QUALIFIED === $type && 0 === strpos( $text, 'PhpParser\\' ) && ! $imports_alias ) {
			$found[] = $text;
		} elseif ( T_CONSTANT_ENCAPSED_STRING === $type && preg_match( '/^[\'"]\\\\{0,2}PhpParser\\\\/', $text ) ) {
			$found[] = $text;
		}
	}

	return $found;
}

/**
 * Deletes a directory and everything in it.
 *
 * @param string $dir Directory.
 */
function upgw_remove_dir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $items as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}

	rmdir( $dir );
}

upgw_remove_dir( $target );
mkdir( $target . '/PhpParser', 0755, true );

$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ) );
$count = 0;
$left  = array();

foreach ( $files as $file ) {
	$relative = substr( $file->getPathname(), strlen( $source ) );
	$out      = $target . '/PhpParser' . $relative;

	if ( ! is_dir( dirname( $out ) ) ) {
		mkdir( dirname( $out ), 0755, true );
	}

	$code = file_get_contents( $file->getPathname() );

	if ( 'php' === $file->getExtension() ) {
		$code = upgw_rename_namespace( $code, $prefix );

		// Anything still pointing at the original namespace would load the wrong class.
		foreach ( upgw_leftovers( $code, $prefix ) as $piece ) {
			$left[] = $relative . ': ' . $piece;
		}
	}

	file_put_contents( $out, $code );
	++$count;
}

copy( $upstream . '/LICENSE', $target . '/LICENSE' );
file_put_contents(
	$target . '/README.md',
	"# PHP-Parser (renamed copy)\n\n" .
	"Source: https://github.com/nikic/PHP-Parser, tag `{$tag}`. Licence: BSD-3-Clause, see `LICENSE`.\n\n" .
	"Copied by `bin/vendor-php-parser.php`, which moves every class from the `PhpParser` namespace to\n" .
	"`TYStudio\\UpgradeWarden\\Vendor\\PhpParser` so that it cannot collide with a copy bundled by another plugin.\n" .
	"Nothing else is changed. Do not edit these files by hand; run the script again to update.\n"
);

echo "Copied {$count} files from {$tag} into lib/php-parser/.\n";

if ( $left ) {
	echo "References to the original namespace remain:\n  " . implode( "\n  ", $left ) . "\n";
	exit( 1 );
}
