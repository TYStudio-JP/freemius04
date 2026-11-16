<?php
/**
 * Scans a file or a folder from the command line. A development aid, and the
 * way the engine is tried on real code before it has a WordPress screen.
 *
 *   php bin/scan.php <path> [--target=8.5] [--summary] [--guarded]
 *
 * --target   PHP version the code should run on (default 8.5)
 * --summary  only the counts per rule, not every finding
 * --guarded  also list findings behind a function_exists() or version check
 *
 * Exit code 1 when something breaks or changes on the target version.
 *
 * @package UpgradeWarden
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/src/autoload.php';

use TYStudio\UpgradeWarden\Engine\Catalog;
use TYStudio\UpgradeWarden\Engine\Finding;
use TYStudio\UpgradeWarden\Engine\Scanner;
use TYStudio\UpgradeWarden\Engine\ScanResult;

$path    = null;
$target  = '8.5';
$summary = false;
$guarded = false;

foreach ( array_slice( $argv, 1 ) as $argument ) {
	if ( 0 === strpos( $argument, '--target=' ) ) {
		$target = substr( $argument, 9 );
	} elseif ( '--summary' === $argument ) {
		$summary = true;
	} elseif ( '--guarded' === $argument ) {
		$guarded = true;
	} else {
		$path = $argument;
	}
}

if ( null === $path || ! file_exists( $path ) ) {
	fwrite( STDERR, "Usage: php bin/scan.php <path> [--target=8.5] [--summary] [--guarded]\n" );
	exit( 2 );
}

$files = array();

if ( is_dir( $path ) ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
			static function ( $current ) {
				return ! $current->isDir() || ! in_array( $current->getFilename(), array( '.git', 'node_modules' ), true );
			}
		)
	);

	foreach ( $iterator as $file ) {
		if ( 'php' === strtolower( $file->getExtension() ) ) {
			$files[] = $file->getPathname();
		}
	}

	sort( $files );
} else {
	$files[] = $path;
}

$scanner  = new Scanner( Catalog::load( dirname( __DIR__ ) . '/data/catalog' ) );
$started  = microtime( true );
$results  = array();
$declared = array();
$bytes    = 0;

foreach ( $files as $file ) {
	$code   = (string) file_get_contents( $file );
	$bytes += strlen( $code );

	$results[ $file ] = $scanner->scan( $code );

	foreach ( $results[ $file ]->declared_functions as $name ) {
		$declared[ $name ] = true;
	}
}

$counts     = array();
$unreadable = array();
$legacy     = 0;
$hidden     = 0;
$worst      = 0;
$rank       = array(
	Finding::LEVEL_DEPRECATED => 1,
	Finding::LEVEL_CHANGED    => 2,
	Finding::LEVEL_ERROR      => 3,
);

foreach ( $results as $file => $result ) {
	if ( ScanResult::GRAMMAR_NONE === $result->grammar ) {
		$unreadable[ $file ] = $result->parse_error;
		continue;
	}

	if ( ScanResult::GRAMMAR_LEGACY === $result->grammar ) {
		++$legacy;
	}

	foreach ( $result->findings as $finding ) {
		$level = $finding->level_for( $target );

		if ( null === $level ) {
			continue;
		}

		// A function the same code base declares itself is not PHP's own.
		if ( 0 === strpos( $finding->rule, 'function.' ) && isset( $declared[ $finding->subject ] ) ) {
			continue;
		}

		if ( $finding->guarded ) {
			++$hidden;

			if ( ! $guarded ) {
				continue;
			}

			$level = 'guarded';
		} else {
			$worst = max( $worst, $rank[ $level ] );
		}

		$key            = $level . '  ' . $finding->rule . ( '' !== $finding->subject ? '  ' . $finding->subject : '' );
		$counts[ $key ] = isset( $counts[ $key ] ) ? $counts[ $key ] + 1 : 1;

		if ( ! $summary ) {
			printf(
				"%-10s %s:%d  %s%s%s%s\n",
				$level,
				$file,
				$finding->line,
				$finding->rule,
				'' !== $finding->subject ? ' ' . $finding->subject : '',
				$finding->fix ? '  [fix available]' : '',
				$finding->runtime_checked_for( $target ) ? '' : '  [from the upgrade notes, not run on PHP ' . $target . ']'
			);
		}
	}
}

ksort( $counts );

echo "\n";

foreach ( $counts as $key => $count ) {
	printf( "%6d  %s\n", $count, $key );
}

foreach ( $unreadable as $file => $message ) {
	printf( "unreadable  %s: %s\n", $file, $message );
}

printf(
	"\nPHP %s: %d files, %.1f MB, %.1f s. %d findings, %d more behind a check%s. %d files need the PHP %s grammar, %d could not be parsed.\n",
	$target,
	count( $files ),
	$bytes / 1048576,
	microtime( true ) - $started,
	array_sum( $counts ) - ( $guarded ? $hidden : 0 ),
	$hidden,
	$guarded ? ' (listed as guarded)' : '',
	$legacy,
	Scanner::LEGACY_GRAMMAR,
	count( $unreadable )
);

exit( $worst >= 2 ? 1 : 0 );
