<?php
/**
 * Plugin Name:       Upgrade Warden – PHP 8 Compatibility Scanner
 * Description:       Finds what a newer PHP version will reject, change or warn about in your plugins and themes, line by line, for PHP 8.0 to 8.6, and records what PHP says while the site runs. Every rule was checked on real PHP versions. The scan runs on your own site.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            TYStudio
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       upgrade-warden
 *
 * @fs_premium_only /premium/, /languages/
 *
 * @package UpgradeWarden
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'upgw_fs' ) ) {
	// The other edition (free or Pro) is already loaded: let Freemius switch to this one.
	upgw_fs()->set_basename( true, __FILE__ );
} else {
	define( 'UPGW_VERSION', '1.0.0' );
	define( 'UPGW_FILE', __FILE__ );
	define( 'UPGW_DIR', plugin_dir_path( __FILE__ ) );
	define( 'UPGW_URL', plugin_dir_url( __FILE__ ) );

	/*
	 * Freemius product credentials. Copy the three values from the Freemius
	 * developer dashboard: the product ID and the public key (Product > Settings
	 * > Keys; never the secret key) and the "wp_org_gatekeeper" value of the SDK
	 * integration snippet. While the first two are empty the plugin runs in
	 * development mode, without licensing and without updates.
	 */
	if ( ! defined( 'UPGW_FS_PRODUCT_ID' ) ) {
		define( 'UPGW_FS_PRODUCT_ID', '' );
	}
	if ( ! defined( 'UPGW_FS_PUBLIC_KEY' ) ) {
		define( 'UPGW_FS_PUBLIC_KEY', '' );
	}
	if ( ! defined( 'UPGW_FS_GATEKEEPER' ) ) {
		define( 'UPGW_FS_GATEKEEPER', '' );
	}

	require_once UPGW_DIR . 'src/autoload.php';

	// DO NOT REMOVE THIS IF: the function_exists() call above depends on it.
	if ( ! function_exists( 'upgw_fs' ) ) {
		/**
		 * Freemius SDK accessor.
		 *
		 * Returns the Freemius instance, or a stand-in with the same methods when the
		 * SDK or the product credentials are missing (development mode).
		 *
		 * @return Freemius|\TYStudio\UpgradeWarden\Licensing\DevLicense
		 */
		function upgw_fs() {
			global $upgw_fs;

			if ( ! isset( $upgw_fs ) ) {
				$sdk = \TYStudio\UpgradeWarden\Licensing\DevLicense::locate_sdk( UPGW_DIR );

				if ( '' !== $sdk && '' !== UPGW_FS_PRODUCT_ID && '' !== UPGW_FS_PUBLIC_KEY ) {
					require_once $sdk;

					$upgw_fs = fs_dynamic_init(
						array(
							'id'                  => UPGW_FS_PRODUCT_ID,
							'slug'                => 'upgrade-warden',
							// The folder name of the ZIP Freemius hands to buyers.
							'premium_slug'        => 'upgrade-warden-premium',
							'type'                => 'plugin',
							'public_key'          => UPGW_FS_PUBLIC_KEY,
							'is_premium'          => true,
							'premium_suffix'      => 'Pro',
							'has_premium_version' => true,
							'has_addons'          => false,
							'has_paid_plans'      => true,
							// The two settings Freemius asks for in editions hosted on WordPress.org.
							'is_org_compliant'    => true,
							'wp_org_gatekeeper'   => UPGW_FS_GATEKEEPER,
							'trial'               => array(
								'days'               => 14,
								'is_require_payment' => false,
							),
							'menu'                => array(
								'slug'    => 'upgrade-warden',
								'contact' => false,
								'support' => false,
								'parent'  => array(
									'slug' => 'tools.php',
								),
							),
							'is_live'             => true,
						)
					);
				} else {
					$upgw_fs = new \TYStudio\UpgradeWarden\Licensing\DevLicense( UPGW_DIR );
				}
			}

			return $upgw_fs;
		}

		upgw_fs();
		do_action( 'upgw_fs_loaded' );
	}

	if ( upgw_fs()->is__premium_only() ) {
		// The classes of the Pro edition.
		require_once UPGW_DIR . 'premium/autoload.php';
	}

	register_activation_hook( __FILE__, array( \TYStudio\UpgradeWarden\Store\Tables::class, 'install' ) );
	register_deactivation_hook( __FILE__, array( \TYStudio\UpgradeWarden\Plugin::class, 'deactivate' ) );

	\TYStudio\UpgradeWarden\Plugin::boot();
}
