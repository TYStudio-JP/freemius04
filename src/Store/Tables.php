<?php
/**
 * The plugin's database tables.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Three tables: one row per scanned file, one row per finding, and one row
 * per message PHP raised while the site ran (the run-time log).
 *
 * A finding is stored without a level. The level depends on the PHP version
 * the site moves to and is worked out when the result is shown, so choosing
 * another version never needs another scan.
 */
final class Tables {

	const SCHEMA = 2;

	/**
	 * Every scheduled event of the plugin, free and Pro. Cleared on
	 * deactivation and removal.
	 */
	const SCHEDULED = array( 'upgw_monitor_prune', 'upgw_auto_weekly', 'upgw_auto_after_update', 'upgw_auto_continue', 'upgw_auto_daily' );

	/**
	 * Table of scanned files.
	 *
	 * @return string
	 */
	public static function files() {
		global $wpdb;

		return $wpdb->prefix . 'upgw_files';
	}

	/**
	 * Table of findings.
	 *
	 * @return string
	 */
	public static function findings() {
		global $wpdb;

		return $wpdb->prefix . 'upgw_findings';
	}

	/**
	 * Table of the run-time log.
	 *
	 * @return string
	 */
	public static function events() {
		global $wpdb;

		return $wpdb->prefix . 'upgw_events';
	}

	/**
	 * Creates or updates the tables.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$files    = self::files();
		$findings = self::findings();
		$events   = self::events();

		dbDelta(
			"CREATE TABLE {$files} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				component varchar(191) NOT NULL,
				path varchar(1000) NOT NULL,
				path_hash char(32) NOT NULL,
				content_hash char(32) NOT NULL,
				catalog varchar(20) NOT NULL,
				grammar varchar(10) NOT NULL,
				parse_error text NOT NULL,
				declared longtext NOT NULL,
				scanned_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY component_path (component,path_hash),
				KEY component (component)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$findings} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				file_id bigint(20) unsigned NOT NULL,
				rule varchar(100) NOT NULL,
				line int(10) unsigned NOT NULL,
				start_pos int(10) unsigned NOT NULL,
				end_pos int(10) unsigned NOT NULL,
				subject varchar(191) NOT NULL,
				deprecated varchar(5) DEFAULT NULL,
				changed varchar(5) DEFAULT NULL,
				breaks varchar(5) DEFAULT NULL,
				evidence varchar(12) NOT NULL,
				guarded tinyint(1) NOT NULL,
				fixable tinyint(1) NOT NULL,
				instead varchar(191) NOT NULL,
				PRIMARY KEY  (id),
				KEY file_id (file_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				fingerprint char(32) NOT NULL,
				type varchar(60) NOT NULL,
				message text NOT NULL,
				file varchar(1000) NOT NULL,
				line int(10) unsigned NOT NULL,
				component varchar(191) NOT NULL,
				php varchar(8) NOT NULL,
				rule varchar(100) NOT NULL,
				deprecated varchar(5) DEFAULT NULL,
				changed varchar(5) DEFAULT NULL,
				breaks varchar(5) DEFAULT NULL,
				evidence varchar(12) NOT NULL,
				first_seen datetime NOT NULL,
				last_seen datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY fingerprint (fingerprint),
				KEY last_seen (last_seen),
				KEY component (component)
			) {$charset};"
		);

		update_option( 'upgw_schema', self::SCHEMA, false );
	}

	/**
	 * Adds the plugin's tables of a site to the tables WordPress drops when
	 * that site is deleted from a network.
	 *
	 * @param mixed $tables  Tables WordPress will drop.
	 * @param int   $site_id Site being deleted.
	 * @return mixed
	 */
	public static function site_tables( $tables, $site_id = 0 ) {
		global $wpdb;

		if ( is_array( $tables ) && $site_id > 0 ) {
			$prefix = $wpdb->get_blog_prefix( $site_id );

			foreach ( array( 'upgw_files', 'upgw_findings', 'upgw_events' ) as $table ) {
				$tables[] = $prefix . $table;
			}
		}

		return $tables;
	}

	/**
	 * Makes sure the tables match this version of the plugin.
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( 'upgw_schema', 0 ) !== self::SCHEMA ) {
			self::install();
		}
	}

	/**
	 * Removes the tables and the plugin's options, on every site of a network.
	 */
	public static function uninstall() {
		if ( ! is_multisite() ) {
			self::uninstall_site();

			return;
		}

		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site ) {
			switch_to_blog( (int) $site );
			self::uninstall_site();
			restore_current_blog();
		}
	}

	/**
	 * Removes the tables and the options of the current site.
	 */
	private static function uninstall_site() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Own tables; dropping tables has no API.
		foreach ( array( self::events(), self::findings(), self::files() ) as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
		// phpcs:enable

		foreach ( array( 'upgw_schema', 'upgw_job', 'upgw_target', 'upgw_last_scan', 'upgw_monitor', 'upgw_monitor_seen', 'upgw_auto', 'upgw_auto_state' ) as $option ) {
			delete_option( $option );
		}

		self::unschedule();
	}

	/**
	 * Removes the scheduled events of the current site.
	 */
	public static function unschedule() {
		foreach ( self::SCHEDULED as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
