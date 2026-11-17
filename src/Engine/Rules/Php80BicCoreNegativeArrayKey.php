<?php
/**
 * Array literals whose first integer key is negative, followed by items
 * without a key: array( -5 => 'a', 'b' ).
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: array.negative_key_then_implicit. Changed in 8.0: the item without
 * a key gets n+1 after a first key n < 0, where PHP 7.4 gave it 0.
 *
 * The keys of both versions are worked out from the literal, and the array is
 * reported only when they differ. Every key has to be a literal integer (or a
 * string PHP turns into one); any other key, or a spread, and nothing is
 * reported. Appending to an existing array, $a[] = ..., changed only in 8.3
 * and is not part of this rule.
 */
final class Php80BicCoreNegativeArrayKey implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Expr\Array_::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Expr\Array_ $node    Array literal.
	 * @param Context          $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		// A list() or [ $a, $b ] = ... on the left of an assignment is not an array.
		$parent = $node->getAttribute( 'parent' );

		if ( ( $parent instanceof Node\Expr\Assign || $parent instanceof Node\Expr\AssignRef ) && $parent->var === $node ) {
			return;
		}

		if ( $parent instanceof Node\Stmt\Foreach_ && $parent->valueVar === $node ) {
			return;
		}

		$old       = 0;
		$new       = null;
		$implicit  = false;
		$differs   = false;
		$any_minus = false;

		foreach ( $node->items as $item ) {
			if ( null === $item || $item->unpack ) {
				return;
			}

			if ( null === $item->key ) {
				$implicit = true;
				$old_key  = $old;
				$new_key  = null === $new ? 0 : $new;

				if ( $old_key !== $new_key ) {
					$differs = true;
				}

				$old = $old_key + 1;
				$new = $new_key + 1;
				continue;
			}

			$key = self::integer_key( $item->key );

			if ( false === $key ) {
				return;
			}

			if ( null === $key ) {
				continue;
			}

			if ( $key < 0 ) {
				$any_minus = true;
			}

			if ( $key >= $old ) {
				$old = $key + 1;
			}

			if ( null === $new || $key >= $new ) {
				$new = $key + 1;
			}
		}

		if ( ! $implicit || ! $any_minus || ! $differs ) {
			return;
		}

		$finding          = $context->report( 'array.negative_key_then_implicit', $node, array( 'changed' => '8.0' ) );
		$finding->instead = 'write every key out';
	}

	/**
	 * The integer a literal key stands for, null for a string key that stays
	 * a string, or false when the key is not a literal the rule understands.
	 *
	 * @param Node\Expr $key Key expression.
	 * @return int|null|false
	 */
	private static function integer_key( Node\Expr $key ) {
		if ( $key instanceof Node\Scalar\Int_ ) {
			return $key->value;
		}

		if ( $key instanceof Node\Expr\UnaryMinus && $key->expr instanceof Node\Scalar\Int_ ) {
			return -$key->expr->value;
		}

		if ( $key instanceof Node\Scalar\String_ ) {
			// PHP turns a canonical decimal integer string into an integer key.
			if ( preg_match( '/^(0|-?[1-9][0-9]{0,17})$/', $key->value ) ) {
				return (int) $key->value;
			}

			return null;
		}

		return false;
	}
}
