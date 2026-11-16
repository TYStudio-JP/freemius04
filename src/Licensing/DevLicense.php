<?php
/**
 * Stand-in for the Freemius SDK while it is not bundled or not configured.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * Answers the few questions the plugin asks Freemius. In development mode the
 * Pro edition is on when the premium folder is present and UPGW_DEV_PRO is true.
 */
final class DevLicense {

	/**
	 * Plugin directory with a trailing slash.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Constructor.
	 *
	 * @param string $dir Plugin directory with a trailing slash.
	 */
	public function __construct( $dir ) {
		$this->dir = $dir;
	}

	/**
	 * Path of the Freemius SDK start file, or an empty string.
	 *
	 * @param string $dir Plugin directory with a trailing slash.
	 * @return string
	 */
	public static function locate_sdk( $dir ) {
		foreach ( array( 'vendor/freemius/wordpress-sdk/start.php', 'vendor/freemius/start.php', 'freemius/start.php' ) as $candidate ) {
			if ( is_readable( $dir . $candidate ) ) {
				return $dir . $candidate;
			}
		}

		return '';
	}

	/**
	 * Whether Pro features may run.
	 *
	 * @return bool
	 */
	public function can_use_premium_code() {
		return defined( 'UPGW_DEV_PRO' ) && true === UPGW_DEV_PRO && is_dir( $this->dir . 'premium' );
	}

	/**
	 * Same question under the name Freemius strips from the free edition.
	 *
	 * @return bool
	 */
	public function can_use_premium_code__premium_only() {
		return $this->can_use_premium_code();
	}

	/**
	 * Whether this is the Pro build.
	 *
	 * @return bool
	 */
	public function is__premium_only() {
		return is_dir( $this->dir . 'premium' );
	}

	/**
	 * Upgrade page address. There is none in development mode.
	 *
	 * @return string
	 */
	public function get_upgrade_url() {
		return '';
	}

	/**
	 * Freemius lets a second copy of the plugin take over; nothing to do here.
	 *
	 * @param bool   $is_premium Whether the other copy is the Pro edition.
	 * @param string $file       Main file of the other copy.
	 */
	public function set_basename( $is_premium, $file ) {
	}

	/**
	 * Freemius registers an uninstall callback; WordPress's own hook is used instead.
	 *
	 * @param string   $tag      Hook name.
	 * @param callable $callback Callback.
	 */
	public function add_action( $tag, $callback ) {
	}
}
