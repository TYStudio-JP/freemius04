<?php
/**
 * Classes that implement Serializable without the newer magic methods.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Stmt\Class_;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: class.serializable_only. Deprecated in 8.1.
 *
 * PHP raises the notice when the class is declared, unless the class is
 * abstract or has both __serialize() and __unserialize(). Those two methods
 * may also come from a parent class or a trait, which this file alone does
 * not show, so a class with "extends" or "use" inside is left alone. A class
 * that gets Serializable through another interface or through its parent is
 * not seen either (fixture runtime.serializable_inherited).
 */
final class Php81DepSerializable implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Class_::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Class_  $node    Class declaration, named or anonymous.
	 * @param Context $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( $node->isAbstract() || null !== $node->extends || count( $node->getTraitUses() ) > 0 ) {
			return;
		}

		$interface = null;

		foreach ( $node->implements as $name ) {
			$resolved = $name->getAttribute( 'resolvedName' );
			$resolved = $resolved instanceof Node\Name ? $resolved : $name;

			if ( 'serializable' === strtolower( $resolved->toString() ) ) {
				$interface = $name;
			}
		}

		if ( null === $interface ) {
			return;
		}

		$methods = array();

		foreach ( $node->getMethods() as $method ) {
			$methods[ strtolower( $method->name->toString() ) ] = true;
		}

		if ( isset( $methods['__serialize'], $methods['__unserialize'] ) ) {
			return;
		}

		$finding          = $context->report( 'class.serializable_only', $interface, array( 'deprecated' => '8.1' ) );
		$finding->subject = null === $node->name ? 'class@anonymous' : $node->name->toString();
		$finding->instead = 'public function __serialize(): array { ... } public function __unserialize( array $data ): void { ... }';

		// A class at the top of a file is declared when the file is compiled.
		// One inside an "if" or a function is declared only when that code runs.
		$parent = $node->getAttribute( 'parent' );

		if ( null !== $parent && ! $parent instanceof Node\Stmt\Namespace_ ) {
			$finding->guarded = $context->function_is_guarded( 'serializable' );
		}
	}
}
