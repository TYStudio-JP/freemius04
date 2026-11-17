<?php
/**
 * A method that overrides a parent method with a parameter list the parent's
 * callers cannot use (an LSP violation).
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: class.incompatible_method_signature. PHP 7.4 only warned
 * ("should be compatible") when a method overrode a concrete parent method
 * incompatibly; from 8.0 it is a fatal error when the class is declared.
 *
 * Judged on the parameter list only: more required parameters than the
 * parent, fewer parameters, a dropped variadic, or a different by-reference
 * flag. Types are not compared. Reported only when the parent chain up to the
 * overridden method is in the same file, uses no traits and implements no
 * interfaces, and the overridden method is neither private, abstract (already
 * fatal on 7.4) nor a constructor (never checked).
 */
final class Php80BicCoreIncompatibleOverride implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Stmt\Class_::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Stmt\Class_ $node    Class.
	 * @param Context          $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( ! $node->extends instanceof Node\Name || ! Php80BicCoreIndex::may_declare( $context, $node->extends->toString() ) ) {
			return;
		}

		foreach ( $node->getMethods() as $method ) {
			$name = $method->name->toLowerString();

			if ( '__construct' === $name || $method->isPrivate() ) {
				continue;
			}

			$base = $this->overridden( $node, $name, $context );

			if ( null === $base || $base->isStatic() !== $method->isStatic() ) {
				continue;
			}

			$conflict = Php80BicCoreIndex::arity_conflict( $base, $method );

			if ( null === $conflict ) {
				continue;
			}

			$finding          = $context->report( 'class.incompatible_method_signature', $method->name, array( 'breaks' => '8.0' ) );
			$finding->subject = $method->name->toString() . '(): ' . $conflict;
			$finding->instead = 'the parameter list of the parent method, with extra parameters optional';
		}
	}

	/**
	 * The concrete, non-private method of that name that the class overrides,
	 * when the whole chain up to it is known and plain.
	 *
	 * @param Node\Stmt\Class_ $class   Overriding class.
	 * @param string           $name    Lower-case method name.
	 * @param Context          $context Scan state.
	 * @return Node\Stmt\ClassMethod|null
	 */
	private function overridden( Node\Stmt\Class_ $class, $name, Context $context ) {
		$index = Php80BicCoreIndex::of( $context );
		$next  = Php80BicCoreIndex::class_name( $class->extends );
		$seen  = array();

		while ( null !== $next && isset( $index['classes'][ $next ] ) && ! isset( $seen[ $next ] ) ) {
			$seen[ $next ] = true;
			$ancestor      = $index['classes'][ $next ];

			if ( ! $ancestor instanceof Node\Stmt\Class_ || array() !== $ancestor->implements || array() !== $ancestor->getTraitUses() ) {
				return null;
			}

			$method = Php80BicCoreIndex::method( $ancestor, $name );

			if ( null !== $method ) {
				return $method->isPrivate() || $method->isAbstract() ? null : $method;
			}

			$next = $ancestor->extends instanceof Node\Name ? Php80BicCoreIndex::class_name( $ancestor->extends ) : null;
		}

		return null;
	}
}
