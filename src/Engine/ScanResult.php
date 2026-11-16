<?php
/**
 * What scanning one file produced.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Scan result of one file.
 */
final class ScanResult {

	/**
	 * The file parses with the newest grammar.
	 */
	const GRAMMAR_CURRENT = 'current';

	/**
	 * The file only parses with the PHP 7.4 grammar: it uses syntax that a
	 * later version removed.
	 */
	const GRAMMAR_LEGACY = 'legacy';

	/**
	 * The file parses with neither grammar and could not be examined.
	 */
	const GRAMMAR_NONE = 'none';

	/**
	 * Findings in the order they appear in the file.
	 *
	 * @var Finding[]
	 */
	public $findings = array();

	/**
	 * One of the GRAMMAR_ constants.
	 *
	 * @var string
	 */
	public $grammar = self::GRAMMAR_CURRENT;

	/**
	 * Why the file could not be parsed, when grammar is GRAMMAR_NONE.
	 *
	 * @var string
	 */
	public $parse_error = '';

	/**
	 * Lower-case names of the functions the file declares in the global
	 * namespace. A plugin that brings its own each() does not call PHP's.
	 *
	 * @var string[]
	 */
	public $declared_functions = array();

	/**
	 * The most severe level among the findings for a target version, ignoring
	 * guarded code, or null when the file is clean for that version.
	 *
	 * @param string $target Major and minor version.
	 * @return string|null One of the Finding::LEVEL_ constants.
	 */
	public function worst_level_for( $target ) {
		$order = array(
			Finding::LEVEL_DEPRECATED => 1,
			Finding::LEVEL_CHANGED    => 2,
			Finding::LEVEL_ERROR      => 3,
		);
		$worst = null;

		foreach ( $this->findings as $finding ) {
			$level = $finding->guarded ? null : $finding->level_for( $target );

			if ( null !== $level && ( null === $worst || $order[ $level ] > $order[ $worst ] ) ) {
				$worst = $level;
			}
		}

		return $worst;
	}
}
