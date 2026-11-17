<?php
/**
 * range() calls whose literal arguments PHP 8.3 treats differently.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule ids:
 *
 * - argument.range_negative_step     range( 1, 5, -1 ), range( 1, 5, -0.5 ),
 *                                    range( 0.0, 1.0, -0.25 ) or
 *                                    range( 'a', 'e', -1 ): a ValueError from
 *                                    8.3; until 8.2 the sign of the step was
 *                                    ignored
 * - argument.range_digit_and_letter  range( '5', 'z' ) or range( '5', 'z', 1 ):
 *                                    until 8.2 the letter was
 *                                    read as 0 and the result was numbers; from
 *                                    8.3 it is the characters in between
 * - argument.range_float_step        range( 1, 5, 2.0 ) or range( 5, 1, -2.0 ):
 *                                    floats until 8.2, integers from 8.3
 *
 * Only literal arguments are looked at. The other changes to range() depend on
 * values (arrays, NAN, empty strings, more than one byte) and are recorded as
 * runtime.range_* fixtures; several of them only add an E_WARNING.
 */
final class Php83Range implements Rule {

	/**
	 * A name no function has, so that only a version check counts as a guard.
	 */
	const VERSION_GUARD = '8.3: version check only';

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
		if ( 'range' !== InternalFunctions::global_name( $node->name ) || isset( $context->declared_functions['range'] ) ) {
			return;
		}

		$start = self::argument( $node, 0, 'start' );
		$end   = self::argument( $node, 1, 'end' );
		$step  = self::argument( $node, 2, 'step' );

		if ( false === $start || false === $end || false === $step || null === $start || null === $end ) {
			return;
		}

		$rule = null;
		$when = array();

		$start_number = self::number( $start );
		$end_number   = self::number( $end );
		$step_number  = null === $step ? null : self::number( $step );

		if ( null !== $step_number && $step_number < 0 && self::increasing( $start, $end, $start_number, $end_number, $step_number ) ) {
			// range( 1, 5, -1 ), range( 1, 5, -0.5 ), range( 0.0, 1.0, -0.25 ),
			// range( 'a', 'e', -1 ): the sign was ignored until 8.2, a
			// ValueError from 8.3.
			$rule = 'argument.range_negative_step';
		} elseif ( is_int( $start_number ) && is_int( $end_number ) && null !== $step_number ) {
			$whole = is_int( $step_number ) || floor( $step_number ) === $step_number;

			if ( is_float( $step_number ) && $whole && 0.0 !== $step_number && ( $step_number > 0 || $start_number > $end_number ) ) {
				// range( 1, 5, 2.0 ) and range( 5, 1, -2.0 ): floats until 8.2.
				$rule = 'argument.range_float_step';
			}
		} elseif ( ( null === $step || 1 === $step_number ) && $start instanceof Node\Scalar\String_ && $end instanceof Node\Scalar\String_ ) {
			$a = $start->value;
			$b = $end->value;

			if ( 1 === strlen( $a ) && 1 === strlen( $b ) && ( ( ctype_digit( $a ) && ctype_alpha( $b ) ) || ( ctype_alpha( $a ) && ctype_digit( $b ) ) ) ) {
				$rule = 'argument.range_digit_and_letter';
			}
		}

		if ( null === $rule ) {
			return;
		}

		if ( 'argument.range_negative_step' === $rule ) {
			$finding          = $context->report( 'argument.range_negative_step', $node, array( 'breaks' => '8.3' ) );
			$finding->instead = 'a positive step; range() counts in the direction from $start to $end';
		} elseif ( 'argument.range_float_step' === $rule ) {
			$finding          = $context->report( 'argument.range_float_step', $node, array( 'changed' => '8.3' ) );
			$finding->instead = 'an integer step, or floats as boundaries, so that the result has the same type on every version';
		} else {
			$finding          = $context->report( 'argument.range_digit_and_letter', $node, array( 'changed' => '8.3' ) );
			$finding->instead = 'two numbers, or two letters';
		}

		$finding->subject = 'range()';
		$finding->guarded = $context->function_is_guarded( self::VERSION_GUARD );
	}

	/**
	 * The expression passed for a parameter.
	 *
	 * @param Node\Expr\FuncCall $call     Call.
	 * @param int                $position Position of the parameter, from 0.
	 * @param string             $name     Name of the parameter.
	 * @return Node\Expr|null|false Null when not passed; false when the
	 *                              arguments cannot be read (spread, placeholder).
	 */
	public static function argument( Node\Expr\FuncCall $call, $position, $name ) {
		$index = 0;

		foreach ( $call->args as $arg ) {
			if ( ! $arg instanceof Node\Arg || $arg->unpack ) {
				return false;
			}

			if ( null !== $arg->name ) {
				if ( $name === $arg->name->toString() ) {
					return $arg->value;
				}

				continue;
			}

			if ( $index === $position ) {
				return $arg->value;
			}

			++$index;
		}

		return null;
	}

	/**
	 * Whether literal boundaries make an increasing range for which a
	 * negative step is a ValueError from 8.3 (seen on the runtimes): two
	 * numbers, or with an integer step two single letters or two single
	 * digits written as strings.
	 *
	 * @param Node\Expr      $start        Start.
	 * @param Node\Expr      $end          End.
	 * @param int|float|null $start_number Start as a number literal.
	 * @param int|float|null $end_number   End as a number literal.
	 * @param int|float      $step_number  Step.
	 * @return bool
	 */
	private static function increasing( Node\Expr $start, Node\Expr $end, $start_number, $end_number, $step_number ) {
		if ( null !== $start_number && null !== $end_number ) {
			return $start_number < $end_number;
		}

		if ( ! is_int( $step_number ) || ! $start instanceof Node\Scalar\String_ || ! $end instanceof Node\Scalar\String_ ) {
			return false;
		}

		$a = $start->value;
		$b = $end->value;

		if ( 1 !== strlen( $a ) || 1 !== strlen( $b ) ) {
			return false;
		}

		$letters = ctype_alpha( $a ) && ctype_alpha( $b );
		$digits  = ctype_digit( $a ) && ctype_digit( $b );

		return ( $letters || $digits ) && strcmp( $a, $b ) < 0;
	}

	/**
	 * The value of an integer or float literal, with an optional sign.
	 *
	 * @param Node\Expr $expr Expression.
	 * @return int|float|null Null for anything else.
	 */
	public static function number( Node\Expr $expr ) {
		$sign = 1;

		if ( $expr instanceof Node\Expr\UnaryMinus ) {
			$sign = -1;
			$expr = $expr->expr;
		} elseif ( $expr instanceof Node\Expr\UnaryPlus ) {
			$expr = $expr->expr;
		}

		if ( $expr instanceof Node\Scalar\Int_ || $expr instanceof Node\Scalar\Float_ ) {
			return $sign * $expr->value;
		}

		return null;
	}
}
