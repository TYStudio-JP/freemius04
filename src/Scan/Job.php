<?php
/**
 * A scan of the whole site, done in short steps.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Scan;

use TYStudio\UpgradeWarden\Engine\Catalog;
use TYStudio\UpgradeWarden\Engine\Scanner;
use TYStudio\UpgradeWarden\Store\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Shared hosts stop a request after a few seconds, and a site can hold tens of
 * megabytes of PHP. The scan therefore runs as many small requests: each call
 * to step() works for a few seconds, saves where it is and returns.
 *
 * A file that has not changed since it was last scanned by this version of
 * the plugin is skipped, so a second scan only costs what changed.
 */
final class Job {

	const OPTION = 'upgw_job';

	/**
	 * Starts a new scan of every component.
	 *
	 * @return array Progress, see progress().
	 */
	public static function start() {
		$queue = array();

		foreach ( Components::all() as $component ) {
			$queue[] = $component['id'];
		}

		update_option(
			self::OPTION,
			array(
				'status'  => 'running',
				'queue'   => $queue,
				'total'   => count( $queue ),
				'current' => null,
				'files'   => array(),
				'seen'    => array(),
				'scanned' => 0,
				'skipped' => 0,
				'started' => time(),
			),
			false
		);

		return self::progress();
	}

	/**
	 * Works on the scan for a limited time.
	 *
	 * @param float $seconds Time budget.
	 * @return array Progress, see progress().
	 */
	public static function step( $seconds = 4.0 ) {
		$job = get_option( self::OPTION );

		if ( ! is_array( $job ) || 'running' !== $job['status'] ) {
			return self::progress();
		}

		$deadline   = microtime( true ) + $seconds;
		$components = Components::all();
		$scanner    = new Scanner( Catalog::load( UPGW_DIR . 'data/catalog' ) );

		while ( microtime( true ) < $deadline ) {
			if ( null === $job['current'] ) {
				if ( ! $job['queue'] ) {
					$job['status'] = 'done';
					update_option( 'upgw_last_scan', time(), false );
					break;
				}

				$job['current'] = array_shift( $job['queue'] );
				$job['seen']    = array();
				$job['files']   = isset( $components[ $job['current'] ] ) ? Components::files( $components[ $job['current'] ]['path'] ) : array();
			}

			if ( ! $job['files'] ) {
				self::forget_missing( $job['current'], $job['seen'] );
				$job['current'] = null;
				continue;
			}

			$relative      = array_shift( $job['files'] );
			$job['seen'][] = md5( $relative );
			$path          = Components::absolute( $components[ $job['current'] ]['path'], $relative );
			$code          = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.

			if ( false === $code ) {
				continue;
			}

			if ( self::store( $scanner, $job['current'], $relative, $code ) ) {
				++$job['scanned'];
			} else {
				++$job['skipped'];
			}
		}

		update_option( self::OPTION, $job, false );

		return self::progress();
	}

	/**
	 * Scans one component in a single call. Used after its files were changed
	 * here; unchanged files are skipped, so this is quick.
	 *
	 * @param string $id Component id.
	 */
	public static function scan_component( $id ) {
		$components = Components::all();

		if ( ! isset( $components[ $id ] ) ) {
			return;
		}

		$scanner = new Scanner( Catalog::load( UPGW_DIR . 'data/catalog' ) );
		$seen    = array();

		foreach ( Components::files( $components[ $id ]['path'] ) as $relative ) {
			$seen[] = md5( $relative );
			$path   = Components::absolute( $components[ $id ]['path'], $relative );
			$code   = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.

			if ( false !== $code ) {
				self::store( $scanner, $id, $relative, $code );
			}
		}

		self::forget_missing( $id, $seen );
	}

	/**
	 * Where the scan stands.
	 *
	 * @return array{status:string,done:int,total:int,scanned:int,skipped:int,current:string}
	 */
	public static function progress() {
		$job = get_option( self::OPTION );

		if ( ! is_array( $job ) ) {
			return array(
				'status'  => 'none',
				'done'    => 0,
				'total'   => 0,
				'scanned' => 0,
				'skipped' => 0,
				'current' => '',
			);
		}

		return array(
			'status'  => $job['status'],
			'done'    => $job['total'] - count( $job['queue'] ) - ( null === $job['current'] ? 0 : 1 ),
			'total'   => $job['total'],
			'scanned' => $job['scanned'],
			'skipped' => $job['skipped'],
			'current' => (string) $job['current'],
		);
	}

	/**
	 * Scans one file and replaces what is stored about it.
	 *
	 * @param Scanner $scanner   Scanner.
	 * @param string  $component Component id.
	 * @param string  $relative  Relative path.
	 * @param string  $code      File contents.
	 * @return bool False when the stored result was still current.
	 */
	private static function store( Scanner $scanner, $component, $relative, $code ) {
		global $wpdb;

		$files     = Tables::files();
		$findings  = Tables::findings();
		$path_hash = md5( $relative );
		$hash      = md5( $code );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own tables; table names come from the prefix.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, content_hash, catalog FROM {$files} WHERE component = %s AND path_hash = %s", $component, $path_hash ) );

		if ( $row && $row->content_hash === $hash && UPGW_VERSION === $row->catalog ) {
			return false;
		}

		$result = $scanner->scan( $code );
		$data   = array(
			'component'    => $component,
			'path'         => $relative,
			'path_hash'    => $path_hash,
			'content_hash' => $hash,
			'catalog'      => UPGW_VERSION,
			'grammar'      => $result->grammar,
			'parse_error'  => $result->parse_error,
			'declared'     => implode( ',', $result->declared_functions ),
			'scanned_at'   => gmdate( 'Y-m-d H:i:s' ),
		);

		if ( $row ) {
			$wpdb->update( $files, $data, array( 'id' => $row->id ) );
			$wpdb->delete( $findings, array( 'file_id' => $row->id ) );
			$file_id = (int) $row->id;
		} else {
			$wpdb->insert( $files, $data );
			$file_id = (int) $wpdb->insert_id;
		}

		foreach ( $result->findings as $finding ) {
			$wpdb->insert(
				$findings,
				array(
					'file_id'    => $file_id,
					'rule'       => $finding->rule,
					'line'       => $finding->line,
					'start_pos'  => $finding->start,
					'end_pos'    => $finding->end,
					'subject'    => substr( $finding->subject, 0, 191 ),
					'deprecated' => $finding->deprecated,
					'changed'    => $finding->changed,
					'breaks'     => $finding->breaks,
					'evidence'   => $finding->evidence,
					'guarded'    => $finding->guarded ? 1 : 0,
					'fixable'    => $finding->fix ? 1 : 0,
					'instead'    => substr( $finding->instead, 0, 191 ),
				)
			);
		}
		// phpcs:enable

		return true;
	}

	/**
	 * Drops what is stored about files that are no longer there.
	 *
	 * @param string   $component Component id.
	 * @param string[] $seen      Path hashes of the files that exist.
	 */
	private static function forget_missing( $component, array $seen ) {
		global $wpdb;

		$files    = Tables::files();
		$findings = Tables::findings();
		$seen     = array_flip( $seen );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own tables.
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, path_hash FROM {$files} WHERE component = %s", $component ) ) as $row ) {
			if ( ! isset( $seen[ $row->path_hash ] ) ) {
				$wpdb->delete( $findings, array( 'file_id' => $row->id ) );
				$wpdb->delete( $files, array( 'id' => $row->id ) );
			}
		}
		// phpcs:enable
	}
}
