<?php
/**
 * A trait with a static property, used by a class whose parent already has
 * that static property: separate storage from PHP 8.3.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: class.trait_static_property_redeclared. Changed in 8.3.
 *
 * Until 8.2 the child class shared the static property with its parent; from
 * 8.3 the trait gives the child a property of its own, so a value written
 * through one class is no longer seen through the other.
 *
 * Reported only when everything can be read in one file: the trait, the class
 * that uses it, and the parent chain up to the class that has the property
 * (declared there, or through a trait that is in the file too). A parent or a
 * trait from another file is unknown, and then nothing is reported.
 */
final class Php83TraitStaticProperty implements Rule {

	/**
	 * The file the collected declarations belong to.
	 *
	 * @var Context|null
	 */
	private $context;

	/**
	 * Static properties of the traits seen: trait => ( property => is private ).
	 *
	 * @var array<string,array<string,bool>>
	 */
	private $traits = array();

	/**
	 * Classes seen, by lower-case name.
	 *
	 * @var array<string,array>
	 */
	private $classes = array();

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array(
			Node\Stmt\Class_::class,
			Node\Stmt\Trait_::class,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Stmt\ClassLike $node    Class or trait.
	 * @param Context             $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( $this->context !== $context ) {
			$this->context = $context;
			$this->traits  = array();
			$this->classes = array();
		}

		if ( null === $node->name ) {
			return;
		}

		$namespaced = $node->namespacedName;
		$name       = strtolower( $namespaced instanceof Node\Name ? $namespaced->toString() : $node->name->toString() );
		$statics    = array();

		foreach ( $node->getProperties() as $property ) {
			if ( ! $property->isStatic() ) {
				continue;
			}

			foreach ( $property->props as $item ) {
				$statics[ $item->name->toString() ] = $property->isPrivate();
			}
		}

		if ( $node instanceof Node\Stmt\Trait_ ) {
			$this->traits[ $name ] = $statics;
		} else {
			$uses = array();

			foreach ( $node->getTraitUses() as $use ) {
				foreach ( $use->traits as $trait ) {
					$uses[ self::class_name( $trait ) ] = $use;
				}
			}

			$this->classes[ $name ] = array(
				'parent'   => $node->extends instanceof Node\Name ? self::class_name( $node->extends ) : null,
				'uses'     => $uses,
				'statics'  => $statics,
				'reported' => false,
			);
		}

		// Declarations may come in any order, so every class is looked at again
		// each time something new is known.
		foreach ( array_keys( $this->classes ) as $class ) {
			if ( ! $this->classes[ $class ]['reported'] ) {
				$this->check( $class, $context );
			}
		}
	}

	/**
	 * Reports the class when a static property it gets from a trait is also
	 * inherited from a parent class.
	 *
	 * @param string  $class   Lower-case class name.
	 * @param Context $context Scan state.
	 */
	private function check( $class, Context $context ) {
		$own = $this->classes[ $class ];

		foreach ( $own['uses'] as $trait => $use ) {
			if ( ! isset( $this->traits[ $trait ] ) ) {
				continue;
			}

			foreach ( array_keys( $this->traits[ $trait ] ) as $property ) {
				// A property the class declares itself as well was its own already.
				if ( isset( $own['statics'][ $property ] ) || ! $this->inherited( $own['parent'], $property ) ) {
					continue;
				}

				$this->classes[ $class ]['reported'] = true;

				$finding          = $context->report( 'class.trait_static_property_redeclared', $use, array( 'changed' => '8.3' ) );
				$finding->subject = 'static $' . $property;
				$finding->instead = 'read and write the property through one class only, for example the parent, where the value has to be shared';

				return;
			}
		}
	}

	/**
	 * Whether a class in this file, or one of its parents in this file, has
	 * the static property and passes it on.
	 *
	 * @param string|null $class    Lower-case class name.
	 * @param string      $property Property name.
	 * @return bool
	 */
	private function inherited( $class, $property ) {
		$seen = array();

		while ( null !== $class && isset( $this->classes[ $class ] ) && ! isset( $seen[ $class ] ) ) {
			$seen[ $class ] = true;
			$entry          = $this->classes[ $class ];

			if ( isset( $entry['statics'][ $property ] ) ) {
				return ! $entry['statics'][ $property ];
			}

			foreach ( array_keys( $entry['uses'] ) as $trait ) {
				if ( isset( $this->traits[ $trait ][ $property ] ) ) {
					return ! $this->traits[ $trait ][ $property ];
				}
			}

			$class = $entry['parent'];
		}

		return false;
	}

	/**
	 * Lower-case, fully qualified name a class reference stands for.
	 *
	 * @param Node\Name $name Name as written.
	 * @return string
	 */
	private static function class_name( Node\Name $name ) {
		$resolved = $name->getAttribute( 'resolvedName' );

		return strtolower( $resolved instanceof Node\Name ? $resolved->toString() : $name->toString() );
	}
}
