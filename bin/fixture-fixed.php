<?php
/**
 * Development aid: prints what the fixer makes of a fixture's CODE section.
 *
 *   php bin/fixture-fixed.php tools/fixtures/some.fixture
 *
 * Use it to write the FIXED section of a new fixture. The text only becomes
 * evidence once tools/verify-fixtures.mjs has run it on the real runtimes and
 * found that it prints what the original printed.
 *
 * @package UpgradeWarden
 */

if ( 'cli' !== PHP_SAPI || $argc < 2 ) {
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/src/autoload.php';

use TYStudio\UpgradeWarden\Engine\Catalog;
use TYStudio\UpgradeWarden\Engine\Scanner;
use TYStudio\UpgradeWarden\Pro\Fix\Fixer;

if ( ! preg_match( '/^--CODE--\n(.*?)(?=^--[A-Z]+--$|\z)/ms', file_get_contents( $argv[1] ), $match ) ) {
	fwrite( STDERR, "No CODE section.\n" );
	exit( 1 );
}

$fixer  = new Fixer( new Scanner( Catalog::load( dirname( __DIR__ ) . '/data/catalog' ) ) );
$result = $fixer->fix( trim( $match[1] ) . "\n" );

if ( '' !== $result['error'] ) {
	fwrite( STDERR, $result['error'] . "\n" );
	exit( 1 );
}

echo $result['code'];
