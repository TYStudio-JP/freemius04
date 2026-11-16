<?php
/**
 * Functions named with a word that later became a keyword.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\FuncCall;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Stmt\Function_;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: syntax.reserved_name. A function called "match" is a parse error
 * from 8.0, where match became a keyword. Methods may still use the word.
 *
 * Other words that became reserved are not covered yet; each needs its own
 * fixture first.
 */
final class ReservedNames implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Function_::class, FuncCall::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node    $node    Function declaration or call.
	 * @param Context $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( $node instanceof Function_ ) {
			$name = $node->name->toString();
		} elseif ( $node->name instanceof Node\Name ) {
			$name = $node->name->getLast();
		} else {
			return;
		}

		if ( 'match' !== strtolower( $name ) ) {
			return;
		}

		$finding          = $context->report( 'syntax.reserved_name', $node, array( 'breaks' => '8.0' ) );
		$finding->subject = $name;
	}
}
