<?php
/**
 * #[\Attribute] on a class that cannot be instantiated.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Stmt;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id (tranche 8.5-bic): class.attribute_on_non_instantiable. Breaks in 8.5.
 *
 *     #[Attribute]
 *     abstract class Base_Rule {}
 *
 * PHP 8.0 to 8.4 accepted #[\Attribute] on an abstract class, an interface, a
 * trait or an enum (and 7.4 reads the line as a comment). 8.5 refuses the
 * declaration at compile time: "Cannot apply #[\Attribute] to abstract class
 * Base_Rule" (fixtures class.attribute_on_non_instantiable~8.5-bic-*; the enum
 * case was seen on 8.4 and 8.5 through eval(), since an enum does not parse
 * before 8.1).
 *
 * Not reported: a declaration that also carries #[\DelayedTargetValidation],
 * which 8.5 accepts, and an attribute whose name resolves to a class other
 * than the global Attribute (for example Foo\Attribute inside namespace Foo).
 * A compile error cannot be kept away by a function_exists() check; only a
 * version check around a conditional declaration counts as a guard.
 */
final class Php85BicAttributeTarget implements Rule {

	const VERSION_GUARD = '8.5-bic: version check only';

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array(
			Stmt\Class_::class,
			Stmt\Interface_::class,
			Stmt\Trait_::class,
			Stmt\Enum_::class,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Stmt\ClassLike $node    Class, interface, trait or enum.
	 * @param Context        $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( $node instanceof Stmt\Class_ && ! $node->isAbstract() ) {
			return;
		}

		$attribute = null;

		foreach ( $node->attrGroups as $group ) {
			foreach ( $group->attrs as $attr ) {
				$name = self::resolved( $attr->name );

				if ( 'delayedtargetvalidation' === $name ) {
					return;
				}

				if ( 'attribute' === $name ) {
					$attribute = $attr;
				}
			}
		}

		if ( null === $attribute ) {
			return;
		}

		if ( $node instanceof Stmt\Interface_ ) {
			$kind = 'interface';
		} elseif ( $node instanceof Stmt\Trait_ ) {
			$kind = 'trait';
		} elseif ( $node instanceof Stmt\Enum_ ) {
			$kind = 'enum';
		} else {
			$kind = 'abstract class';
		}

		$finding          = $context->report( 'class.attribute_on_non_instantiable', $attribute, array( 'breaks' => '8.5' ) );
		$finding->subject = '#[\\Attribute] on ' . $kind . ( null !== $node->name ? ' ' . $node->name->toString() : '' );
		$finding->instead = 'remove #[\\Attribute], put it on a concrete class, or add #[\\DelayedTargetValidation] (PHP 8.5)';

		// A declaration at the top of a file is compiled with the file; one
		// inside an "if" or a function only when that code runs.
		$around = $node->getAttribute( 'parent' );

		if ( null !== $around && ! $around instanceof Stmt\Namespace_ ) {
			$finding->guarded = $context->function_is_guarded( self::VERSION_GUARD );
		}
	}

	/**
	 * Lower-case fully qualified name of an attribute, without the leading
	 * backslash.
	 *
	 * @param Node\Name $name Attribute name.
	 * @return string
	 */
	private static function resolved( Node\Name $name ) {
		$resolved = $name->getAttribute( 'resolvedName' );
		$resolved = $resolved instanceof Node\Name ? $resolved : $name;

		return strtolower( ltrim( $resolved->toString(), '\\' ) );
	}
}
