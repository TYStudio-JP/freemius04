<?php
/**
 * Reads stored scan results for a target PHP version.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Scan;

use TYStudio\UpgradeWarden\Engine\Finding;
use TYStudio\UpgradeWarden\Engine\ScanResult;
use TYStudio\UpgradeWarden\Store\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Turns stored findings into verdicts.
 */
final class Report {

	const GUARDED = 'guarded';

	/**
	 * The findings of one component that matter for a target version.
	 *
	 * @param string $component Component id.
	 * @param string $target    PHP version, for example "8.4".
	 * @return array{findings:array[],unreadable:array[],files:int,legacy:int}
	 *         Each finding: path, line, rule, subject, instead, level (one of
	 *         the Finding::LEVEL_ constants or self::GUARDED), since, evidence,
	 *         checked (bool), fixable (bool).
	 */
	public static function component( $component, $target ) {
		global $wpdb;

		$files_table    = Tables::files();
		$findings_table = Tables::findings();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Own tables.
		$files = $wpdb->get_results( $wpdb->prepare( 'SELECT id, path, grammar, parse_error, declared FROM %i WHERE component = %s ORDER BY path', $files_table, $component ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT f.*, p.path FROM %i f INNER JOIN %i p ON p.id = f.file_id WHERE p.component = %s ORDER BY p.path, f.line, f.id',
				$findings_table,
				$files_table,
				$component
			)
		);
		// phpcs:enable

		$declared   = array();
		$unreadable = array();
		$legacy     = 0;

		foreach ( $files as $file ) {
			foreach ( array_filter( explode( ',', $file->declared ) ) as $name ) {
				$declared[ $name ] = true;
			}

			if ( ScanResult::GRAMMAR_NONE === $file->grammar ) {
				$unreadable[] = array(
					'path'  => $file->path,
					'error' => $file->parse_error,
				);
			} elseif ( ScanResult::GRAMMAR_LEGACY === $file->grammar ) {
				++$legacy;
			}
		}

		$findings = array();

		foreach ( $rows as $row ) {
			// A function the component declares itself is not PHP's own.
			if ( 0 === strpos( $row->rule, 'function.' ) && isset( $declared[ $row->subject ] ) ) {
				continue;
			}

			$finding             = new Finding();
			$finding->rule       = $row->rule;
			$finding->deprecated = $row->deprecated;
			$finding->changed    = $row->changed;
			$finding->breaks     = $row->breaks;
			$finding->evidence   = $row->evidence;
			$level               = $finding->level_for( $target );

			if ( null === $level ) {
				continue;
			}

			$since = Finding::LEVEL_ERROR === $level ? $row->breaks : ( Finding::LEVEL_CHANGED === $level ? $row->changed : $row->deprecated );

			$findings[] = array(
				'path'     => $row->path,
				'line'     => (int) $row->line,
				'rule'     => $row->rule,
				'subject'  => $row->subject,
				'instead'  => $row->instead,
				'level'    => $row->guarded ? self::GUARDED : $level,
				'since'    => $since,
				'evidence' => $row->evidence,
				'checked'  => $finding->runtime_checked_for( $target ),
				'fixable'  => (bool) $row->fixable,
			);
		}

		return array(
			'findings'   => $findings,
			'unreadable' => $unreadable,
			'files'      => count( $files ),
			'legacy'     => $legacy,
		);
	}

	/**
	 * Counts per level for one component.
	 *
	 * @param string $component Component id.
	 * @param string $target    PHP version.
	 * @return array{error:int,changed:int,deprecated:int,guarded:int,fixable:int,unreadable:int,files:int,verdict:string}
	 *         verdict is "error", "changed", "deprecated", "clean", "unreadable" or "unscanned".
	 */
	public static function summary( $component, $target ) {
		$detail = self::component( $component, $target );
		$counts = array(
			Finding::LEVEL_ERROR      => 0,
			Finding::LEVEL_CHANGED    => 0,
			Finding::LEVEL_DEPRECATED => 0,
			self::GUARDED             => 0,
			'fixable'                 => 0,
		);

		foreach ( $detail['findings'] as $finding ) {
			++$counts[ $finding['level'] ];

			if ( $finding['fixable'] && self::GUARDED !== $finding['level'] ) {
				++$counts['fixable'];
			}
		}

		$counts['unreadable'] = count( $detail['unreadable'] );
		$counts['files']      = $detail['files'];

		if ( 0 === $detail['files'] ) {
			$counts['verdict'] = 'unscanned';
		} elseif ( $counts[ Finding::LEVEL_ERROR ] > 0 ) {
			$counts['verdict'] = Finding::LEVEL_ERROR;
		} elseif ( $counts[ Finding::LEVEL_CHANGED ] > 0 ) {
			$counts['verdict'] = Finding::LEVEL_CHANGED;
		} elseif ( $counts['unreadable'] > 0 ) {
			// Not having read a file is not the same as the file being fine.
			$counts['verdict'] = 'unreadable';
		} elseif ( $counts[ Finding::LEVEL_DEPRECATED ] > 0 ) {
			$counts['verdict'] = Finding::LEVEL_DEPRECATED;
		} else {
			$counts['verdict'] = 'clean';
		}

		return $counts;
	}
}
