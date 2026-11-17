<?php
/**
 * call_user_func_array() with string keys in a literal argument array.
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
 * - call.call_user_func_array_named_error  from 8.0 the string keys are
 *   parameter names, and the call fails: a key that is no parameter name, a
 *   positional value after a named one, a name given twice, or a required
 *   parameter left without a value.
 * - call.call_user_func_array_named_order  from 8.0 the values reach other
 *   parameters than on 7.4, which passed them by position.
 *
 * Worked out only when the callback is a closure or arrow function written in
 * the call, or the name of a function declared in the same file, the
 * function has no variadic parameter, and every key of the literal array is a
 * literal. A call that already failed on 7.4 (too few values) is left alone.
 */
final class Php80BicCoreCallUserFuncArrayKeys implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Expr\FuncCall::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Expr\FuncCall $node    Call.
	 * @param Context            $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( 'call_user_func_array' !== InternalFunctions::global_name( $node->name ) || isset( $context->declared_functions['call_user_func_array'] ) || 2 !== count( $node->args ) ) {
			return;
		}

		foreach ( $node->args as $arg ) {
			if ( ! $arg instanceof Node\Arg || $arg->unpack || null !== $arg->name ) {
				return;
			}
		}

		$values = $node->args[1]->value;
		$params = $this->params_of( $node->args[0]->value, $context );

		if ( null === $params || ! $values instanceof Node\Expr\Array_ ) {
			return;
		}

		$keys = array();

		foreach ( $values->items as $item ) {
			if ( null === $item || $item->unpack ) {
				return;
			}

			if ( null === $item->key || $item->key instanceof Node\Scalar\Int_ ) {
				$keys[] = null;
			} elseif ( $item->key instanceof Node\Scalar\String_ ) {
				$keys[] = preg_match( '/^(0|-?[1-9][0-9]{0,17})$/', $item->key->value ) ? null : $item->key->value;
			} else {
				return;
			}
		}

		$named = array_filter( $keys, 'is_string' );

		// A key written twice is one value in the array; leave that alone.
		if ( array() === $named || count( $named ) !== count( array_unique( $named ) ) ) {
			return;
		}

		$outcome = self::compare( $params, $keys );

		if ( null === $outcome ) {
			return;
		}

		if ( 'order' === $outcome[0] ) {
			$finding = $context->report( 'call.call_user_func_array_named_order', $values, array( 'changed' => '8.0' ) );
		} else {
			$finding = $context->report( 'call.call_user_func_array_named_error', $values, array( 'breaks' => '8.0' ) );
		}

		$finding->subject = $outcome[1];
		$finding->instead = 'array_values( ... ), or keys that are the parameter names in their order';
	}

	/**
	 * Parameters of the callback, or null when it is not known or variadic.
	 *
	 * @param Node\Expr $callback First argument.
	 * @param Context   $context  Scan state.
	 * @return Node\Param[]|null
	 */
	private function params_of( Node\Expr $callback, Context $context ) {
		$params = null;

		if ( $callback instanceof Node\Expr\Closure || $callback instanceof Node\Expr\ArrowFunction ) {
			$params = $callback->getParams();
		} elseif ( $callback instanceof Node\Scalar\String_ && false === strpos( $callback->value, '::' ) ) {
			$index = Php80BicCoreIndex::of( $context );
			$name  = strtolower( ltrim( $callback->value, '\\' ) );

			if ( isset( $index['functions'][ $name ] ) ) {
				$params = $index['functions'][ $name ]->getParams();
			}
		}

		if ( null === $params ) {
			return null;
		}

		foreach ( $params as $param ) {
			if ( $param->variadic || ! $param->var instanceof Node\Expr\Variable || ! is_string( $param->var->name ) ) {
				return null;
			}
		}

		return $params;
	}

	/**
	 * How the call behaves on 8.0 compared with 7.4.
	 *
	 * @param Node\Param[]     $params Parameters.
	 * @param array<?string>   $keys   Per value: its string key, or null for a positional one.
	 * @return array{0: string, 1: string}|null Kind ('error' or 'order') and a description; null when the same.
	 */
	private static function compare( array $params, array $keys ) {
		$names    = array();
		$required = 0;

		foreach ( $params as $at => $param ) {
			$names[ $param->var->name ] = $at;

			if ( null === $param->default ) {
				$required = $at + 1;
			}
		}

		// Too few values fails on 7.4 already.
		if ( count( $keys ) < $required ) {
			return null;
		}

		$slots = array();
		$named = false;

		foreach ( $keys as $value_at => $key ) {
			if ( null === $key ) {
				if ( $named ) {
					return array( 'error', 'a positional value after a named one' );
				}

				$slots[ $value_at ] = $value_at;
				continue;
			}

			$named = true;

			if ( ! isset( $names[ $key ] ) ) {
				return array( 'error', 'no parameter $' . $key );
			}

			if ( isset( $slots[ $names[ $key ] ] ) ) {
				return array( 'error', '$' . $key . ' given twice' );
			}

			$slots[ $names[ $key ] ] = $value_at;
		}

		foreach ( $params as $at => $param ) {
			if ( null === $param->default && ! isset( $slots[ $at ] ) ) {
				return array( 'error', 'no value for $' . $param->var->name );
			}
		}

		foreach ( $params as $at => $param ) {
			$old = $at < count( $keys ) ? $at : null;
			$new = isset( $slots[ $at ] ) ? $slots[ $at ] : null;

			if ( $old !== $new ) {
				return array( 'order', '$' . $param->var->name . ' gets another value' );
			}
		}

		return null;
	}
}
