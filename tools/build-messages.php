<?php
/**
 * Builds data/catalog/messages.php: the messages of PHP itself that the
 * run-time monitor can explain.
 *
 *   php tools/build-messages.php            (or: node tools/php.mjs tools/build-messages.php)
 *
 * Input: every tools/fixtures/*.observed.json, the record of what the real
 * PHP runtimes printed for a fixture and of the claim that was verified.
 *
 * A message enters the list only when it is the verified change itself:
 *
 *   deprecation  the E_DEPRECATED a runtime raised on the first version the
 *                claim says "deprecated_in" (7.4 when the claim is older)
 *   fatal        the error on the first version the claim says "breaks_in"
 *   early sign   a warning or notice on the last version before the code
 *                breaks or changes, for example "count(): Parameter must be an
 *                array ..." on 7.4, which PHP 8.0 turns into a TypeError.
 *                Only for the fixtures listed in catalog-src/early-signs.json,
 *                which were reviewed by hand: a fixture can print a warning
 *                that has nothing to do with the change
 *
 * Anything else a fixture happened to print is not evidence of the change and
 * is left out. So is a message that fixtures with different claims share, or
 * that a fixture claiming "nothing happens" also printed: it cannot be told
 * apart, and a wrong "this will break" costs more than a missing one.
 *
 * @package UpgradeWarden
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );

$root = dirname( __DIR__ );

require $root . '/src/Monitor/Signatures.php';

use TYStudio\UpgradeWarden\Monitor\Signatures;

$versions = array( '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5' );
$number   = static function ( $version ) {
	$parts = explode( '.', $version );

	return (int) $parts[0] * 100 + (int) $parts[1];
};

/** Splits "E_DEPRECATED: text" or "TypeError: text" into type and text. */
$split = static function ( $message ) {
	$at = strpos( $message, ': ' );

	return false === $at ? array( '', $message ) : array( substr( $message, 0, $at ), substr( $message, $at + 2 ) );
};

$early      = json_decode( file_get_contents( $root . '/tools/catalog-src/early-signs.json' ), true );
$skipped    = array();
$candidates = array(); // key => list of array( rule, deprecated, changed, breaks, kind, fixture )
$incidental = array(); // key => true: printed where it is not the change itself
$files      = glob( $root . '/tools/fixtures/*.observed.json' );

sort( $files );

foreach ( $files as $file ) {
	$record = json_decode( file_get_contents( $file ), true );

	if ( ! is_array( $record ) || 'verified' !== $record['verdict'] ) {
		continue;
	}

	$claim = $record['claim'] + array(
		'deprecated_in' => null,
		'changed_in'    => null,
		'breaks_in'     => null,
	);
	$entry = array( $record['rule'], $claim['deprecated_in'], $claim['changed_in'], $claim['breaks_in'] );
	$name  = basename( $file, '.observed.json' );
	$taken = array();

	$at = static function ( $since ) use ( $versions, $number ) {
		// The first runtime at or after the claimed version.
		foreach ( $versions as $version ) {
			if ( $number( $version ) >= $number( $since ) ) {
				return $version;
			}
		}

		return null;
	};

	if ( null !== $claim['deprecated_in'] ) {
		$version = $at( $claim['deprecated_in'] );

		if ( null !== $version && isset( $record['messages'][ $version ] ) ) {
			list( $type, $text ) = $split( $record['messages'][ $version ] );

			if ( 'E_DEPRECATED' === $type ) {
				$key                  = Signatures::normalize( $type, $text );
				$candidates[ $key ][] = array_merge( $entry, array( 'deprecation', $name ) );
				$taken[ $key ]        = true;
			}
		}
	}

	if ( null !== $claim['breaks_in'] ) {
		$version = $at( $claim['breaks_in'] );

		if ( null !== $version && isset( $record['messages'][ $version ] ) && 'fatal' === $record['observed'][ $version ] ) {
			list( $type, $text ) = $split( $record['messages'][ $version ] );
			$key                  = Signatures::normalize( $type, $text );

			// The monitor keeps a compile error and an uncaught Error of PHP, not
			// an uncaught exception: on a site that is the site's own business.
			if ( 0 === strpos( $type, 'E_' ) || 'Error' === $type || is_subclass_of( $type, 'Error' ) ) {
				$candidates[ $key ][] = array_merge( $entry, array( 'fatal', $name ) );
			} else {
				$skipped[] = "an exception, not an Error	{$key}";
			}

			$taken[ $key ] = true;
		}
	}

	// The early sign: a warning or notice on the version just before the code
	// breaks or changes, for the fixtures that were reviewed and listed.
	$approved = false;

	foreach ( $early['fixtures'] as $pattern ) {
		$approved = $approved || fnmatch( $pattern, $name );
	}

	$since = null !== $claim['breaks_in'] ? $claim['breaks_in'] : $claim['changed_in'];

	if ( $approved && null !== $since && null !== $at( $since ) ) {
		$before = array_search( $at( $since ), $versions, true ) - 1;

		if ( $before >= 0 && isset( $record['messages'][ $versions[ $before ] ] ) ) {
			list( $early_type, $early_text ) = $split( $record['messages'][ $versions[ $before ] ] );

			if ( in_array( $early_type, array( 'E_WARNING', 'E_NOTICE' ), true ) ) {
				$key                  = Signatures::normalize( $early_type, $early_text );
				$candidates[ $key ][] = array_merge( $entry, array( 'early sign', $name ) );
				$taken[ $key ]        = true;
			}
		}
	}

	foreach ( $record['messages'] as $message ) {
		list( $type, $text ) = $split( $message );
		$key                  = Signatures::normalize( $type, $text );

		if ( ! isset( $taken[ $key ] ) ) {
			$incidental[ $key ] = true;
		}
	}
}

$map      = array();
$dropped  = $skipped;
$by_kind  = array();

ksort( $candidates );

foreach ( $candidates as $key => $entries ) {
	$claims = array();

	foreach ( $entries as $entry ) {
		$claims[ json_encode( array_slice( $entry, 1, 3 ) ) ] = true;
	}

	if ( count( $claims ) > 1 ) {
		$dropped[] = "different claims\t{$key}";
		continue;
	}

	if ( isset( $incidental[ $key ] ) ) {
		$dropped[] = "also printed elsewhere\t{$key}";
		continue;
	}

	// Prefer the rule the scanner knows over a "runtime." record of the same message.
	usort(
		$entries,
		static function ( $a, $b ) {
			return ( 0 === strpos( $a[0], 'runtime.' ) ) - ( 0 === strpos( $b[0], 'runtime.' ) );
		}
	);

	$map[ $key ] = array_slice( $entries[0], 0, 4 );
	$by_kind[ $entries[0][4] ] = isset( $by_kind[ $entries[0][4] ] ) ? $by_kind[ $entries[0][4] ] + 1 : 1;
}

$export = static function ( $value ) {
	return null === $value ? 'null' : "'" . addcslashes( $value, "'\\" ) . "'";
};
$lines  = array();

foreach ( $map as $key => $entry ) {
	$lines[] = "\t" . $export( $key ) . ' => array( ' . implode( ', ', array_map( $export, $entry ) ) . ' ),';
}

$php = "<?php\n/**\n * Messages of PHP itself, by the change they belong to.\n *\n * GENERATED by tools/build-messages.php from the records of real PHP runtimes\n * (tools/fixtures/*.observed.json). Do not edit.\n *\n * Key: the message with numbers and quoted values blanked, see\n * Monitor\\Signatures::normalize(). Value: rule id, then the versions from\n * which PHP deprecates, silently changes and refuses the code (null: never).\n *\n * @package UpgradeWarden\n */\n\ndefined( 'ABSPATH' ) || exit;\n\nreturn array(\n" . implode( "\n", $lines ) . "\n);\n";

file_put_contents( $root . '/data/catalog/messages.php', $php );

if ( ! is_dir( $root . '/tools/out' ) ) {
	mkdir( $root . '/tools/out' );
}

file_put_contents( $root . '/tools/out/messages-dropped.txt', implode( "\n", $dropped ) . "\n" );

printf( "%d messages written to data/catalog/messages.php (%s). %d left out, listed in tools/out/messages-dropped.txt.\n", count( $map ), implode( ', ', array_map( static function ( $kind, $count ) { return "{$count} {$kind}"; }, array_keys( $by_kind ), $by_kind ) ), count( $dropped ) );
