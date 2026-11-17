<?php
/**
 * "." followed by an unparenthesized +, -, << or >>: "Sum: " . $a + $b.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\BinaryOp;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: syntax.concat_precedence. Deprecated in 7.4 (raised when the file
 * is compiled), changed in 8.0: "." used to bind as tightly as +, - and the
 * shifts, so $a . $b + $c was ( $a . $b ) + $c; from 8.0 it is
 * $a . ( $b + $c ).
 *
 * Only the order "." first, then the arithmetic, is affected; $a + $b . $c
 * means the same on every version. Both the PHP 8 tree (a Concat whose right
 * side is the arithmetic) and the PHP 7.4 tree (arithmetic whose left side is
 * the Concat) are recognised, each only without parentheses.
 */
final class Php80BicCoreConcatPrecedence implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array(
			BinaryOp\Concat::class,
			BinaryOp\Plus::class,
			BinaryOp\Minus::class,
			BinaryOp\ShiftLeft::class,
			BinaryOp\ShiftRight::class,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param BinaryOp $node    Operation.
	 * @param Context  $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( $node instanceof BinaryOp\Concat ) {
			$right = $node->right;

			if ( ! self::is_arithmetic( $right ) ) {
				return;
			}

			// Parenthesized, the arithmetic starts after the "(" that follows the ".".
			$between = $context->text( $node->left->getEndFilePos() + 1, $right->getStartFilePos() - 1 );

			if ( false !== strpos( $between, '(' ) ) {
				return;
			}
		} else {
			// The PHP 7.4 grammar: ( $a . $b ) + $c, the Concat starting where the sum starts.
			if ( ! $node->left instanceof BinaryOp\Concat || $node->left->getStartFilePos() !== $node->getStartFilePos() ) {
				return;
			}

			$parent = $node->getAttribute( 'parent' );

			if ( $parent instanceof BinaryOp\Concat && $parent->right === $node ) {
				return;
			}
		}

		$finding          = $context->report(
			'syntax.concat_precedence',
			$node,
			array(
				'deprecated' => '7.4',
				'changed'    => '8.0',
			)
		);
		$finding->instead = 'parentheses around the part that is meant to be computed first';
	}

	/**
	 * Whether the node is +, -, << or >>.
	 *
	 * @param Node $node Node.
	 * @return bool
	 */
	private static function is_arithmetic( Node $node ) {
		return $node instanceof BinaryOp\Plus
			|| $node instanceof BinaryOp\Minus
			|| $node instanceof BinaryOp\ShiftLeft
			|| $node instanceof BinaryOp\ShiftRight;
	}
}
