<?php
/**
 * A class that declares a constant of an interface it implements as protected
 * or private: accepted by PHP 8.1 and 8.2, a compile error from 8.3.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: class.interface_constant_visibility. Breaks in 8.3.
 *
 * Interface constants are public. From 8.1 a class may override them, and
 * until 8.2 PHP did not check that the override stays public; 8.3 does
 * ("Access level to C::X must be public (as in interface I)"). Before 8.1 any
 * override was an error already.
 *
 * Reported only when the interface is declared in the same file, implemented
 * by the class directly or through interfaces that extend it, all in the file.
 * An interface from elsewhere is unknown, and then nothing is reported.
 */
final class Php83InterfaceConstantVisibility implements Rule {

	/**
	 * The scan the declarations below belong to.
	 *
	 * @var Context|null
	 */
	private $context;

	/**
	 * Interfaces seen: lower-case name => array( 'constants' => name => true, 'extends' => names ).
	 *
	 * @var array<string,array>
	 */
	private $interfaces = array();

	/**
	 * Classes and enums seen and not settled yet: each with the interfaces it
	 * implements and its non-public constants (name => ClassConst node).
	 *
	 * @var array[]
	 */
	private $pending = array();

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array(
			Node\Stmt\Interface_::class,
			Node\Stmt\Class_::class,
			Node\Stmt\Enum_::class,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Stmt\ClassLike $node    Interface, class or enum.
	 * @param Context             $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( $this->context !== $context ) {
			$this->context    = $context;
			$this->interfaces = array();
			$this->pending    = array();
		}

		if ( $node instanceof Node\Stmt\Interface_ ) {
			if ( null === $node->name ) {
				return;
			}

			$constants = array();

			foreach ( $node->getConstants() as $declaration ) {
				foreach ( $declaration->consts as $constant ) {
					$constants[ $constant->name->toString() ] = true;
				}
			}

			$extends = array();

			foreach ( $node->extends as $name ) {
				$extends[] = self::class_name( $name );
			}

			$namespaced = $node->namespacedName;

			$this->interfaces[ strtolower( $namespaced instanceof Node\Name ? $namespaced->toString() : $node->name->toString() ) ] = array(
				'constants' => $constants,
				'extends'   => $extends,
			);
		} else {
			$narrowed = array();

			foreach ( $node->getConstants() as $declaration ) {
				if ( $declaration->isPublic() ) {
					continue;
				}

				foreach ( $declaration->consts as $constant ) {
					$narrowed[ $constant->name->toString() ] = $declaration;
				}
			}

			if ( array() === $narrowed || array() === $node->implements ) {
				return;
			}

			$implements = array();

			foreach ( $node->implements as $name ) {
				$implements[] = self::class_name( $name );
			}

			$this->pending[] = array(
				'implements' => $implements,
				'narrowed'   => $narrowed,
			);
		}

		// An interface may be declared below the class that implements it, so
		// every pending class is looked at again when something new is known.
		foreach ( $this->pending as $index => $class ) {
			foreach ( $class['narrowed'] as $constant => $declaration ) {
				$interface = $this->declaring_interface( $class['implements'], $constant );

				if ( null === $interface ) {
					continue;
				}

				$finding          = $context->report( 'class.interface_constant_visibility', $declaration, array( 'breaks' => '8.3' ) );
				$finding->subject = $constant;
				$finding->instead = 'declare the constant public, as in the interface ' . $interface;

				unset( $this->pending[ $index ]['narrowed'][ $constant ] );
			}

			if ( array() === $this->pending[ $index ]['narrowed'] ) {
				unset( $this->pending[ $index ] );
			}
		}
	}

	/**
	 * The interface, among the given ones and those they extend, that declares
	 * the constant; null when none known in this file does.
	 *
	 * @param string[] $names    Lower-case interface names.
	 * @param string   $constant Constant name.
	 * @return string|null
	 */
	private function declaring_interface( array $names, $constant ) {
		$seen = array();

		while ( array() !== $names ) {
			$name = array_pop( $names );

			if ( isset( $seen[ $name ] ) || ! isset( $this->interfaces[ $name ] ) ) {
				continue;
			}

			$seen[ $name ] = true;

			if ( isset( $this->interfaces[ $name ]['constants'][ $constant ] ) ) {
				return $name;
			}

			foreach ( $this->interfaces[ $name ]['extends'] as $parent ) {
				$names[] = $parent;
			}
		}

		return null;
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
