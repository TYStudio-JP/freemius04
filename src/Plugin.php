<?php
/**
 * Wires the plugin into WordPress.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden;

use TYStudio\UpgradeWarden\Admin\Page;
use TYStudio\UpgradeWarden\Engine\Versions;
use TYStudio\UpgradeWarden\Monitor\Monitor;
use TYStudio\UpgradeWarden\Scan\Job;
use TYStudio\UpgradeWarden\Store\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap.
 */
final class Plugin {

	const CAPABILITY = 'manage_options';
	const SLUG       = 'upgrade-warden';

	/**
	 * Registers the hooks.
	 */
	public static function boot() {
		add_action( 'admin_init', array( Tables::class, 'maybe_upgrade' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_upgw_start', array( __CLASS__, 'ajax_start' ) );
		add_action( 'wp_ajax_upgw_step', array( __CLASS__, 'ajax_step' ) );

		Monitor::boot();

		if ( self::is_pro() ) {
			\TYStudio\UpgradeWarden\Pro\Bootstrap::boot();
		}
	}

	/**
	 * Stops what the plugin scheduled. The data stays until the plugin is deleted.
	 */
	public static function deactivate() {
		foreach ( array( Monitor::CRON, 'upgw_auto_weekly', 'upgw_auto_continue', 'upgw_auto_daily' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Whether Pro features may run.
	 *
	 * @return bool
	 */
	public static function is_pro() {
		return class_exists( '\\TYStudio\\UpgradeWarden\\Pro\\Bootstrap' ) && upgw_fs()->can_use_premium_code();
	}

	/**
	 * The PHP version results are shown for.
	 *
	 * @return string
	 */
	public static function target() {
		$target = (string) get_option( 'upgw_target', '' );

		if ( in_array( $target, Versions::TARGETS, true ) ) {
			return $target;
		}

		// By default, the newest version whose rules were all run on a real runtime.
		return Versions::RUNTIME_CHECKED[ count( Versions::RUNTIME_CHECKED ) - 1 ];
	}

	/**
	 * Adds the screen under Tools.
	 */
	public static function menu() {
		add_management_page(
			__( 'Upgrade Warden', 'upgrade-warden' ),
			__( 'Upgrade Warden', 'upgrade-warden' ),
			self::CAPABILITY,
			self::SLUG,
			array( Page::class, 'render' )
		);
	}

	/**
	 * Loads the screen's script and style.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function assets( $hook ) {
		if ( 'tools_page_' . self::SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'upgw-admin', UPGW_URL . 'assets/admin.css', array(), UPGW_VERSION );
		wp_enqueue_script( 'upgw-admin', UPGW_URL . 'assets/admin.js', array(), UPGW_VERSION, true );
		wp_localize_script(
			'upgw-admin',
			'upgwAdmin',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'upgw_scan' ),
				'text'  => array(
					/* translators: 1: number of plugins and themes finished, 2: total number. */
					'progress' => __( 'Scanning… %1$d of %2$d plugins and themes done.', 'upgrade-warden' ),
					'failed'   => __( 'The scan stopped. Reload the page and start it again; files already scanned are not scanned twice.', 'upgrade-warden' ),
				),
			)
		);
	}

	/**
	 * Starts a scan.
	 */
	public static function ajax_start() {
		self::guard();

		wp_send_json_success( Job::start() );
	}

	/**
	 * Continues a scan for a few seconds.
	 */
	public static function ajax_step() {
		self::guard();

		wp_send_json_success( Job::step() );
	}

	/**
	 * Refuses requests from anyone who may not manage the site.
	 */
	private static function guard() {
		check_ajax_referer( 'upgw_scan', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( null, 403 );
		}
	}
}
