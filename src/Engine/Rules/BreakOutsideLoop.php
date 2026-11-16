<?php
/**
 * "break" and "continue" with no loop or switch around them.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Stmt;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: syntax.break_outside_loop. A compile error: the whole file fails
 * to load, even when the statement would never be reached. Old code can carry
 * such a statement in a dead branch for years.
 *
 * The version given is 7.4, the oldest runtime the fixture ran on, where it
 * already fails. It very likely fails on earlier 7.x versions too, but the
 * official upgrade notes do not say since when, so no earlier version is
 * claimed. For a site on 7.4 or later the answer is the same either way.
 *
 * Also covers "break 2" and "continue 2" with fewer loops around them than
 * the number asks for.
 */
final class BreakOutsideLoop implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Stmt\Break_::class, Stmt\Continue_::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Stmt\Break_|Stmt\Continue_ $node    Statement.
	 * @param Context                    $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		$levels = 1;

		if ( null !== $node->num ) {
			if ( ! $node->num instanceof Node\Scalar\Int_ ) {
				return;
			}

			$levels = $node->num->value;
		}

		if ( $levels <= $context->loop_depth ) {
			return;
		}

		$finding          = $context->report( 'syntax.break_outside_loop', $node, array( 'breaks' => '7.4' ) );
		$finding->subject = $node instanceof Stmt\Break_ ? 'break' : 'continue';
	}
}
