<?php
/**
 * Prints what this PHP runtime says about itself: every internal function,
 * class, method, constant and ini directive, with its extension and whether
 * the runtime marks it as deprecated.
 *
 * Written in the oldest syntax still common (no closures, no short arrays) so
 * the same file runs on every runtime, including very old ones.
 */

error_reporting( -1 );
ini_set( 'display_errors', '0' );

$GLOBALS['__upgw_deprecation'] = false;

function __upgw_note_deprecation( $type, $message ) {
	if ( 8192 === $type || 16384 === $type ) {
		$GLOBALS['__upgw_deprecation'] = true;
	}

	return true;
}

set_error_handler( '__upgw_note_deprecation' );

$dump = array(
	'version'    => PHP_VERSION,
	'extensions' => array(),
	'functions'  => array(),
	'classes'    => array(),
	'constants'  => array(),
	'ini'        => array(),
);

foreach ( get_loaded_extensions() as $extension ) {
	$dump['extensions'][] = strtolower( $extension );
}

$defined = get_defined_functions();

foreach ( $defined['internal'] as $name ) {
	$reflection = new ReflectionFunction( $name );

	$dump['functions'][ strtolower( $name ) ] = array(
		'ext'        => strtolower( (string) $reflection->getExtensionName() ),
		'deprecated' => $reflection->isDeprecated(),
	);
}

$types = array_merge( get_declared_classes(), get_declared_interfaces() );

if ( function_exists( 'get_declared_traits' ) ) {
	$types = array_merge( $types, get_declared_traits() );
}

foreach ( $types as $name ) {
	$reflection = new ReflectionClass( $name );

	if ( ! $reflection->isInternal() ) {
		continue;
	}

	$methods = array();

	foreach ( $reflection->getMethods() as $method ) {
		if ( strtolower( $method->getDeclaringClass()->getName() ) !== strtolower( $name ) ) {
			continue;
		}

		$methods[ strtolower( $method->getName() ) ] = $method->isDeprecated();
	}

	$dump['classes'][ strtolower( $name ) ] = array(
		'name'    => $name,
		'ext'     => strtolower( (string) $reflection->getExtensionName() ),
		'methods' => $methods,
	);
}

foreach ( get_defined_constants( true ) as $group => $constants ) {
	if ( 'user' === $group ) {
		continue;
	}

	foreach ( $constants as $name => $value ) {
		$GLOBALS['__upgw_deprecation'] = false;

		// Reading a deprecated constant is what raises the notice.
		constant( $name );

		$dump['constants'][ $name ] = array(
			'ext'        => strtolower( $group ),
			'deprecated' => $GLOBALS['__upgw_deprecation'],
		);
	}
}

foreach ( ini_get_all() as $name => $unused ) {
	$dump['ini'][] = $name;
}

echo '@@UPGW-DUMP@@', json_encode( $dump );
