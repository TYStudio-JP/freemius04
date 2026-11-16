<?php
/**
 * Rewrites code the scanner found, where a safe rewrite is known.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Pro\Fix;

use TYStudio\UpgradeWarden\Engine\Finding;
use TYStudio\UpgradeWarden\Engine\Scanner;
use TYStudio\UpgradeWarden\Engine\ScanResult;

defined( 'ABSPATH' ) || exit;

/**
 * A fix here is a small text edit at positions the scanner reported, never a
 * re-print of the file: everything the edit does not touch, including layout
 * and comments, stays byte for byte as it was.
 *
 * Each kind of fix has a fixture in tools/fixtures/ whose FIXED section is
 * exactly what this class produces. That fixed program was run on real PHP
 * 7.4 to 8.5 and printed the same as the original. A kind without such a
 * fixture must not be added.
 *
 * The fixed code still runs on the PHP version it came from, so a site can be
 * fixed first and switched to the newer PHP afterwards.
 */
final class Fixer {

	/**
	 * How often the file is scanned and fixed again. One fix can uncover the
	 * next: a file that did not parse at all starts to, or a fix sits inside
	 * code another fix has just moved.
	 */
	const MAX_PASSES = 6;

	/**
	 * Scanner.
	 *
	 * @var Scanner
	 */
	private $scanner;

	/**
	 * Constructor.
	 *
	 * @param Scanner $scanner Scanner.
	 */
	public function __construct( Scanner $scanner ) {
		$this->scanner = $scanner;
	}

	/**
	 * Fixes what can be fixed in the given source.
	 *
	 * @param string        $code               Contents of a PHP file.
	 * @param string[]|null $rules              Rule ids to fix; all by default.
	 * @param string[]      $declared_elsewhere Passed on to the scanner.
	 * @return array{code:string,applied:array[],error:string} The new source,
	 *               the fixes made (rule and line in the original pass) and,
	 *               when the result had to be thrown away, the reason.
	 */
	public function fix( $code, ?array $rules = null, array $declared_elsewhere = array() ) {
		$original = $code;
		$applied  = array();

		for ( $pass = 0; $pass < self::MAX_PASSES; $pass++ ) {
			$result = $this->scanner->scan( $code, $declared_elsewhere );

			if ( ScanResult::GRAMMAR_NONE === $result->grammar ) {
				// Never hand back a file that does not parse.
				return array(
					'code'    => $original,
					'applied' => array(),
					'error'   => 0 === $pass ? 'The file cannot be parsed, so nothing was changed.' : 'A fix produced code that does not parse. Nothing was changed.',
				);
			}

			$groups = array();

			foreach ( $result->findings as $finding ) {
				if ( ! $finding->fix || ( null !== $rules && ! in_array( $finding->rule, $rules, true ) ) ) {
					continue;
				}

				$edits = $this->edits_for( $finding );

				if ( $edits ) {
					$groups[] = array( $finding, $edits );
				}
			}

			if ( ! $groups ) {
				break;
			}

			// Work from the end of the file so that earlier offsets stay valid.
			usort(
				$groups,
				static function ( $a, $b ) {
					return self::span( $b[1] )[0] - self::span( $a[1] )[0];
				}
			);

			$floor   = PHP_INT_MAX;
			$changed = false;

			foreach ( $groups as $group ) {
				list( $finding, $edits ) = $group;
				list( $from, $to )       = self::span( $edits );

				// Overlaps a fix already made in this pass: leave it for the next.
				if ( $to > $floor ) {
					continue;
				}

				usort(
					$edits,
					static function ( $a, $b ) {
						return $b[0] - $a[0];
					}
				);

				foreach ( $edits as $edit ) {
					$code = substr( $code, 0, $edit[0] ) . $edit[2] . substr( $code, $edit[0] + $edit[1] );
				}

				$floor     = $from;
				$changed   = true;
				$applied[] = array(
					'rule' => $finding->rule,
					'line' => $finding->line,
				);
			}

			if ( ! $changed ) {
				break;
			}
		}

		if ( $applied && ScanResult::GRAMMAR_NONE === $this->scanner->scan( $code, $declared_elsewhere )->grammar ) {
			return array(
				'code'    => $original,
				'applied' => array(),
				'error'   => 'A fix produced code that does not parse. Nothing was changed.',
			);
		}

		return array(
			'code'    => $code,
			'applied' => $applied,
			'error'   => '',
		);
	}

	/**
	 * First offset touched and the offset after the last one touched.
	 *
	 * @param array[] $edits Edits as offset, length to remove, text to insert.
	 * @return int[]
	 */
	private static function span( array $edits ) {
		$from = PHP_INT_MAX;
		$to   = 0;

		foreach ( $edits as $edit ) {
			$from = min( $from, $edit[0] );
			$to   = max( $to, $edit[0] + $edit[1] );
		}

		return array( $from, $to );
	}

	/**
	 * The text edits that make up the fix of one finding.
	 *
	 * @param Finding $finding Finding with fix data from its rule.
	 * @return array[] Edits as offset, length to remove, text to insert.
	 */
	private function edits_for( Finding $finding ) {
		$fix = $finding->fix;

		switch ( $fix['kind'] ) {
			case 'replace':
			case 'cast':
				return array( array( $fix['start'], $fix['end'] - $fix['start'] + 1, $fix['text'] ) );

			case 'insert':
				return array( array( $fix['at'], 0, $fix['text'] ) );

			case 'wrap':
				return array(
					array( $fix['start'], 0, '( ' ),
					array( $fix['end'] + 1, 0, ' )' ),
				);

			case 'curly_offset':
				return array(
					array( $fix['open'], 1, '[' ),
					array( $fix['close'], 1, ']' ),
				);

			case 'utf8_encode':
				// Needs the mbstring extension, which the caller has to check.
				return array(
					array( $fix['name_start'], $fix['name_end'] - $fix['name_start'] + 1, ( $fix['qualified'] ? '\\' : '' ) . 'mb_convert_encoding' ),
					array( $fix['arg_end'] + 1, 0, ", 'UTF-8', 'ISO-8859-1'" ),
				);

			case 'php4_constructor':
				// The method becomes __construct(). A method of the old name stays
				// and passes its arguments on, because other code may call it,
				// typically a child class through parent::OldName().
				$call    = '' === $fix['forward'] ? 'self::__construct();' : 'self::__construct( ' . $fix['forward'] . ' );';
				$wrapper = "\n\n" . $fix['indent'] . $fix['head'] . " {\n" . $fix['indent'] . "\t" . $call . "\n" . $fix['indent'] . '}';

				return array(
					array( $fix['name_start'], $fix['name_end'] - $fix['name_start'] + 1, '__construct' ),
					array( $fix['method_end'] + 1, 0, $wrapper ),
				);
		}

		return array();
	}
}
