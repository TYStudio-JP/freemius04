<?php
/**
 * Chained ternaries without parentheses: $a ? 1 : $b ? 2 : 3.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\Ternary;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: syntax.nested_ternary. Deprecated in 7.4, a compile error from 8.0.
 *
 * PHP read such a chain from the left: ( $a ? 1 : $b ) ? 2 : 3. The fix writes
 * those parentheses out, which keeps the result exactly as it was.
 */
final class NestedTernary implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Ternary::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Ternary $node    Outer ternary.
	 * @param Context $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		$inner = $node->cond;

		if ( ! $inner instanceof Ternary ) {
			return;
		}

		// With parentheses around the inner ternary, the outer one starts at "(".
		if ( $inner->getStartFilePos() !== $node->getStartFilePos() ) {
			return;
		}

		// A chain of short ternaries, $a ?: $b ?: $c, is still allowed.
		if ( null === $node->if && null === $inner->if ) {
			return;
		}

		$finding = $context->report(
			'syntax.nested_ternary',
			$node,
			array(
				'deprecated' => '7.4',
				'breaks'     => '8.0',
			)
		);

		$finding->fix = array(
			'kind'  => 'wrap',
			'start' => $inner->getStartFilePos(),
			'end'   => $inner->getEndFilePos(),
		);
	}
}
