<?php
/**
 * A trait alias without the trait name, for a method that two used traits have.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: class.ambiguous_trait_alias. A compile error from 8.0; PHP 7.4
 * silently took the method of the first trait.
 *
 *     use T1, T2 { func as otherFunc; }
 *
 * The runtimes show the error even when an insteadof rule settles which func
 * the class gets, and for an alias that only changes the visibility
 * (func as protected). Reported when at least two traits used by the class
 * and declared in the same file have the method; traits from other files are
 * not looked into.
 */
final class Php80BicCoreAmbiguousTraitAlias implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Stmt\Class_::class, Node\Stmt\Trait_::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Stmt\ClassLike $node    Class or trait.
	 * @param Context             $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		$uses = $node->getTraitUses();

		if ( array() === $uses ) {
			return;
		}

		$traits = array();

		foreach ( $uses as $use ) {
			foreach ( $use->traits as $trait ) {
				$name = Php80BicCoreIndex::class_name( $trait );

				if ( null !== $name ) {
					$traits[ $name ] = $trait->toString();
				}
			}
		}

		if ( count( $traits ) < 2 ) {
			return;
		}

		$index = Php80BicCoreIndex::of( $context );

		foreach ( $uses as $use ) {
			foreach ( $use->adaptations as $adaptation ) {
				if ( ! $adaptation instanceof Node\Stmt\TraitUseAdaptation\Alias || null !== $adaptation->trait ) {
					continue;
				}

				$having = array();

				foreach ( $traits as $lower => $written ) {
					if ( isset( $index['classes'][ $lower ] ) && $index['classes'][ $lower ] instanceof Node\Stmt\Trait_ && null !== Php80BicCoreIndex::method( $index['classes'][ $lower ], $adaptation->method->toString() ) ) {
						$having[] = $written;
					}
				}

				if ( count( $having ) < 2 ) {
					continue;
				}

				$finding          = $context->report( 'class.ambiguous_trait_alias', $adaptation, array( 'breaks' => '8.0' ) );
				$finding->subject = $adaptation->method->toString() . '() of ' . implode( ' and ', $having );
				$finding->instead = $having[0] . '::' . $adaptation->method->toString() . ' as ...';
			}
		}
	}
}
