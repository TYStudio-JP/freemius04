<?php
/**
 * Runs the tests with plain PHP: php tests/run.php [filter]
 *
 * Every file in tests/Unit/ whose name ends in Test.php returns an array of
 * test name => function. A test fails by throwing.
 *
 * @package UpgradeWarden
 */

require __DIR__ . '/bootstrap.php';

$filter = isset( $argv[1] ) ? (string) $argv[1] : '';
$passed = 0;
$failed = array();
$files  = glob( __DIR__ . '/Unit/*Test.php' );

sort( $files );

foreach ( $files as $file ) {
	$suite = basename( $file, '.php' );

	foreach ( require $file as $name => $test ) {
		$label = $suite . ': ' . $name;

		if ( '' !== $filter && false === stripos( $label, $filter ) ) {
			continue;
		}

		try {
			$test();
			++$passed;
		} catch ( Throwable $error ) {
			$failed[] = $label . "\n    " . get_class( $error ) . ': ' . $error->getMessage()
				. ( $error instanceof Upgw_Test_Failure ? '' : "\n    at " . $error->getFile() . ':' . $error->getLine() );
		}
	}
}

foreach ( $failed as $failure ) {
	echo "FAILED  {$failure}\n\n";
}

printf( "%d passed, %d failed (PHP %s)\n", $passed, count( $failed ), PHP_VERSION );

exit( $failed ? 1 : 0 );
