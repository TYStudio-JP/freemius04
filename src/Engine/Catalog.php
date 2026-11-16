<?php
/**
 * The generated catalog of internal functions and constants.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Reads data/catalog/*.php, which tools/build-catalog.mjs generates from real
 * PHP runtimes and the official upgrade notes.
 */
final class Catalog {

	/**
	 * Functions by lower-case name.
	 *
	 * @var array<string,array>
	 */
	private $functions;

	/**
	 * Constants by name.
	 *
	 * @var array<string,array>
	 */
	private $constants;

	/**
	 * Constructor.
	 *
	 * @param array<string,array> $functions Functions by lower-case name.
	 * @param array<string,array> $constants Constants by name.
	 */
	public function __construct( array $functions, array $constants ) {
		$this->functions = $functions;
		$this->constants = $constants;
	}

	/**
	 * Loads the catalog shipped with the plugin.
	 *
	 * @param string $dir Directory with functions.php and constants.php.
	 * @return self
	 */
	public static function load( $dir ) {
		return new self( require $dir . '/functions.php', require $dir . '/constants.php' );
	}

	/**
	 * Looks up an internal function.
	 *
	 * @param string $name Function name in any case, without a leading backslash.
	 * @return array|null Entry with deprecated, removed, ext, evidence and optionally instead.
	 */
	public function get_function( $name ) {
		$name = strtolower( $name );

		return isset( $this->functions[ $name ] ) ? $this->functions[ $name ] : null;
	}

	/**
	 * Looks up an internal constant. Constant names are case-sensitive.
	 *
	 * @param string $name Constant name.
	 * @return array|null
	 */
	public function get_constant( $name ) {
		return isset( $this->constants[ $name ] ) ? $this->constants[ $name ] : null;
	}

	/**
	 * Number of entries, for display.
	 *
	 * @return array{functions:int,constants:int}
	 */
	public function size() {
		return array(
			'functions' => count( $this->functions ),
			'constants' => count( $this->constants ),
		);
	}
}
