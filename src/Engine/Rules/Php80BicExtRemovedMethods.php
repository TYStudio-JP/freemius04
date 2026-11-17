<?php
/**
 * Calls to methods that PHP 8.0 removed from Reflection, SplFileObject and
 * SplFixedArray (tranche 8.0-bic-ext).
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr;

defined( 'ABSPATH' ) || exit;

/**
 * Rule ids:
 *
 * - call.reflection_export            ReflectionClass::export( ... ) and the other
 *                                     Reflection*::export(): deprecated 7.4, Error from 8.0
 * - call.splfileobject_fgetss         $file->fgetss(): deprecated 7.3, Error from 8.0
 * - call.splfixedarray_iterator_method $fixed->rewind(), current(), key(), next(),
 *                                     valid() on an SplFixedArray: Error from 8.0
 *
 * export() is judged by the class name written before ::, which must be one
 * of the Reflection classes itself.
 *
 * fgetss() is no method of any other class that PHP has, so any call to it
 * is taken for SplFileObject's, unless the class the call is written in
 * declares a method fgetss() of its own.
 *
 * The SplFixedArray methods are judged only on (new SplFixedArray( ... ))
 * itself, or on a local variable of a function that is only ever assigned
 * new SplFixedArray( ... ) or SplFixedArray::fromArray( ... ) there (see
 * Php80BicExtLocal); an SplFixedArray kept anywhere else is not followed.
 */
final class Php80BicExtRemovedMethods implements Rule {

	/**
	 * Reflection classes that had a static export() up to 7.4.
	 */
	const REFLECTION = array(
		'reflectionclass'         => true,
		'reflectionobject'        => true,
		'reflectionmethod'        => true,
		'reflectionfunction'      => true,
		'reflectionproperty'      => true,
		'reflectionparameter'     => true,
		'reflectionextension'     => true,
		'reflectionzendextension' => true,
		'reflectionclassconstant' => true,
	);

	/**
	 * Iterator methods SplFixedArray no longer has.
	 */
	const FIXED_ARRAY = array(
		'rewind'  => true,
		'current' => true,
		'key'     => true,
		'next'    => true,
		'valid'   => true,
	);

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Expr\StaticCall::class, Expr\MethodCall::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Expr\StaticCall|Expr\MethodCall $node    Call.
	 * @param Context                         $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( ! $node->name instanceof Node\Identifier ) {
			return;
		}

		$method = $node->name->toLowerString();

		if ( $node instanceof Expr\StaticCall ) {
			$class = Php80BicExtLocal::class_name( $node->class );

			if ( 'export' === $method && null !== $class && isset( self::REFLECTION[ $class ] ) ) {
				$finding          = $context->report( 'call.reflection_export', $node, array( 'deprecated' => '7.4', 'breaks' => '8.0' ) );
				$finding->subject = $node->class->getLast() . '::export()';
				$finding->instead = 'echo new ' . $node->class->getLast() . '( ... ), or (string) new ' . $node->class->getLast() . '( ... )';
			}

			return;
		}

		if ( 'fgetss' === $method ) {
			if ( ! self::own_method( $node, 'fgetss' ) ) {
				$finding          = $context->report( 'call.splfileobject_fgetss', $node, array( 'deprecated' => '7.3', 'breaks' => '8.0' ) );
				$finding->subject = 'SplFileObject::fgetss()';
				$finding->instead = 'strip_tags( $file->fgets() )';
			}

			return;
		}

		if ( isset( self::FIXED_ARRAY[ $method ] ) && $this->is_fixed_array( $node->var, $context ) ) {
			$finding          = $context->report( 'call.splfixedarray_iterator_method', $node, array( 'breaks' => '8.0' ) );
			$finding->subject = 'SplFixedArray::' . $method . '()';
			$finding->instead = 'foreach, or the iterator from getIterator()';
		}
	}

	/**
	 * Whether the class the call is written in declares the method itself.
	 *
	 * @param Node   $node   Call.
	 * @param string $method Lower-case method name.
	 * @return bool
	 */
	private static function own_method( Node $node, $method ) {
		$current = $node->getAttribute( 'parent' );

		while ( null !== $current && ! $current instanceof Node\Stmt\ClassLike ) {
			$current = $current->getAttribute( 'parent' );
		}

		if ( null === $current ) {
			return false;
		}

		foreach ( $current->getMethods() as $declared ) {
			if ( $declared->name->toLowerString() === $method ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the expression is certainly an SplFixedArray.
	 *
	 * @param Expr    $value   Receiver of the method call.
	 * @param Context $context Scan state.
	 * @return bool
	 */
	private function is_fixed_array( Expr $value, Context $context ) {
		if ( self::makes_fixed_array( $value ) ) {
			return true;
		}

		if ( ! $value instanceof Expr\Variable ) {
			return false;
		}

		$is_read = static function ( Expr\Variable $use, $parent ) {
			if ( Php80BicExtLocal::plain_read( $use, $parent ) ) {
				return true;
			}

			// $fixed[0] and $fixed[0] = ...: the elements change, not the object.
			if ( $parent instanceof Expr\ArrayDimFetch && $parent->var === $use ) {
				return true;
			}

			if ( $parent instanceof Node\Stmt\Foreach_ && $parent->expr === $use ) {
				return true;
			}

			if ( $parent instanceof Node\Arg && ! $parent->unpack ) {
				$call = $parent->getAttribute( 'parent' );

				return $call instanceof Expr\FuncCall && in_array( InternalFunctions::global_name( $call->name ), array( 'count', 'var_dump', 'is_object', 'get_class', 'iterator_to_array' ), true );
			}

			return false;
		};

		return Php80BicExtLocal::only_assigned( $value, array( __CLASS__, 'makes_fixed_array' ), $is_read );
	}

	/**
	 * Whether the expression is new SplFixedArray( ... ) or
	 * SplFixedArray::fromArray( ... ).
	 *
	 * @param Expr $value Expression.
	 * @return bool
	 */
	public static function makes_fixed_array( $value ) {
		if ( $value instanceof Expr\New_ ) {
			return 'splfixedarray' === Php80BicExtLocal::class_name( $value->class );
		}

		return $value instanceof Expr\StaticCall
			&& 'splfixedarray' === Php80BicExtLocal::class_name( $value->class )
			&& $value->name instanceof Node\Identifier
			&& 'fromarray' === $value->name->toLowerString();
	}
}
