<?php
/**
 * Runs one fixture inside a real PHP runtime and reports what happened.
 *
 * The fixture is included, never pasted into this file, so that a parse error
 * in the fixture cannot stop the report from being written. The report is one
 * JSON document after a marker line, which the Node side picks out of stdout.
 *
 * This file has to run unchanged on every PHP version under test (7.4 to 8.5),
 * so it avoids anything deprecated or added along the way.
 */

error_reporting( -1 );
ini_set( 'display_errors', '0' );
ini_set( 'log_errors', '0' );

$GLOBALS['__upgw_report'] = array(
	'version'     => PHP_VERSION,
	'diagnostics' => array(),
	'thrown'      => null,
	'fatal'       => null,
	'output'      => '',
);

function __upgw_error_name( $type ) {
	// Numeric keys on purpose: the E_STRICT constant is itself deprecated since 8.4.
	$names = array(
		1     => 'E_ERROR',
		2     => 'E_WARNING',
		4     => 'E_PARSE',
		8     => 'E_NOTICE',
		16    => 'E_CORE_ERROR',
		32    => 'E_CORE_WARNING',
		64    => 'E_COMPILE_ERROR',
		128   => 'E_COMPILE_WARNING',
		256   => 'E_USER_ERROR',
		512   => 'E_USER_WARNING',
		1024  => 'E_USER_NOTICE',
		2048  => 'E_STRICT',
		4096  => 'E_RECOVERABLE_ERROR',
		8192  => 'E_DEPRECATED',
		16384 => 'E_USER_DEPRECATED',
	);

	return isset( $names[ $type ] ) ? $names[ $type ] : 'E_' . $type;
}

function __upgw_on_error( $type, $message, $file, $line ) {
	$GLOBALS['__upgw_report']['diagnostics'][] = array(
		'type'    => __upgw_error_name( $type ),
		'message' => $message,
		'line'    => $line,
	);

	return true;
}

function __upgw_on_shutdown() {
	$last = error_get_last();

	if ( is_array( $last ) && in_array( $last['type'], array( 1, 4, 16, 64, 256, 4096 ), true ) ) {
		$GLOBALS['__upgw_report']['fatal'] = array(
			'type'    => __upgw_error_name( $last['type'] ),
			'message' => $last['message'],
			'line'    => $last['line'],
		);
	}

	$output = '';

	while ( ob_get_level() > 0 ) {
		$output = ob_get_clean() . $output;
	}

	$GLOBALS['__upgw_report']['output'] = $output;

	echo "\n@@UPGW-REPORT@@", json_encode( $GLOBALS['__upgw_report'], JSON_PARTIAL_OUTPUT_ON_ERROR );
}

set_error_handler( '__upgw_on_error' );
register_shutdown_function( '__upgw_on_shutdown' );
ob_start();

try {
	include $GLOBALS['__upgw_fixture'];
} catch ( \Throwable $__upgw_caught ) {
	$GLOBALS['__upgw_report']['thrown'] = array(
		'class'   => get_class( $__upgw_caught ),
		'message' => $__upgw_caught->getMessage(),
		'line'    => $__upgw_caught->getLine(),
	);
}
