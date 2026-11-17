<?php
/**
 * Calls to internal functions whose arguments PHP 8.5 deprecates, when the
 * argument is written out in the call.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule ids (tranche 8.5-dep):
 *
 * - call.get_defined_functions_argument  get_defined_functions( $exclude_disabled ):
 *                                         any argument from 8.5; a literal false
 *                                         already from 8.0
 * - call.finfo_buffer_context            finfo_buffer() with a fourth ($context) argument
 * - call.openssl_pkey_derive_key_length  openssl_pkey_derive() with a third
 *                                         ($key_length) argument, whatever its value
 * - call.spl_autoload_unregister_call    spl_autoload_unregister( 'spl_autoload_call' )
 * - argument.dir_handle_missing          readdir(), rewinddir(), closedir() without a
 *                                         directory handle or with a literal null
 * - argument.chr_out_of_range            chr() with a literal integer outside 0..255
 * - argument.ord_not_one_byte            ord() with a literal string that is not one byte
 * - argument.array_key_exists_null       array_key_exists() / key_exists() with a literal
 *                                         null key
 *
 * All of these are raised when the call runs, so only a PHP version check
 * marks them as guarded: function_exists() for the same function does not
 * keep the notice away. Values that come from variables are known at run
 * time only and are not reported.
 */
final class Php85DepFunctionArguments implements Rule {

	const VERSION_GUARD = '8.5-dep: version check only';

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Expr\FuncCall::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Expr\FuncCall $node    Function call.
	 * @param Context            $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		$name = InternalFunctions::global_name( $node->name );

		if ( null === $name || isset( $context->declared_functions[ $name ] ) ) {
			return;
		}

		$positional = array();
		$named      = array();

		foreach ( $node->args as $arg ) {
			// A first-class callable (f(...)) or an unpacked array: arguments unknown.
			if ( ! $arg instanceof Node\Arg || $arg->unpack ) {
				return;
			}

			if ( null !== $arg->name ) {
				$named[ strtolower( $arg->name->toString() ) ] = $arg->value;
			} else {
				$positional[] = $arg->value;
			}
		}

		$argument = static function ( $index, $param ) use ( $positional, $named ) {
			if ( isset( $positional[ $index ] ) ) {
				return $positional[ $index ];
			}

			return isset( $named[ $param ] ) ? $named[ $param ] : null;
		};

		switch ( $name ) {
			case 'get_defined_functions':
				$value = $argument( 0, 'exclude_disabled' );

				if ( null === $value ) {
					return;
				}

				$since = self::is_literal( $value, 'false' ) ? '8.0' : '8.5';

				$finding          = $context->report( 'call.get_defined_functions_argument', $node, array( 'deprecated' => $since ) );
				$finding->subject = 'get_defined_functions( $exclude_disabled )';
				$finding->instead = 'get_defined_functions()';
				break;

			case 'finfo_buffer':
				if ( null === $argument( 3, 'context' ) ) {
					return;
				}

				$finding          = $context->report( 'call.finfo_buffer_context', $node, array( 'deprecated' => '8.5' ) );
				$finding->subject = 'finfo_buffer( ..., $context )';
				$finding->instead = 'finfo_buffer() without the $context argument';
				break;

			case 'openssl_pkey_derive':
				if ( null === $argument( 2, 'key_length' ) ) {
					return;
				}

				$finding          = $context->report( 'call.openssl_pkey_derive_key_length', $node, array( 'deprecated' => '8.5' ) );
				$finding->subject = 'openssl_pkey_derive( ..., $key_length )';
				$finding->instead = 'openssl_pkey_derive() without $key_length';
				break;

			case 'spl_autoload_unregister':
				$value = $argument( 0, 'callback' );

				if ( ! $value instanceof Node\Scalar\String_ || 'spl_autoload_call' !== strtolower( ltrim( $value->value, '\\' ) ) ) {
					return;
				}

				$finding          = $context->report( 'call.spl_autoload_unregister_call', $node, array( 'deprecated' => '8.5' ) );
				$finding->subject = "spl_autoload_unregister( 'spl_autoload_call' )";
				$finding->instead = 'spl_autoload_unregister() for each entry of spl_autoload_functions()';
				break;

			case 'readdir':
			case 'rewinddir':
			case 'closedir':
				if ( 0 !== count( $node->args ) ) {
					$value = $argument( 0, 'dir_handle' );

					if ( null === $value || ! self::is_literal( $value, 'null' ) ) {
						return;
					}
				}

				$finding          = $context->report( 'argument.dir_handle_missing', $node, array( 'deprecated' => '8.5' ) );
				$finding->subject = $name . '()';
				$finding->instead = $name . '( $handle ) with the handle opendir() returned';
				break;

			case 'chr':
				$value = $argument( 0, 'codepoint' );
				$int   = self::literal_int( $value );

				if ( null === $int || ( $int >= 0 && $int <= 255 ) ) {
					return;
				}

				$finding          = $context->report( 'argument.chr_out_of_range', $node, array( 'deprecated' => '8.5' ) );
				$finding->subject = 'chr( ' . $int . ' )';
				$finding->instead = 'chr( ' . ( ( ( $int % 256 ) + 256 ) % 256 ) . ' )';
				break;

			case 'ord':
				$value = $argument( 0, 'character' );

				if ( ! $value instanceof Node\Scalar\String_ || 1 === strlen( $value->value ) ) {
					return;
				}

				$finding          = $context->report( 'argument.ord_not_one_byte', $node, array( 'deprecated' => '8.5' ) );
				$finding->subject = 'ord() with a string of ' . strlen( $value->value ) . ' bytes';
				$finding->instead = 'ord( $string[0] )';
				break;

			case 'array_key_exists':
			case 'key_exists':
				$value = $argument( 0, 'key' );

				if ( null === $value || ! self::is_literal( $value, 'null' ) ) {
					return;
				}

				$finding          = $context->report( 'argument.array_key_exists_null', $node, array( 'deprecated' => '8.5' ) );
				$finding->subject = $name . '( null, ... )';
				$finding->instead = "''";
				break;

			default:
				return;
		}

		$finding->guarded = $context->function_is_guarded( self::VERSION_GUARD );
	}

	/**
	 * Whether an expression is the constant true, false or null written out.
	 *
	 * @param Node\Expr $value Expression.
	 * @param string    $word  "true", "false" or "null".
	 * @return bool
	 */
	public static function is_literal( Node\Expr $value, $word ) {
		return $value instanceof Node\Expr\ConstFetch
			&& 1 === count( $value->name->getParts() )
			&& $word === strtolower( $value->name->getLast() );
	}

	/**
	 * The value of an integer literal, with an optional minus sign, or null.
	 *
	 * @param Node\Expr|null $value Expression.
	 * @return int|null
	 */
	private static function literal_int( $value ) {
		if ( $value instanceof Node\Scalar\Int_ ) {
			return (int) $value->value;
		}

		if ( $value instanceof Node\Expr\UnaryMinus && $value->expr instanceof Node\Scalar\Int_ ) {
			return -1 * (int) $value->expr->value;
		}

		return null;
	}
}
