<?php
/**
 * PHP version numbers as the engine compares them.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Version helpers.
 */
final class Versions {

	/**
	 * Versions a site can be checked against, oldest first.
	 */
	const TARGETS = array( '8.0', '8.1', '8.2', '8.3', '8.4', '8.5', '8.6' );

	/**
	 * Versions whose behaviour was checked on a real runtime. Claims about any
	 * other version come from the official upgrade notes only.
	 */
	const RUNTIME_CHECKED = array( '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5' );

	/**
	 * Turns "8.4" into 804, so versions compare as numbers.
	 *
	 * @param string $version Major and minor version.
	 * @return int
	 */
	public static function number( $version ) {
		$parts = explode( '.', (string) $version );

		return (int) $parts[0] * 100 + ( isset( $parts[1] ) ? (int) $parts[1] : 0 );
	}

	/**
	 * Whether $version is the same as or newer than $since.
	 *
	 * @param string $version Version to test.
	 * @param string $since   Version to compare with.
	 * @return bool
	 */
	public static function at_least( $version, $since ) {
		return self::number( $version ) >= self::number( $since );
	}

	/**
	 * Whether a claim about this version was checked on a real runtime.
	 *
	 * @param string $version Major and minor version.
	 * @return bool
	 */
	public static function runtime_checked( $version ) {
		return in_array( (string) $version, self::RUNTIME_CHECKED, true );
	}
}
