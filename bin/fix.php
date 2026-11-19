<?php
/**
 * Applies the verified fixes to a file or a folder from the command line.
 * A development aid for trying the fixer on real code.
 *
 *   php bin/fix.php <path> [--write] [--rules=rule.one,rule.two]
 *
 * Without --write nothing is changed: the script only reports what it would do.
 * Run it on a copy or a clean git checkout, so the changes can be reviewed
 * with "git diff".
 *
 * @package UpgradeWarden
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/src/autoload.php';
require dirname( __DIR__ ) . '/premium/autoload.php';

use TYStudio\UpgradeWarden\Engine\Catalog;
use TYStudio\UpgradeWarden\Engine\Scanner;
use TYStudio\UpgradeWarden\Pro\Fix\Fixer;

$path  = null;
$write = false;
$rules = null;

foreach ( array_slice( $argv, 1 ) as $argument ) {
	if ( '--write' === $argument ) {
		$write = true;
	} elseif ( 0 === strpos( $argument, '--rules=' ) ) {
		$rules = explode( ',', substr( $argument, 8 ) );
	} else {
		$path = $argument;
	}
}

if ( null === $path || ! file_exists( $path ) ) {
	fwrite( STDERR, "Usage: php bin/fix.php <path> [--write] [--rules=rule.one,rule.two]\n" );
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

$fixer   = new Fixer( new Scanner( Catalog::load( dirname( __DIR__ ) . '/data/catalog' ) ) );
$counts  = array();
$changed = 0;
$errors  = array();

foreach ( $files as $file ) {
	$code   = (string) file_get_contents( $file );
	$result = $fixer->fix( $code, $rules );

	if ( '' !== $result['error'] ) {
		$errors[ $file ] = $result['error'];
		continue;
	}

	if ( $result['code'] === $code ) {
		continue;
	}

	++$changed;

	foreach ( $result['applied'] as $fix ) {
		$counts[ $fix['rule'] ] = isset( $counts[ $fix['rule'] ] ) ? $counts[ $fix['rule'] ] + 1 : 1;
	}

	if ( $write ) {
		file_put_contents( $file, $result['code'] );
	}
}

ksort( $counts );

foreach ( $counts as $rule => $count ) {
	printf( "%6d  %s\n", $count, $rule );
}

foreach ( $errors as $file => $message ) {
	printf( "skipped  %s: %s\n", $file, $message );
}

printf(
	"\n%d files, %d %s, %d fixes, %d skipped.%s\n",
	count( $files ),
	$changed,
	$write ? 'changed' : 'would change',
	array_sum( $counts ),
	count( $errors ),
	$write ? '' : ' Nothing was written; add --write to apply.'
);
