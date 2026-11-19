<?php
/**
 * The run-time monitor: which messages it keeps and how it explains them.
 *
 * What PHP really says on each version is checked by tools/verify-monitor.mjs
 * on the real runtimes. These tests cover the parts that do not depend on the
 * PHP version they run on, and tie the two hand-written patterns to fixtures.
 *
 * @package UpgradeWarden
 */

use TYStudio\UpgradeWarden\Monitor\Capture;
use TYStudio\UpgradeWarden\Monitor\Signatures;

$tests      = array();
$signatures = new Signatures( UPGW_ROOT . '/data/catalog' );

$tests['the list of known messages is there'] = static function () use ( $signatures ) {
	upgw_true( $signatures->count() > 400, 'data/catalog/messages.php holds ' . $signatures->count() . ' messages. Run: php tools/build-messages.php' );
};

$tests['numbers, names and quoted values do not make a different message'] = static function () {
	upgw_same(
		Signatures::normalize( 'TypeError', 'count(): Argument #1 ($value) must be of type Countable|array, null given' ),
		Signatures::normalize( 'TypeError', 'count(): Argument #1 ($value) must be of type Countable|array, string given' )
	);
	upgw_same(
		Signatures::normalize( 'E_WARNING', "Illegal string offset 'first'" ),
		Signatures::normalize( 'E_WARNING', "Illegal string offset 'name'" )
	);
	upgw_same(
		Signatures::normalize( 'E_WARNING', "Use of undefined constant MY_SLUG - assumed 'MY_SLUG' (this will throw an Error in a future version of PHP)" ),
		Signatures::normalize( 'E_WARNING', "Use of undefined constant OTHER - assumed 'OTHER' (this will throw an Error in a future version of PHP)" )
	);
	upgw_true( Signatures::normalize( 'E_WARNING', 'count(): x' ) !== Signatures::normalize( 'E_NOTICE', 'count(): x' ), 'The level is part of the message.' );
	upgw_true( Signatures::normalize( 'E_WARNING', 'max(): Array must contain at least one element' ) !== Signatures::normalize( 'E_WARNING', 'min(): Array must contain at least one element' ), 'The function name is kept.' );
};

$tests['count( null ) on PHP 7 is explained as breaking in 8.0'] = static function () use ( $signatures ) {
	$match = $signatures->match( 'E_WARNING', 'count(): Parameter must be an array or an object that implements Countable' );

	upgw_same( '8.0', $match['breaks'] );
	upgw_same( 'verified', $match['evidence'] );
	upgw_same( 'runtime.count_non_countable', $match['rule'] );
};

$tests['the error PHP 8 raises for count( null ) is explained as well'] = static function () use ( $signatures ) {
	$match = $signatures->match( 'TypeError', 'count(): Argument #1 ($value) must be of type Countable|array, null given' );

	upgw_same( '8.0', $match['breaks'] );
	upgw_same( 'verified', $match['evidence'] );
};

$tests['a deprecation on record carries its versions'] = static function () use ( $signatures ) {
	$match = $signatures->match( 'E_DEPRECATED', 'implode(): Passing glue string after array is deprecated. Swap the parameters' );

	upgw_same( '7.4', $match['deprecated'] );
	upgw_same( '8.0', $match['breaks'] );
};

$tests['a message that is not on record is not explained'] = static function () use ( $signatures ) {
	upgw_same( null, $signatures->match( 'E_WARNING', 'Undefined variable $missing' ) );
	upgw_same( null, $signatures->match( 'E_WARNING', 'file_get_contents(/nowhere): Failed to open stream: No such file or directory' ) );
	upgw_same( null, $signatures->match( 'E_NOTICE', 'Array to string conversion' ) );
	upgw_same( null, $signatures->match( 'E_DEPRECATED', 'Something PHP has never said' ) );
};

$tests['a wrong argument warning for a function without a fixture is only "likely"'] = static function () use ( $signatures ) {
	$match = $signatures->match( 'E_WARNING', 'substr_count() expects parameter 1 to be string, array given' );

	upgw_same( 'likely', $match['evidence'] );
	upgw_same( '8.0', $match['breaks'] );

	$match = $signatures->match( 'E_WARNING', 'str_pad() expects at least 2 parameters, 1 given' );

	upgw_same( 'likely', $match['evidence'] );

	// The same words in a notice, or raised by code of the site, are not that warning.
	upgw_same( null, $signatures->match( 'E_NOTICE', 'substr_count() expects parameter 1 to be string, array given' ) );
	upgw_same( null, $signatures->match( 'E_WARNING', 'My plugin expects parameter 1 to be set' ) );
};

$tests['the "likely" pattern is what the fixtures showed: the warning on 7.4, an error from 8.0'] = static function () {
	$observed = upgw_observed()['fixtures'];
	$shown    = 0;

	foreach ( $observed as $name => $record ) {
		if ( ! isset( $record['messages']['7.4'] ) || 0 !== strpos( $record['messages']['7.4'], 'E_WARNING: ' ) || 0 !== strpos( $record['rule'], 'runtime.internal_argument_' ) ) {
			continue;
		}

		if ( 1 === preg_match( Signatures::LIKELY_ARGUMENT_ERROR, substr( $record['messages']['7.4'], 11 ) ) ) {
			++$shown;
			upgw_same( array( 'breaks_in' => '8.0' ), $record['claim'], $name );
			upgw_same( 'fatal', $record['observed']['8.0'], $name );
		}
	}

	upgw_true( $shown >= 8, "Only {$shown} fixtures show the pattern." );
};

$tests['an override that does not fit: only a class written in PHP is explained'] = static function () use ( $signatures ) {
	if ( ! class_exists( 'Upgw_Test_Base_Field', false ) ) {
		eval( 'class Upgw_Test_Base_Field { public function render( $value ) {} }' ); // phpcs:ignore
	}

	$match = $signatures->match( 'E_WARNING', 'Declaration of Text_Field::render($value, $attributes) should be compatible with Upgw_Test_Base_Field::render($value)' );

	upgw_same( '8.0', $match['breaks'] );
	upgw_same( 'class.incompatible_method_signature', $match['rule'] );

	// A class of PHP itself: what 8.0 does depends on how that class changed.
	upgw_same( null, $signatures->match( 'E_WARNING', 'Declaration of My_List::count($mode) should be compatible with ArrayObject::count()' ) );
	// A class that is not loaded cannot be looked at.
	upgw_same( null, $signatures->match( 'E_WARNING', 'Declaration of A::b() should be compatible with Upgw_Class_Nobody_Declared::b($x)' ) );
};

$tests['the override pattern is what the fixtures showed'] = static function () {
	$shown = 0;

	foreach ( upgw_observed()['fixtures'] as $name => $record ) {
		if ( 0 !== strpos( $name, 'class.incompatible_method_signature~' ) || ! isset( $record['messages']['7.4'] ) ) {
			continue;
		}

		++$shown;
		upgw_same( 1, preg_match( Signatures::INCOMPATIBLE_OVERRIDE, substr( $record['messages']['7.4'], 11 ) ), $name );
		upgw_same( '8.0', $record['claim']['breaks_in'], $name );
	}

	upgw_true( $shown >= 4, "Only {$shown} fixtures." );
};

$tests['an uncaught error of PHP is kept, with its message and without the path'] = static function () {
	$fatal = Capture::fatal(
		array(
			'type'    => 1,
			'message' => "Uncaught TypeError: count(): Argument #1 (\$value) must be of type Countable|array, null given in /var/www/site/wp-content/plugins/old/old.php:12\nStack trace:\n#0 /var/www/site/wp-content/plugins/old/old.php(12): count(NULL)\n#1 {main}\n  thrown",
			'file'    => '/var/www/site/wp-content/plugins/old/old.php',
			'line'    => 12,
		)
	);

	upgw_same( 'TypeError', $fatal['type'] );
	upgw_same( 'count(): Argument #1 ($value) must be of type Countable|array, null given', $fatal['message'] );
};

$tests['a message that itself says "in" is cut at the right place'] = static function () {
	$fatal = Capture::fatal(
		array(
			'type'    => 1,
			'message' => "Uncaught TypeError: show_items(): Argument #1 (\$limit) must be of type int, null given, called in /srv/a.php on line 8 and defined in /srv/a.php:3\nStack trace:\n#0 {main}\n  thrown",
		)
	);

	upgw_same( 'show_items(): Argument #1 ($limit) must be of type int, null given, called in /srv/a.php on line 8 and defined', $fatal['message'] );
	upgw_same(
		Signatures::normalize( 'TypeError', 'show_items(): Argument #1 ($limit) must be of type int, null given, called in /other/b.php on line 20' ),
		Signatures::normalize( 'TypeError', $fatal['message'] ),
		'With or without the place of the call, it is the same message.'
	);
};

$tests['a compile error is kept'] = static function () {
	$fatal = Capture::fatal(
		array(
			'type'    => 64,
			'message' => 'Array and string offset access syntax with curly braces is no longer supported',
		)
	);

	upgw_same( 'E_COMPILE_ERROR', $fatal['type'] );
};

$tests['an exception of the site, memory and time limits, and warnings are not fatal errors of interest'] = static function () {
	upgw_same( null, Capture::fatal( array( 'type' => 1, 'message' => "Uncaught Exception: Could not charge customer 4711 in /srv/shop.php:9\nStack trace:\n#0 {main}\n  thrown" ) ) );
	upgw_same( null, Capture::fatal( array( 'type' => 1, 'message' => "Uncaught RuntimeException: no in /srv/shop.php:9\nStack trace:\n#0 {main}\n  thrown" ) ) );
	upgw_same( null, Capture::fatal( array( 'type' => 1, 'message' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)' ) ) );
	upgw_same( null, Capture::fatal( array( 'type' => 1, 'message' => 'Maximum execution time of 30 seconds exceeded' ) ) );
	upgw_same( null, Capture::fatal( array( 'type' => 2, 'message' => 'Division by zero' ) ) );
	upgw_same( null, Capture::fatal( array( 'type' => 8192, 'message' => 'Something is deprecated' ) ) );
};

$tests['the handler keeps deprecations and known warnings, once, and passes everything on'] = static function () use ( $signatures ) {
	$passed = array();

	set_error_handler(
		static function ( $type, $message ) use ( &$passed ) {
			$passed[] = $message;

			return true;
		}
	);

	$sunk = array();

	Capture::install(
		$signatures,
		static function ( $events ) use ( &$sunk ) {
			$sunk = $events;
		}
	);
	Capture::reset();

	Capture::on_error( 8192, 'A deprecation nobody has on record', '/srv/a.php', 3 );
	Capture::on_error( 8192, 'A deprecation nobody has on record', '/srv/a.php', 3 );
	Capture::on_error( 2, 'count(): Parameter must be an array or an object that implements Countable', '/srv/a.php', 9 );
	Capture::on_error( 2, 'fopen(/nowhere): failed to open stream: No such file or directory', '/srv/a.php', 10 );
	Capture::on_error( 8, 'Undefined index: page', '/srv/a.php', 11 );
	Capture::on_error( 16384, 'A deprecation raised by a plugin, not by PHP', '/srv/a.php', 12 );

	$events = Capture::events();

	upgw_same( 2, count( $events ), 'One deprecation and one known warning.' );
	upgw_same( 'E_DEPRECATED', $events[0]['type'] );
	upgw_same( null, $events[0]['match'] );
	upgw_same( 'E_WARNING', $events[1]['type'] );
	upgw_same( '8.0', $events[1]['match']['breaks'] );
	upgw_same( 6, count( $passed ), 'Every message reached the handler that was there before.' );

	// The end of the request hands the events over and starts afresh.
	Capture::on_shutdown();
	upgw_same( 2, count( $sunk ) );
	upgw_same( array(), Capture::events() );

	// A page that raises thousands of notices is cut off.
	for ( $i = 0; $i < 200; $i++ ) {
		Capture::on_error( 8192, 'Deprecation number ' . $i, '/srv/a.php', $i );
	}

	upgw_same( Capture::MAX_EVENTS, count( Capture::events() ) );
	Capture::reset();
};

return $tests;
