<?php
/**
 * The run-time log: what PHP said on this site, stored on this site.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Monitor;

use TYStudio\UpgradeWarden\Scan\Components;
use TYStudio\UpgradeWarden\Store\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * One row per place and message, with the first and the last time it was
 * seen. Nothing about the visitor or the page is stored: no address, no
 * request data, no stack trace.
 */
final class Log {

	/**
	 * Option that remembers which rows were written lately.
	 */
	const SEEN = 'upgw_monitor_seen';

	/**
	 * A row is written at most once in this many seconds. A notice raised on
	 * every page view must not cost a database write on every page view.
	 */
	const WRITE_EVERY = 3600;

	/**
	 * Days a row is kept after it was last seen. Longer in Pro.
	 */
	const DAYS = 7;

	/**
	 * Most rows kept. More in Pro.
	 */
	const ROWS = 300;

	/**
	 * Stores the events of a request. Called at the end of the request.
	 *
	 * @param array[] $events Events from Capture.
	 */
	public static function record( array $events ) {
		global $wpdb;

		if ( ! $events || ! isset( $wpdb ) || ! function_exists( 'get_option' ) ) {
			return;
		}

		$seen = get_option( self::SEEN, array() );
		$seen = is_array( $seen ) ? $seen : array();
		$now  = time();
		$php  = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		$todo = array();

		foreach ( $events as $event ) {
			$file        = self::relative( $event['file'] );
			$fingerprint = md5( $php . '|' . Signatures::normalize( $event['type'], $event['message'] ) . '|' . $file . '|' . $event['line'] );
			$short       = substr( $fingerprint, 0, 12 );

			if ( isset( $seen[ $short ] ) && $now - $seen[ $short ] < self::WRITE_EVERY ) {
				continue;
			}

			$seen[ $short ]       = $now;
			$todo[ $fingerprint ] = array( $event, $file );
		}

		if ( ! $todo ) {
			return;
		}

		$table  = Tables::events();
		$stamp  = gmdate( 'Y-m-d H:i:s', $now );
		$errors = $wpdb->suppress_errors( true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; the name comes from the prefix.
		foreach ( $todo as $fingerprint => $item ) {
			list( $event, $file ) = $item;

			$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE fingerprint = %s", $fingerprint ) );

			if ( $id ) {
				$wpdb->update( $table, array( 'last_seen' => $stamp ), array( 'id' => $id ) );
				continue;
			}

			$match = is_array( $event['match'] ) ? $event['match'] : array();

			$wpdb->insert(
				$table,
				array(
					'fingerprint' => $fingerprint,
					'type'        => substr( $event['type'], 0, 60 ),
					'message'     => $event['message'],
					'file'        => substr( $file, 0, 1000 ),
					'line'        => (int) $event['line'],
					'component'   => substr( Components::of_file( $event['file'] ), 0, 191 ),
					'php'         => $php,
					'rule'        => isset( $match['rule'] ) ? $match['rule'] : '',
					'deprecated'  => isset( $match['deprecated'] ) ? $match['deprecated'] : null,
					'changed'     => isset( $match['changed'] ) ? $match['changed'] : null,
					'breaks'      => isset( $match['breaks'] ) ? $match['breaks'] : null,
					'evidence'    => isset( $match['evidence'] ) ? $match['evidence'] : '',
					'first_seen'  => $stamp,
					'last_seen'   => $stamp,
				)
			);
		}
		// phpcs:enable

		$wpdb->suppress_errors( $errors );

		if ( count( $seen ) > 600 ) {
			arsort( $seen );
			$seen = array_slice( $seen, 0, 400, true );
		}

		update_option( self::SEEN, $seen, false );
	}

	/**
	 * Rows of the log, newest first.
	 *
	 * @param string $component Only this component; empty for all.
	 * @param int    $limit     Most rows to return.
	 * @return object[]
	 */
	public static function rows( $component = '', $limit = 200 ) {
		global $wpdb;

		$table = Tables::events();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		if ( '' !== $component ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE component = %s ORDER BY last_seen DESC, id DESC LIMIT %d", $component, $limit ) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY last_seen DESC, id DESC LIMIT %d", $limit ) );
		}
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Rows first seen after a point in time, oldest first.
	 *
	 * @param int $since Unix time.
	 * @return object[]
	 */
	public static function rows_since( $since ) {
		global $wpdb;

		$table = Tables::events();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE first_seen > %s ORDER BY first_seen, id LIMIT 200", gmdate( 'Y-m-d H:i:s', $since ) ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Number of rows per component.
	 *
	 * @return array<string,int>
	 */
	public static function counts() {
		global $wpdb;

		$table  = Tables::events();
		$counts = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		foreach ( (array) $wpdb->get_results( "SELECT component, COUNT(*) AS found FROM {$table} GROUP BY component" ) as $row ) {
			$counts[ $row->component ] = (int) $row->found;
		}

		return $counts;
	}

	/**
	 * Empties the log.
	 */
	public static function clear() {
		global $wpdb;

		$table = Tables::events();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$wpdb->query( "DELETE FROM {$table}" );
		delete_option( self::SEEN );
	}

	/**
	 * Days a row is kept after it was last seen.
	 *
	 * @return int
	 */
	public static function days() {
		/**
		 * Filters how many days the run-time log keeps a message after it was last seen.
		 *
		 * @param int $days Days.
		 */
		return max( 1, (int) apply_filters( 'upgw_monitor_days', self::DAYS ) );
	}

	/**
	 * Drops rows that are too old, and the oldest ones when there are too many.
	 */
	public static function prune() {
		global $wpdb;

		$table = Tables::events();

		/**
		 * Filters how many messages the run-time log keeps at most.
		 *
		 * @param int $rows Rows.
		 */
		$most = max( 10, (int) apply_filters( 'upgw_monitor_rows', self::ROWS ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE last_seen < %s", gmdate( 'Y-m-d H:i:s', time() - self::days() * DAY_IN_SECONDS ) ) );

		$edge = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY last_seen DESC, id DESC LIMIT 1 OFFSET %d", $most ) );

		if ( $edge ) {
			$keep = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY last_seen DESC, id DESC LIMIT %d", $most ) );

			if ( $keep ) {
				$wpdb->query( "DELETE FROM {$table} WHERE id NOT IN (" . implode( ',', array_map( 'intval', $keep ) ) . ')' );
			}
		}
		// phpcs:enable
	}

	/**
	 * A path as it is stored: relative to the site, never the server's full path.
	 *
	 * @param string $file Absolute path.
	 * @return string
	 */
	public static function relative( $file ) {
		$file = wp_normalize_path( (string) $file );

		foreach ( array( dirname( WP_CONTENT_DIR ), ABSPATH ) as $root ) {
			$root = trailingslashit( wp_normalize_path( $root ) );

			if ( 0 === strpos( $file, $root ) ) {
				return substr( $file, strlen( $root ) );
			}
		}

		return basename( $file );
	}
}
