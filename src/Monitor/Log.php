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

			$todo[ $fingerprint ] = array( $event, $file, $short );
		}

		if ( ! $todo ) {
			return;
		}

		$table  = Tables::events();
		$stamp  = gmdate( 'Y-m-d H:i:s', $now );
		$errors = $wpdb->suppress_errors( true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Own table.
		foreach ( $todo as $fingerprint => $item ) {
			list( $event, $file, $short ) = $item;

			$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE fingerprint = %s', $table, $fingerprint ) );

			if ( $id ) {
				if ( false !== $wpdb->update( $table, array( 'last_seen' => $stamp ), array( 'id' => $id ) ) ) {
					$seen[ $short ] = $now;
				}

				continue;
			}

			$match = is_array( $event['match'] ) ? $event['match'] : array();

			$inserted = $wpdb->insert(
				$table,
				array(
					'fingerprint' => $fingerprint,
					'type'        => substr( $event['type'], 0, 60 ),
					'message'     => self::without_roots( $event['message'] ),
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

			// Only a stored message waits before it is written again; one the
			// database refused is tried again on the next request.
			if ( $inserted ) {
				$seen[ $short ] = $now;
			}
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Own table.
		if ( '' !== $component ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE component = %s ORDER BY last_seen DESC, id DESC LIMIT %d', $table, $component, $limit ) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY last_seen DESC, id DESC LIMIT %d', $table, $limit ) );
		}
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Rows first seen after a point in time, oldest first.
	 *
	 * @param int $since Unix time.
	 * @param int $limit Most rows to return.
	 * @return object[]
	 */
	public static function rows_since( $since, $limit = 200 ) {
		global $wpdb;

		$table = Tables::events();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE first_seen > %s ORDER BY first_seen, id LIMIT %d', $table, gmdate( 'Y-m-d H:i:s', $since ), $limit ) );

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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT component, COUNT(*) AS found FROM %i GROUP BY component', $table ) ) as $row ) {
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE last_seen < %s', $table, gmdate( 'Y-m-d H:i:s', time() - self::days() * DAY_IN_SECONDS ) ) );

		// The first row beyond the limit, newest first: it and everything older goes.
		$edge = $wpdb->get_row( $wpdb->prepare( 'SELECT id, last_seen FROM %i ORDER BY last_seen DESC, id DESC LIMIT 1 OFFSET %d', $table, $most ) );

		if ( $edge ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE last_seen < %s OR ( last_seen = %s AND id <= %d )', $table, $edge->last_seen, $edge->last_seen, $edge->id ) );
		}
		// phpcs:enable
	}

	/**
	 * A message without the server's folders in front of the paths it names
	 * ("called in /home/…/wp-content/plugins/…" becomes "wp-content/plugins/…").
	 *
	 * @param string $message Message.
	 * @return string
	 */
	public static function without_roots( $message ) {
		$message = (string) $message;

		foreach ( array( dirname( WP_CONTENT_DIR ), ABSPATH ) as $root ) {
			foreach ( array_unique( array( trailingslashit( wp_normalize_path( $root ) ), trailingslashit( $root ) ) ) as $prefix ) {
				if ( strlen( $prefix ) > 1 ) {
					$message = str_replace( $prefix, '', $message );
				}
			}
		}

		return $message;
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
