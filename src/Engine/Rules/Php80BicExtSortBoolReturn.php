<?php
/**
 * Sort callbacks that return a comparison, true or false, instead of a
 * number (tranche 8.0-bic-ext).
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\BinaryOp;
use TYStudio\UpgradeWarden\Vendor\PhpParser\NodeFinder;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: callable.sort_bool_return. Deprecated in 8.0.
 *
 *     usort( $list, function ( $a, $b ) { return $a > $b; } );
 *
 * From 8.0 a comparison function that returns a bool raises "Returning bool
 * from comparison function is deprecated" (PHP still sorts as before by
 * calling it again with the arguments swapped).
 *
 * Reported: a closure or arrow function written directly as the callback of
 * usort(), uasort() or uksort(), when every value it returns is a comparison
 * (> < >= <= == != === !==). A named function or a method given as a string
 * or an array is not looked into.
 */
final class Php80BicExtSortBoolReturn implements Rule {

	/**
	 * Sort functions and the position of their callback.
	 */
	const SORTS = array(
		'usort'  => 1,
		'uasort' => 1,
		'uksort' => 1,
	);

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Expr\FuncCall::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Expr\FuncCall $node    Function call.
	 * @param Context       $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		$name = InternalFunctions::global_name( $node->name );

		if ( null === $name || ! isset( self::SORTS[ $name ] ) || isset( $context->declared_functions[ $name ] ) ) {
			return;
		}

		$position = self::SORTS[ $name ];

		if ( ! isset( $node->args[ $position ] ) || ! $node->args[ $position ] instanceof Node\Arg || $node->args[ $position ]->unpack ) {
			return;
		}

		foreach ( $node->args as $arg ) {
			if ( ! $arg instanceof Node\Arg || null !== $arg->name ) {
				return;
			}
		}

		$callback = $node->args[ $position ]->value;

		if ( $callback instanceof Expr\ArrowFunction ) {
			if ( ! self::is_comparison( $callback->expr ) ) {
				return;
			}
		} elseif ( $callback instanceof Expr\Closure ) {
			if ( ! self::returns_only_comparisons( $callback ) ) {
				return;
			}
		} else {
			return;
		}

		$finding          = $context->report( 'callable.sort_bool_return', $callback, array( 'deprecated' => '8.0' ) );
		$finding->subject = $name . '() callback returning a bool';
		$finding->instead = '$a <=> $b';
	}

	/**
	 * Whether every return statement of the closure itself returns a
	 * comparison, and there is at least one.
	 *
	 * @param Expr\Closure $closure Closure.
	 * @return bool
	 */
	private static function returns_only_comparisons( Expr\Closure $closure ) {
		$finder  = new NodeFinder();
		$returns = 0;

		foreach ( $finder->findInstanceOf( $closure->stmts, Node\Stmt\Return_::class ) as $return ) {
			$owner = $return->getAttribute( 'parent' );

			while ( null !== $owner && ! $owner instanceof Node\FunctionLike ) {
				$owner = $owner->getAttribute( 'parent' );
			}

			if ( $owner !== $closure ) {
				continue;
			}

			if ( null === $return->expr || ! self::is_comparison( $return->expr ) ) {
				return false;
			}

			$returns++;
		}

		return $returns > 0;
	}

	/**
	 * Whether the expression is a comparison, which gives a bool.
	 *
	 * @param Expr $expr Expression.
	 * @return bool
	 */
	private static function is_comparison( Expr $expr ) {
		return $expr instanceof BinaryOp\Greater
			|| $expr instanceof BinaryOp\GreaterOrEqual
			|| $expr instanceof BinaryOp\Smaller
			|| $expr instanceof BinaryOp\SmallerOrEqual
			|| $expr instanceof BinaryOp\Equal
			|| $expr instanceof BinaryOp\NotEqual
			|| $expr instanceof BinaryOp\Identical
			|| $expr instanceof BinaryOp\NotIdentical;
	}
}
