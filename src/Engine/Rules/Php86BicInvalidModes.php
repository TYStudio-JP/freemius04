<?php
/**
 * Literal mode arguments outside the documented values (tranche 8.6-bic).
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: argument.invalid_mode_value. Documented for 8.6 only.
 *
 * The PHP 8.6 upgrade notes, word for word:
 *
 *     array_filter() now raises a ValueError when an invalid $mode argument value
 *     is passed.
 *     array_change_key_case() now raises a ValueError when an invalid $case
 *     argument value is passed.
 *     scandir() now raises a ValueError when an invalid $sorting_order argument
 *     value is passed.
 *
 *     array_filter( $list, $callback, 3 );   // 7.4-8.5: as mode 0
 *
 * The runtimes ran each of these with a value outside the documented set
 * without any diagnostic on 7.4 to 8.5 (fixtures
 * argument.invalid_mode_value~8.6-bic-*). No 8.6 runtime: evidence
 * "documented".
 *
 * Reported only when the argument is a literal whose value is certain: an
 * integer, true/false, one of the function's own constants, or those combined
 * with "|". The valid sets are the documented constants:
 *
 * - array_filter $mode: 0, ARRAY_FILTER_USE_BOTH (1), ARRAY_FILTER_USE_KEY (2)
 * - array_change_key_case $case: CASE_LOWER (0), CASE_UPPER (1)
 * - scandir $sorting_order: SCANDIR_SORT_ASCENDING (0), _DESCENDING (1), _NONE (2)
 *
 * pathinfo() is in the same list of the notes, but whether a combination of
 * PATHINFO_* flags counts as invalid is not stated, so it is not reported.
 */
final class Php86BicInvalidModes implements Rule {

	const VERSION_GUARD = '8.6-bic: version check only';

	/**
	 * Function => array( position, parameter name, valid values ).
	 */
	const FUNCTIONS = array(
		'array_filter'          => array( 2, 'mode', array( 0, 1, 2 ) ),
		'array_change_key_case' => array( 1, 'case', array( 0, 1 ) ),
		'scandir'               => array( 1, 'sorting_order', array( 0, 1, 2 ) ),
	);

	/**
	 * Constants that may appear in those arguments, with their values.
	 */
	const CONSTANTS = array(
		'ARRAY_FILTER_USE_BOTH'   => 1,
		'ARRAY_FILTER_USE_KEY'    => 2,
		'CASE_LOWER'              => 0,
		'CASE_UPPER'              => 1,
		'SCANDIR_SORT_ASCENDING'  => 0,
		'SCANDIR_SORT_DESCENDING' => 1,
		'SCANDIR_SORT_NONE'       => 2,
	);

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

		if ( null === $name || ! isset( self::FUNCTIONS[ $name ] ) || isset( $context->declared_functions[ $name ] ) ) {
			return;
		}

		list( $position, $parameter, $valid ) = self::FUNCTIONS[ $name ];

		$value = null;

		foreach ( $node->args as $index => $arg ) {
			if ( ! $arg instanceof Node\Arg || $arg->unpack ) {
				return;
			}

			if ( null === $arg->name ? $position === $index : $parameter === strtolower( $arg->name->toString() ) ) {
				$value = $arg->value;
				break;
			}
		}

		if ( null === $value ) {
			return;
		}

		$number = self::evaluate( $value );

		if ( null === $number || in_array( $number, $valid, true ) ) {
			return;
		}

		$finding           = $context->report( 'argument.invalid_mode_value', $node, array( 'breaks' => '8.6' ) );
		$finding->subject  = $name . '() $' . $parameter . ' = ' . $number;
		$finding->evidence = 'documented';
		$finding->instead  = 'one of ' . implode( ', ', $valid ) . ' (the documented constants); documented for PHP 8.6, not verified on an 8.6 runtime';
		$finding->guarded  = $context->function_is_guarded( self::VERSION_GUARD );
	}

	/**
	 * The integer value of a literal expression, or null when it is not certain.
	 *
	 * @param Node\Expr $expr Expression.
	 * @return int|null
	 */
	public static function evaluate( Node\Expr $expr ) {
		if ( $expr instanceof Node\Scalar\Int_ ) {
			return (int) $expr->value;
		}

		if ( $expr instanceof Node\Expr\UnaryMinus && $expr->expr instanceof Node\Scalar\Int_ ) {
			return -1 * (int) $expr->expr->value;
		}

		if ( $expr instanceof Node\Expr\ConstFetch ) {
			$constant = $expr->name->getLast();

			if ( 1 !== count( $expr->name->getParts() ) && ! $expr->name->isFullyQualified() ) {
				return null;
			}

			if ( 'true' === strtolower( $constant ) ) {
				return 1;
			}

			if ( 'false' === strtolower( $constant ) ) {
				return 0;
			}

			return isset( self::CONSTANTS[ $constant ] ) ? self::CONSTANTS[ $constant ] : null;
		}

		if ( $expr instanceof Node\Expr\BinaryOp\BitwiseOr ) {
			$left  = self::evaluate( $expr->left );
			$right = self::evaluate( $expr->right );

			return null === $left || null === $right ? null : ( $left | $right );
		}

		return null;
	}
}
