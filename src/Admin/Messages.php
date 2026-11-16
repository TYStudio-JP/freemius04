<?php
/**
 * Plain-language text for findings.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Admin;

use TYStudio\UpgradeWarden\Engine\Finding;
use TYStudio\UpgradeWarden\Scan\Report;

defined( 'ABSPATH' ) || exit;

/**
 * Words for rules and levels.
 */
final class Messages {

	/**
	 * What a finding is, in one sentence.
	 *
	 * @param string $rule    Rule id.
	 * @param string $subject What the finding is about; may be empty.
	 * @return string
	 */
	public static function describe( $rule, $subject ) {
		$code = '' !== $subject ? '<code>' . esc_html( $subject ) . '</code>' : '';

		switch ( $rule ) {
			case 'function.removed':
				/* translators: %s: function name. */
				return sprintf( __( 'Calls %s(), a function PHP no longer has.', 'upgrade-warden' ), $code );
			case 'function.deprecated':
				/* translators: %s: function name. */
				return sprintf( __( 'Calls %s(), which PHP has deprecated.', 'upgrade-warden' ), $code );
			case 'constant.removed':
				/* translators: %s: constant name. */
				return sprintf( __( 'Uses the constant %s, which PHP no longer has.', 'upgrade-warden' ), $code );
			case 'constant.deprecated':
				/* translators: %s: constant name. */
				return sprintf( __( 'Uses the constant %s, which PHP has deprecated.', 'upgrade-warden' ), $code );
			case 'syntax.curly_offset':
				return __( 'Reads an array or string offset with curly braces, as in $text{0}.', 'upgrade-warden' );
			case 'syntax.real_cast':
				return __( 'Uses the (real) cast.', 'upgrade-warden' );
			case 'syntax.unset_cast':
				return __( 'Uses the (unset) cast.', 'upgrade-warden' );
			case 'syntax.noncanonical_cast':
				return __( 'Uses a long cast name such as (integer) or (boolean).', 'upgrade-warden' );
			case 'syntax.nested_ternary':
				return __( 'Chains ternary operators without parentheses.', 'upgrade-warden' );
			case 'syntax.php4_constructor':
				/* translators: %s: class name. */
				return sprintf( __( 'The class %s has a constructor named after the class. PHP no longer calls it when an object is created, and gives no warning.', 'upgrade-warden' ), $code );
			case 'syntax.reserved_name':
				/* translators: %s: name. */
				return sprintf( __( 'Uses %s as a function name; it is now a reserved word.', 'upgrade-warden' ), $code );
			case 'syntax.break_outside_loop':
				/* translators: %s: "break" or "continue". */
				return sprintf( __( 'Has a %s statement with no loop or switch around it. The whole file fails to load.', 'upgrade-warden' ), $code );
			case 'syntax.backtick':
				return __( 'Runs a command with the backtick operator.', 'upgrade-warden' );
			case 'syntax.case_semicolon':
				return __( 'Ends a case with a semicolon instead of a colon.', 'upgrade-warden' );
			case 'signature.implicit_nullable':
				/* translators: %s: parameter name. */
				return sprintf( __( 'The parameter %s has a type and a null default, but the type does not say it may be null.', 'upgrade-warden' ), $code );
			case 'signature.required_after_optional':
				/* translators: %s: parameter name. */
				return sprintf( __( 'The optional parameter %s comes before a required one.', 'upgrade-warden' ), $code );
			case 'string.dollar_brace':
				return __( 'Writes a variable inside a string as ${name}.', 'upgrade-warden' );
		}

		// A rule without its own sentence yet: show its id rather than nothing.
		/* translators: 1: rule id, 2: what it is about. */
		return trim( sprintf( __( 'Rule %1$s %2$s', 'upgrade-warden' ), '<code>' . esc_html( $rule ) . '</code>', $code ) );
	}

	/**
	 * What happens, and from which version.
	 *
	 * @param string $level One of the Finding::LEVEL_ constants or Report::GUARDED.
	 * @param string $since PHP version.
	 * @return string
	 */
	public static function consequence( $level, $since ) {
		switch ( $level ) {
			case Finding::LEVEL_ERROR:
				/* translators: %s: PHP version. */
				return sprintf( __( 'Stops working from PHP %s.', 'upgrade-warden' ), $since );
			case Finding::LEVEL_CHANGED:
				/* translators: %s: PHP version. */
				return sprintf( __( 'Behaves differently from PHP %s, without a warning.', 'upgrade-warden' ), $since );
			case Finding::LEVEL_DEPRECATED:
				/* translators: %s: PHP version. */
				return sprintf( __( 'Deprecated from PHP %s: still works, raises a notice, and will be removed later.', 'upgrade-warden' ), $since );
		}

		return __( 'Behind a check, so it should not run on the newer PHP.', 'upgrade-warden' );
	}

	/**
	 * Short label of a level or verdict.
	 *
	 * @param string $level Level or verdict.
	 * @return string
	 */
	public static function label( $level ) {
		$labels = array(
			Finding::LEVEL_ERROR      => __( 'Will break', 'upgrade-warden' ),
			Finding::LEVEL_CHANGED    => __( 'Will behave differently', 'upgrade-warden' ),
			Finding::LEVEL_DEPRECATED => __( 'Deprecation notices', 'upgrade-warden' ),
			Report::GUARDED           => __( 'Behind a check', 'upgrade-warden' ),
			'unreadable'              => __( 'Could not be read', 'upgrade-warden' ),
			'unscanned'               => __( 'Not scanned yet', 'upgrade-warden' ),
			'clean'                   => __( 'Nothing found', 'upgrade-warden' ),
		);

		return isset( $labels[ $level ] ) ? $labels[ $level ] : $level;
	}
}
