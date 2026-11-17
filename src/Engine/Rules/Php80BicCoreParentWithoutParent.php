<?php
/**
 * "parent" in a class that does not extend another class.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: class.parent_without_parent. Deprecated in 7.4 (raised when the
 * file is compiled), a compile error from 8.0: parent::f(), parent::CONST,
 * new parent() and a parameter or return type "parent" in a class without
 * "extends", anonymous classes included.
 *
 * Not reported in traits (the using class decides), and not in closures, which
 * the runtimes showed compile on 8.0 because they may be bound elsewhere.
 */
final class Php80BicCoreParentWithoutParent implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Name::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Name $node    Name.
	 * @param Context   $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( 'parent' !== $node->toLowerString() ) {
			return;
		}

		$around = $node->getAttribute( 'parent' );

		while ( null !== $around && ! $around instanceof Node\Stmt\ClassLike ) {
			if ( $around instanceof Node\Expr\Closure || $around instanceof Node\Expr\ArrowFunction || $around instanceof Node\Stmt\Function_ ) {
				return;
			}

			$around = $around->getAttribute( 'parent' );
		}

		if ( ! $around instanceof Node\Stmt\Class_ || null !== $around->extends ) {
			return;
		}

		$finding          = $context->report(
			'class.parent_without_parent',
			$node,
			array(
				'deprecated' => '7.4',
				'breaks'     => '8.0',
			)
		);
		$finding->subject = null === $around->name ? 'class@anonymous' : $around->name->toString();
		$finding->instead = 'self, or extend the class that was meant';
	}
}
