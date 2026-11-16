<?php
/**
 * One place in a file that a newer PHP version treats differently.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * A finding does not depend on the PHP version the site wants to move to. It
 * records from which version PHP starts to warn, to behave differently and to
 * refuse the code; the level for a given target is worked out from that. One
 * scan therefore answers the question for every target version.
 */
final class Finding {

	const LEVEL_ERROR      = 'error';
	const LEVEL_CHANGED    = 'changed';
	const LEVEL_DEPRECATED = 'deprecated';

	/**
	 * Rule that produced the finding, for example "syntax.curly_offset".
	 *
	 * @var string
	 */
	public $rule;

	/**
	 * Line number, starting at 1.
	 *
	 * @var int
	 */
	public $line;

	/**
	 * Offset of the first byte of the code in question.
	 *
	 * @var int
	 */
	public $start;

	/**
	 * Offset of its last byte.
	 *
	 * @var int
	 */
	public $end;

	/**
	 * What the finding is about, for example a function name. May be empty.
	 *
	 * @var string
	 */
	public $subject = '';

	/**
	 * First version that raises a deprecation notice, or null.
	 *
	 * @var string|null
	 */
	public $deprecated;

	/**
	 * First version on which the code runs but does something else, or null.
	 *
	 * @var string|null
	 */
	public $changed;

	/**
	 * First version on which the code no longer runs, or null.
	 *
	 * @var string|null
	 */
	public $breaks;

	/**
	 * "verified" when a real runtime confirmed the versions above, "documented"
	 * when they come from the official upgrade notes only, "parser" when the
	 * only evidence is that the newest grammar rejects the file.
	 *
	 * @var string
	 */
	public $evidence = 'verified';

	/**
	 * Whether the code sits behind a check such as function_exists() or a PHP
	 * version comparison, and so may never run on the newer version.
	 *
	 * @var bool
	 */
	public $guarded = false;

	/**
	 * What to write instead, as code. May be empty.
	 *
	 * @var string
	 */
	public $instead = '';

	/**
	 * Offsets and names a fix needs. Empty when no automatic fix is known.
	 *
	 * @var array
	 */
	public $fix = array();

	/**
	 * The level of this finding on a target version, or null when that version
	 * still accepts the code without complaint.
	 *
	 * @param string $target Major and minor version, for example "8.4".
	 * @return string|null One of the LEVEL_ constants.
	 */
	public function level_for( $target ) {
		if ( null !== $this->breaks && Versions::at_least( $target, $this->breaks ) ) {
			return self::LEVEL_ERROR;
		}

		if ( null !== $this->changed && Versions::at_least( $target, $this->changed ) ) {
			return self::LEVEL_CHANGED;
		}

		if ( null !== $this->deprecated && Versions::at_least( $target, $this->deprecated ) ) {
			return self::LEVEL_DEPRECATED;
		}

		return null;
	}

	/**
	 * Whether the level on a target rests on a real runtime of that version.
	 *
	 * @param string $target Major and minor version.
	 * @return bool
	 */
	public function runtime_checked_for( $target ) {
		$level = $this->level_for( $target );

		if ( null === $level || 'verified' !== $this->evidence ) {
			return false;
		}

		$since = self::LEVEL_ERROR === $level ? $this->breaks : ( self::LEVEL_CHANGED === $level ? $this->changed : $this->deprecated );

		// Checked on the version the change starts in, or on the oldest runtime
		// when the change is older than that.
		return Versions::runtime_checked( $since ) || Versions::number( $since ) < Versions::number( Versions::RUNTIME_CHECKED[0] );
	}
}
