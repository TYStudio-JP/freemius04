<?php
/**
 * Test bootstrap: loads the engine without WordPress.
 *
 * @package UpgradeWarden
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'UPGW_ROOT', dirname( __DIR__ ) );

require UPGW_ROOT . '/src/autoload.php';

use TYStudio\UpgradeWarden\Engine\Catalog;
use TYStudio\UpgradeWarden\Engine\Scanner;
use TYStudio\UpgradeWarden\Pro\Fix\Fixer;

/**
 * Thrown by a failed assertion.
 */
final class Upgw_Test_Failure extends Exception {
}

/**
 * Fails unless both values are identical.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  What was being checked.
 * @throws Upgw_Test_Failure When the values differ.
 */
function upgw_same( $expected, $actual, $message = '' ) {
	if ( $expected !== $actual ) {
		throw new Upgw_Test_Failure( $message . "\n      expected: " . var_export( $expected, true ) . "\n      actual:   " . var_export( $actual, true ) );
	}
}

/**
 * Fails unless the condition is true.
 *
 * @param bool   $condition Condition.
 * @param string $message   What was being checked.
 * @throws Upgw_Test_Failure When the condition is not true.
 */
function upgw_true( $condition, $message = '' ) {
	if ( true !== $condition ) {
		throw new Upgw_Test_Failure( $message );
	}
}

/**
 * The scanner with the shipped catalog.
 *
 * @return Scanner
 */
function upgw_scanner() {
	static $scanner = null;

	if ( null === $scanner ) {
		$scanner = new Scanner( Catalog::load( UPGW_ROOT . '/data/catalog' ) );
	}

	return $scanner;
}

/**
 * The fixer.
 *
 * @return Fixer
 */
function upgw_fixer() {
	return new Fixer( upgw_scanner() );
}

/**
 * Rule ids found in the code, in order.
 *
 * @param string $code PHP source.
 * @return string[]
 */
function upgw_rules_in( $code ) {
	$rules = array();

	foreach ( upgw_scanner()->scan( $code )->findings as $finding ) {
		$rules[] = $finding->rule;
	}

	return $rules;
}

/**
 * Every fixture in tools/fixtures/, parsed the way tools/verify-fixtures.mjs does.
 *
 * @return array<string,array{rule:string,claim:array,code:string,fixed:?string}>
 */
function upgw_fixtures() {
	$fixtures = array();

	foreach ( glob( UPGW_ROOT . '/tools/fixtures/*.fixture' ) as $path ) {
		$sections = array();
		$current  = null;

		foreach ( explode( "\n", str_replace( "\r\n", "\n", file_get_contents( $path ) ) ) as $line ) {
			if ( preg_match( '/^--([A-Z]+)--$/', $line, $match ) ) {
				$current              = $match[1];
				$sections[ $current ] = array();
			} elseif ( null !== $current ) {
				$sections[ $current ][] = $line;
			}
		}

		$get = static function ( $name ) use ( $sections ) {
			return isset( $sections[ $name ] ) ? trim( implode( "\n", $sections[ $name ] ) ) : null;
		};

		$fixtures[ basename( $path ) ] = array(
			'rule'  => $get( 'RULE' ),
			'claim' => json_decode( $get( 'CLAIM' ), true ),
			'code'  => $get( 'CODE' ) . "\n",
			'fixed' => null === $get( 'FIXED' ) ? null : $get( 'FIXED' ) . "\n",
			'scan'  => null === $get( 'SCAN' ) ? array() : json_decode( $get( 'SCAN' ), true ),
		);
	}

	return $fixtures;
}

/**
 * What the real PHP runtimes did with each fixture, as recorded by
 * tools/verify-fixtures.mjs.
 *
 * @return array
 */
function upgw_observed() {
	return json_decode( file_get_contents( UPGW_ROOT . '/tools/fixtures/observed.json' ), true );
}
