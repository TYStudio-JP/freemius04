<?php
/**
 * Switches the run-time log on and off and keeps it small.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Monitor;

use TYStudio\UpgradeWarden\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Recording is off until the site owner switches it on.
 */
final class Monitor {

	const OPTION = 'upgw_monitor';
	const CRON   = 'upgw_monitor_prune';

	/**
	 * Registers the hooks and, when recording is on, starts listening at once:
	 * the earlier in the request, the more of the other plugins is heard.
	 */
	public static function boot() {
		add_action( 'admin_post_upgw_monitor', array( __CLASS__, 'handle' ) );
		add_action( self::CRON, array( Log::class, 'prune' ) );

		if ( self::enabled() ) {
			Capture::install( new Signatures( UPGW_DIR . 'data/catalog' ), array( Log::class, 'record' ) );

			add_filter( 'pre_update_option_active_plugins', array( __CLASS__, 'load_first' ) );
		}
	}

	/**
	 * Whether recording is on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return '1' === get_option( self::OPTION, '0' );
	}

	/**
	 * Puts this plugin first in the list of active plugins, so that it hears
	 * what the others say while they load. Only while recording is on.
	 *
	 * @param mixed $plugins Value about to be saved.
	 * @return mixed
	 */
	public static function load_first( $plugins ) {
		if ( ! is_array( $plugins ) ) {
			return $plugins;
		}

		$own = plugin_basename( UPGW_FILE );
		$at  = array_search( $own, $plugins, true );

		if ( false === $at || 0 === $at ) {
			return $plugins;
		}

		unset( $plugins[ $at ] );
		array_unshift( $plugins, $own );

		return array_values( $plugins );
	}

	/**
	 * Switches recording on or off.
	 *
	 * @param bool $on New state.
	 */
	public static function set( $on ) {
		update_option( self::OPTION, $on ? '1' : '0' );

		if ( $on ) {
			if ( ! wp_next_scheduled( self::CRON ) ) {
				wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON );
			}

			$active = get_option( 'active_plugins' );
			$first  = self::load_first( $active );

			if ( $first !== $active ) {
				update_option( 'active_plugins', $first );
			}
		} else {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	/**
	 * Handles the buttons of the log screen, then returns to it.
	 */
	public static function handle() {
		check_admin_referer( 'upgw_monitor' );

		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'upgrade-warden' ), 403 );
		}

		$do = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';

		if ( 'on' === $do || 'off' === $do ) {
			self::set( 'on' === $do );
		} elseif ( 'clear' === $do ) {
			Log::clear();
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => Plugin::SLUG,
					'view' => 'log',
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}
}
