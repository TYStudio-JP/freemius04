<?php
/**
 * A class method that does not match the abstract method of a trait it uses.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: class.abstract_trait_method_mismatch. From 8.0 an abstract method
 * of a trait is checked against the method of the class that uses the trait;
 * PHP 7.4 accepted any signature without a word. A compile error from 8.0.
 *
 * Reported when the trait is declared in the same file, its method is
 * abstract and not private (a private abstract method was a compile error on
 * 7.4 already), the class declares the method itself, and either the
 * parameter list conflicts (see Php80BicCoreIndex::arity_conflict()) or both
 * declare different built-in scalar return types (int and string, say).
 */
final class Php80BicCoreAbstractTraitMethod implements Rule {

	/**
	 * Return types that never accept one another.
	 *
	 * @var string[]
	 */
	const SCALARS = array( 'int', 'string', 'bool', 'array', 'void' );

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
		$uses = $node->getTraitUses();

		if ( array() === $uses ) {
			return;
		}

		$index = Php80BicCoreIndex::of( $context );

		foreach ( $uses as $use ) {
			foreach ( $use->traits as $trait_name ) {
				$lower = Php80BicCoreIndex::class_name( $trait_name );

				if ( null === $lower || ! isset( $index['classes'][ $lower ] ) || ! $index['classes'][ $lower ] instanceof Node\Stmt\Trait_ ) {
					continue;
				}

				foreach ( $index['classes'][ $lower ]->getMethods() as $abstract ) {
					if ( ! $abstract->isAbstract() || $abstract->isPrivate() ) {
						continue;
					}

					$method = $node->getMethod( $abstract->name->toLowerString() );

					if ( null === $method || $method->isAbstract() || $method->isStatic() !== $abstract->isStatic() ) {
						continue;
					}

					$conflict = Php80BicCoreIndex::arity_conflict( $abstract, $method );

					if ( null === $conflict ) {
						$conflict = self::return_conflict( $abstract, $method );
					}

					if ( null === $conflict ) {
						continue;
					}

					$finding          = $context->report( 'class.abstract_trait_method_mismatch', $method->name, array( 'breaks' => '8.0' ) );
					$finding->subject = $method->name->toString() . '() for ' . $trait_name->toString() . ': ' . $conflict;
					$finding->instead = 'the signature of the abstract method in the trait';
				}
			}
		}
	}

	/**
	 * A description of two return types that cannot match, or null.
	 *
	 * @param Node\Stmt\ClassMethod $abstract Abstract trait method.
	 * @param Node\Stmt\ClassMethod $method   Class method.
	 * @return string|null
	 */
	private static function return_conflict( Node\Stmt\ClassMethod $abstract, Node\Stmt\ClassMethod $method ) {
		if ( ! $abstract->returnType instanceof Node\Identifier || ! $method->returnType instanceof Node\Identifier ) {
			return null;
		}

		$want = $abstract->returnType->toLowerString();
		$have = $method->returnType->toLowerString();

		if ( $want === $have || ! in_array( $want, self::SCALARS, true ) || ! in_array( $have, self::SCALARS, true ) ) {
			return null;
		}

		return 'returns ' . $have . ' where the trait says ' . $want;
	}
}
