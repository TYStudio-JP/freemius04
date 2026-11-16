<?php
/**
 * Session INI settings whose change PHP 8.4 deprecates.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: ini.session_deprecated_setting.
 *
 * Reported for ini_set(), ini_alter() and the options array of
 * session_start(), and only when both the setting and its new value are
 * written out as literals. What the runtimes showed is narrower than the
 * upgrade notes: setting a value that equals the built-in default raises
 * nothing, so only these are reported:
 *
 * - session.sid_length              a number other than 32
 * - session.sid_bits_per_character  5 or 6 (the default is 4)
 * - session.use_only_cookies        switched off
 * - session.use_trans_sid           switched on
 * - session.trans_sid_tags          anything but the default list
 * - session.trans_sid_hosts         a non-empty value
 * - session.referer_check           a non-empty value
 *
 * A value from a variable or a constant is known at run time only and is not
 * reported. A php.ini that already differs from the defaults is not visible
 * here either.
 */
final class Php84DepSessionIni implements Rule {

	const DEFAULT_TAGS = 'a=href,area=href,frame=src,form=';

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Expr\FuncCall::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Expr\FuncCall $node    Function call.
	 * @param Context            $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		$name = InternalFunctions::global_name( $node->name );

		if ( null === $name || isset( $context->declared_functions[ $name ] ) ) {
			return;
		}

		foreach ( $node->args as $arg ) {
			if ( ! $arg instanceof Node\Arg || $arg->unpack || null !== $arg->name ) {
				return;
			}
		}

		if ( 'ini_set' === $name || 'ini_alter' === $name ) {
			if ( 2 !== count( $node->args ) || ! $node->args[0]->value instanceof Node\Scalar\String_ ) {
				return;
			}

			$setting = $node->args[0]->value->value;

			if ( 0 === strpos( $setting, 'session.' ) ) {
				$this->check( substr( $setting, 8 ), $node->args[1]->value, $node, $name, $context );
			}

			return;
		}

		if ( 'session_start' !== $name || 1 !== count( $node->args ) || ! $node->args[0]->value instanceof Node\Expr\Array_ ) {
			return;
		}

		foreach ( $node->args[0]->value->items as $item ) {
			if ( null !== $item && ! $item->unpack && $item->key instanceof Node\Scalar\String_ ) {
				$this->check( $item->key->value, $item->value, $item, $name, $context );
			}
		}
	}

	/**
	 * Reports one setting when its literal value is one PHP 8.4 complains about.
	 *
	 * @param string    $setting  Setting name without "session.".
	 * @param Node\Expr $value    Expression of the new value.
	 * @param Node      $at       Node to report.
	 * @param string    $function Lower-case name of the function called.
	 * @param Context   $context  Scan state.
	 */
	private function check( $setting, Node\Expr $value, Node $at, $function, Context $context ) {
		$text = $this->literal( $value );

		if ( null === $text ) {
			return;
		}

		$number = ( '' !== $text && ctype_digit( $text ) && strlen( $text ) < 10 ) ? (int) $text : null;
		$word   = strtolower( $text );

		switch ( $setting ) {
			case 'sid_length':
				$bad = null !== $number && $number >= 22 && $number <= 256 && 32 !== $number;
				break;
			case 'sid_bits_per_character':
				$bad = 5 === $number || 6 === $number;
				break;
			case 'use_only_cookies':
				$bad = 0 === $number || in_array( $word, array( '', 'off', 'no', 'false' ), true );
				break;
			case 'use_trans_sid':
				$bad = ( null !== $number && $number > 0 ) || in_array( $word, array( 'on', 'yes', 'true' ), true );
				break;
			case 'trans_sid_tags':
				$bad = $value instanceof Node\Scalar\String_ && self::DEFAULT_TAGS !== $text;
				break;
			case 'trans_sid_hosts':
			case 'referer_check':
				$bad = $value instanceof Node\Scalar\String_ && '' !== $text;
				break;
			default:
				return;
		}

		if ( ! $bad ) {
			return;
		}

		$finding          = $context->report( 'ini.session_deprecated_setting', $at, array( 'deprecated' => '8.4' ) );
		$finding->subject = 'session.' . $setting;
		$finding->guarded = $context->function_is_guarded( $function );
	}

	/**
	 * The string PHP makes of a literal value, or null when the expression is
	 * not a plain literal.
	 *
	 * @param Node\Expr $value Expression.
	 * @return string|null
	 */
	private function literal( Node\Expr $value ) {
		if ( $value instanceof Node\Scalar\String_ ) {
			return $value->value;
		}

		if ( $value instanceof Node\Scalar\Int_ ) {
			return (string) $value->value;
		}

		if ( $value instanceof Node\Expr\ConstFetch ) {
			$word = strtolower( $value->name->toString() );

			if ( 'true' === $word ) {
				return '1';
			}

			if ( 'false' === $word || 'null' === $word ) {
				return '';
			}
		}

		return null;
	}
}
