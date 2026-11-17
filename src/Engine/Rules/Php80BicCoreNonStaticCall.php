<?php
/**
 * Non-static methods called statically, directly or through a callable.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule ids:
 *
 * - call.non_static_method_statically      Greeter::hello() on a non-static
 *                                          method: deprecated since 7.0, an
 *                                          Error from 8.0
 * - callable.non_static_method_statically  call_user_func( array( 'Greeter', 'hello' ) )
 *                                          or 'Greeter::hello': the same, a
 *                                          TypeError from 8.0
 * - call.is_callable_non_static_method     is_callable() of such a callable:
 *                                          true until 7.4, false from 8.0
 *
 * Reported only when the class and the method (in the class or a parent) are
 * declared in the same file, and the call cannot have a $this of that class:
 * at the top of the file, in a function, in a static method, or in an
 * instance method of a class that is known not to descend from it. Calls in
 * closures are left alone, because a closure can be bound to any object.
 */
final class Php80BicCoreNonStaticCall implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Expr\StaticCall::class, Node\Expr\FuncCall::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Expr\StaticCall|Node\Expr\FuncCall $node    Call.
	 * @param Context                                 $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( $node instanceof Node\Expr\StaticCall ) {
			$this->static_call( $node, $context );
		} else {
			$this->callable_argument( $node, $context );
		}
	}

	/**
	 * Greeter::hello().
	 *
	 * @param Node\Expr\StaticCall $node    Call.
	 * @param Context              $context Scan state.
	 */
	private function static_call( Node\Expr\StaticCall $node, Context $context ) {
		if ( ! $node->class instanceof Node\Name || ! $node->name instanceof Node\Identifier ) {
			return;
		}

		$class = Php80BicCoreIndex::class_name( $node->class );

		if ( null === $class || ! $this->is_instance_method( $class, $node->name->toString(), $context ) || ! $this->has_no_this_of( $class, $node, $context ) ) {
			return;
		}

		$finding          = $context->report(
			'call.non_static_method_statically',
			$node,
			array(
				'deprecated' => '7.0',
				'breaks'     => '8.0',
			)
		);
		$finding->subject = $node->class->toString() . '::' . $node->name->toString() . '()';
		$finding->instead = 'call the method on an object, or declare it static';
	}

	/**
	 * call_user_func(), call_user_func_array() and is_callable() with a
	 * literal callable that names a non-static method.
	 *
	 * @param Node\Expr\FuncCall $node    Call.
	 * @param Context            $context Scan state.
	 */
	private function callable_argument( Node\Expr\FuncCall $node, Context $context ) {
		$function = InternalFunctions::global_name( $node->name );

		if ( ! in_array( $function, array( 'call_user_func', 'call_user_func_array', 'is_callable' ), true ) || isset( $context->declared_functions[ $function ] ) ) {
			return;
		}

		if ( ! isset( $node->args[0] ) || ! $node->args[0] instanceof Node\Arg || $node->args[0]->unpack || null !== $node->args[0]->name ) {
			return;
		}

		$target = self::literal_callable( $node->args[0]->value );

		if ( null === $target ) {
			return;
		}

		list( $class, $method, $written ) = $target;

		if ( ! $this->is_instance_method( $class, $method, $context ) || ! $this->has_no_this_of( $class, $node, $context ) ) {
			return;
		}

		if ( 'is_callable' === $function ) {
			$finding = $context->report( 'call.is_callable_non_static_method', $node, array( 'changed' => '8.0' ) );
		} else {
			$finding = $context->report(
				'callable.non_static_method_statically',
				$node->args[0]->value,
				array(
					'deprecated' => '7.0',
					'breaks'     => '8.0',
				)
			);
		}

		$finding->subject = $written . '::' . $method . '()';
		$finding->instead = 'array( $object, \'' . $method . '\' ), or declare the method static';
	}

	/**
	 * Class and method of array( 'Class', 'method' ) or 'Class::method'.
	 *
	 * @param Node\Expr $value Argument.
	 * @return array{0: string, 1: string, 2: string}|null Lower-case class name, method name, class name as written.
	 */
	private static function literal_callable( Node\Expr $value ) {
		if ( $value instanceof Node\Scalar\String_ ) {
			$parts = explode( '::', $value->value );

			if ( 2 !== count( $parts ) ) {
				return null;
			}
		} elseif ( $value instanceof Node\Expr\Array_ && 2 === count( $value->items ) ) {
			$parts = array();

			foreach ( $value->items as $item ) {
				if ( null === $item || null !== $item->key || $item->byRef || $item->unpack || ! $item->value instanceof Node\Scalar\String_ ) {
					return null;
				}

				$parts[] = $item->value->value;
			}
		} else {
			return null;
		}

		$class = ltrim( $parts[0], '\\' );

		if ( ! preg_match( '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff\\\\]*$/', $class ) || ! preg_match( '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $parts[1] ) ) {
			return null;
		}

		if ( in_array( strtolower( $class ), array( 'self', 'parent', 'static' ), true ) ) {
			return null;
		}

		return array( strtolower( $class ), $parts[1], $class );
	}

	/**
	 * Whether the class (declared in this file) has, itself or through
	 * parents declared in this file, a method of that name that is not static.
	 *
	 * @param string  $class   Lower-case, fully qualified class name.
	 * @param string  $method  Method name.
	 * @param Context $context Scan state.
	 * @return bool
	 */
	private function is_instance_method( $class, $method, Context $context ) {
		if ( ! Php80BicCoreIndex::may_declare( $context, $class ) ) {
			return false;
		}

		$index = Php80BicCoreIndex::of( $context );
		$seen  = array();

		while ( isset( $index['classes'][ $class ] ) && ! isset( $seen[ $class ] ) ) {
			$seen[ $class ] = true;
			$declaration    = $index['classes'][ $class ];

			if ( ! $declaration instanceof Node\Stmt\Class_ ) {
				return false;
			}

			$found = Php80BicCoreIndex::method( $declaration, $method );

			if ( null !== $found ) {
				return ! $found->isStatic();
			}

			// A trait could bring the method; it is not looked into.
			if ( array() !== $declaration->getTraitUses() || ! $declaration->extends instanceof Node\Name ) {
				return false;
			}

			$class = Php80BicCoreIndex::class_name( $declaration->extends );
		}

		return false;
	}

	/**
	 * Whether the call certainly runs without a $this that is an instance of
	 * the class.
	 *
	 * @param string  $class   Lower-case, fully qualified class name.
	 * @param Node    $node    Call.
	 * @param Context $context Scan state.
	 * @return bool
	 */
	private function has_no_this_of( $class, Node $node, Context $context ) {
		$around = $node->getAttribute( 'parent' );

		while ( null !== $around && ! $around instanceof Node\FunctionLike ) {
			$around = $around->getAttribute( 'parent' );
		}

		if ( null === $around || $around instanceof Node\Stmt\Function_ ) {
			return true;
		}

		if ( ! $around instanceof Node\Stmt\ClassMethod ) {
			return false;
		}

		if ( $around->isStatic() ) {
			return true;
		}

		$owner = $around->getAttribute( 'parent' );

		if ( ! $owner instanceof Node\Stmt\Class_ || null === $owner->name ) {
			return false;
		}

		$index   = Php80BicCoreIndex::of( $context );
		$current = strtolower( $owner->namespacedName instanceof Node\Name ? $owner->namespacedName->toString() : $owner->name->toString() );
		$seen    = array();

		// Up the chain of the calling class: it must end inside the file
		// without passing the class of the method.
		while ( ! isset( $seen[ $current ] ) ) {
			if ( $current === $class || ! isset( $index['classes'][ $current ] ) ) {
				return false;
			}

			$seen[ $current ] = true;
			$declaration      = $index['classes'][ $current ];

			if ( ! $declaration instanceof Node\Stmt\Class_ ) {
				return false;
			}

			if ( ! $declaration->extends instanceof Node\Name ) {
				return true;
			}

			$current = Php80BicCoreIndex::class_name( $declaration->extends );

			if ( null === $current ) {
				return false;
			}
		}

		return false;
	}
}
