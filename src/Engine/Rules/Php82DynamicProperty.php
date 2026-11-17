<?php
/**
 * Properties that a class creates on itself without declaring them.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Stmt;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: property.dynamic_on_this. Deprecated in 8.2.
 *
 *     class Counter {
 *         public function start() {
 *             $this->count = 1;   // $count is declared nowhere
 *         }
 *     }
 *
 * Which class an object has is known at run time only, with one exception:
 * $this inside a method. So only "$this->name = ..." is looked at (also with
 * [...] behind the name, with =&, with ??= and the other compound assignments
 * such as += and .=, and with ++ and --; fixtures ~8.2-compound_assign and
 * ~8.2-increment), and only when this file shows
 * everything that could declare the property or allow dynamic ones:
 *
 * - the class extends nothing and uses no trait (either could declare it,
 *   define __set() or carry the attribute),
 * - the class is not abstract (a child class could declare it; fixture
 *   runtime.dynamic_property_declared_by_child),
 * - the class has no __set() (fixture runtime.dynamic_property_magic_set),
 * - the class does not carry #[AllowDynamicProperties],
 * - the class is not readonly (there the assignment is an Error, not a
 *   deprecation, and the class needs PHP 8.2 anyway),
 * - the assignment is directly in a non-static method, not in a closure,
 *   which may be bound to another object.
 *
 * What is left out is recorded as run-time behaviour: a property set on any
 * other object (fixture runtime.dynamic_property_other_object).
 *
 * A child class that declares the property or carries the attribute would
 * still silence the notice for its own objects; objects of the class itself
 * raise it.
 *
 * One finding per class and property: PHP raises the notice where the
 * property is created, and the first assignment in the file stands for it.
 */
final class Php82DynamicProperty implements Rule {

	/**
	 * Scan state of the file being walked; the two caches below belong to it.
	 *
	 * @var Context|null
	 */
	private $context;

	/**
	 * What is known about the classes of this file, by spl_object_id().
	 *
	 * @var array<int,array|false> False for a class that is left alone.
	 */
	private $classes = array();

	/**
	 * Class and property pairs already reported in this file.
	 *
	 * @var array<string,true>
	 */
	private $reported = array();

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array(
			Expr\Assign::class,
			Expr\AssignRef::class,
			Expr\AssignOp\BitwiseAnd::class,
			Expr\AssignOp\BitwiseOr::class,
			Expr\AssignOp\BitwiseXor::class,
			Expr\AssignOp\Coalesce::class,
			Expr\AssignOp\Concat::class,
			Expr\AssignOp\Div::class,
			Expr\AssignOp\Minus::class,
			Expr\AssignOp\Mod::class,
			Expr\AssignOp\Mul::class,
			Expr\AssignOp\Plus::class,
			Expr\AssignOp\Pow::class,
			Expr\AssignOp\ShiftLeft::class,
			Expr\AssignOp\ShiftRight::class,
			Expr\PreInc::class,
			Expr\PreDec::class,
			Expr\PostInc::class,
			Expr\PostDec::class,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Expr    $node    Assignment.
	 * @param Context $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		$target = $node->var;

		while ( $target instanceof Expr\ArrayDimFetch ) {
			$target = $target->var;
		}

		if ( ! $target instanceof Expr\PropertyFetch || ! $target->name instanceof Node\Identifier ) {
			return;
		}

		if ( ! $target->var instanceof Expr\Variable || 'this' !== $target->var->name ) {
			return;
		}

		$class = self::class_of( $node );

		if ( null === $class ) {
			return;
		}

		if ( $this->context !== $context ) {
			$this->context  = $context;
			$this->classes  = array();
			$this->reported = array();
		}

		$id = spl_object_id( $class );

		if ( ! isset( $this->classes[ $id ] ) ) {
			$this->classes[ $id ] = self::declared_in( $class );
		}

		$name = $target->name->toString();

		if ( false === $this->classes[ $id ] || isset( $this->classes[ $id ][ $name ] ) || isset( $this->reported[ $id . ' ' . $name ] ) ) {
			return;
		}

		$this->reported[ $id . ' ' . $name ] = true;

		$finding          = $context->report( 'property.dynamic_on_this', $target, array( 'deprecated' => '8.2' ) );
		$finding->subject = ( null === $class->name ? 'class@anonymous' : $class->name->toString() ) . '::$' . $name;
		$finding->instead = 'Declare it in the class: public $' . $name . ';';
		$finding->guarded = $context->function_is_guarded( Php82TentativeReturnType::VERSION_GUARD );
	}

	/**
	 * The class whose non-static method directly contains the node, or null.
	 *
	 * @param Node $node Node inside a method.
	 * @return Stmt\Class_|null
	 */
	private static function class_of( Node $node ) {
		$parent = $node->getAttribute( 'parent' );

		while ( $parent instanceof Node ) {
			if ( $parent instanceof Expr\Closure || $parent instanceof Expr\ArrowFunction || $parent instanceof Stmt\Function_ ) {
				return null;
			}

			if ( $parent instanceof Stmt\ClassMethod ) {
				$class = $parent->getAttribute( 'parent' );

				return ! $parent->isStatic() && $class instanceof Stmt\Class_ ? $class : null;
			}

			$parent = $parent->getAttribute( 'parent' );
		}

		return null;
	}

	/**
	 * Names of the instance properties a class declares, or false when the
	 * class cannot be judged from this file alone.
	 *
	 * @param Stmt\Class_ $class Class declaration.
	 * @return array<string,true>|false
	 */
	private static function declared_in( Stmt\Class_ $class ) {
		if ( null !== $class->extends || $class->isAbstract() || $class->isReadonly() || count( $class->getTraitUses() ) > 0 ) {
			return false;
		}

		foreach ( $class->attrGroups as $group ) {
			foreach ( $group->attrs as $attribute ) {
				if ( 'allowdynamicproperties' === strtolower( $attribute->name->getLast() ) ) {
					return false;
				}
			}
		}

		$declared = array();

		foreach ( $class->getProperties() as $property ) {
			// A static property of the same name declares nothing for objects
			// (fixture property.dynamic_on_this~8.2-static_declared).
			if ( $property->isStatic() ) {
				continue;
			}

			foreach ( $property->props as $item ) {
				$declared[ $item->name->toString() ] = true;
			}
		}

		foreach ( $class->getMethods() as $method ) {
			$name = strtolower( $method->name->toString() );

			if ( '__set' === $name ) {
				return false;
			}

			if ( '__construct' !== $name ) {
				continue;
			}

			// Promoted constructor parameters are declarations.
			foreach ( $method->params as $param ) {
				if ( 0 !== $param->flags && $param->var instanceof Expr\Variable && is_string( $param->var->name ) ) {
					$declared[ $param->var->name ] = true;
				}
			}
		}

		return $declared;
	}
}
