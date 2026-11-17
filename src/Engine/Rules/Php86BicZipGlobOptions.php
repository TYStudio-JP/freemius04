<?php
/**
 * ZipArchive::addGlob() / addPattern() options of the wrong type (tranche 8.6-bic).
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: argument.zip_glob_option_type. Documented for 8.6 only.
 *
 * The PHP 8.6 upgrade notes, word for word:
 *
 *     ZipArchive::addGlob() and ZipArchive::addPattern() now raise a TypeError
 *     when the "remove_all_path" option is not of type bool, or when the
 *     "comp_method", "comp_flags", or "enc_method" options are not of type int
 *     (numeric strings are coerced), instead of emitting a warning.
 *
 *     $zip->addGlob( '*.txt', 0, array( 'remove_all_path' => 1 ) );
 *
 * The runtimes showed 7.4 accepting these silently and 8.0 to 8.5 warning
 * "Option "remove_all_path" must be of type bool, int given" and going on
 * (fixtures argument.zip_glob_option_type~8.6-bic-*). No 8.6 runtime:
 * evidence "documented".
 *
 * Reported when the options argument is an array literal and:
 *
 * - "remove_all_path" is a literal that is not true or false (an int, float,
 *   string, null or array);
 * - "comp_method", "comp_flags" or "enc_method" is a non-numeric string
 *   literal or an array literal. Numeric strings are coerced, as the notes
 *   say; for floats, booleans and null the notes do not say, so those are not
 *   reported.
 *
 * The receiver's class cannot be seen; the method names together with these
 * option keys are specific to ZipArchive.
 */
final class Php86BicZipGlobOptions implements Rule {

	const VERSION_GUARD = '8.6-bic: version check only';

	/**
	 * Method => position of the options argument.
	 */
	const METHODS = array(
		'addglob'    => 2,
		'addpattern' => 2,
	);

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Expr\MethodCall::class, Node\Expr\NullsafeMethodCall::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Expr\MethodCall $node    Method call.
	 * @param Context              $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( ! $node->name instanceof Node\Identifier ) {
			return;
		}

		$method = strtolower( $node->name->toString() );

		if ( ! isset( self::METHODS[ $method ] ) ) {
			return;
		}

		$options = null;

		foreach ( $node->args as $index => $arg ) {
			if ( ! $arg instanceof Node\Arg || $arg->unpack ) {
				return;
			}

			if ( null === $arg->name ? self::METHODS[ $method ] === $index : 'options' === strtolower( $arg->name->toString() ) ) {
				$options = $arg->value;
				break;
			}
		}

		if ( ! $options instanceof Node\Expr\Array_ ) {
			return;
		}

		foreach ( $options->items as $item ) {
			if ( null === $item || ! $item->key instanceof Node\Scalar\String_ ) {
				continue;
			}

			$key = $item->key->value;

			if ( 'remove_all_path' === $key ? self::not_bool( $item->value ) : ( in_array( $key, array( 'comp_method', 'comp_flags', 'enc_method' ), true ) && self::not_int( $item->value ) ) ) {
				$finding           = $context->report( 'argument.zip_glob_option_type', $node, array( 'breaks' => '8.6' ) );
				$finding->subject  = 'ZipArchive::' . $node->name->toString() . '() option "' . $key . '"';
				$finding->evidence = 'documented';
				$finding->instead  = ( 'remove_all_path' === $key ? 'true or false' : 'an int (a ZipArchive::CM_* or EM_* constant)' ) . '; documented for PHP 8.6, not verified on an 8.6 runtime';
				$finding->guarded  = $context->function_is_guarded( self::VERSION_GUARD );

				return;
			}
		}
	}

	/**
	 * Whether a literal is certainly not a bool.
	 *
	 * @param Node\Expr $value Value.
	 * @return bool
	 */
	private static function not_bool( Node\Expr $value ) {
		if ( $value instanceof Node\Scalar\Int_ || $value instanceof Node\Scalar\Float_ || $value instanceof Node\Scalar\String_ || $value instanceof Node\Expr\Array_ ) {
			return true;
		}

		return $value instanceof Node\Expr\ConstFetch && 'null' === strtolower( $value->name->toString() );
	}

	/**
	 * Whether a literal is certainly neither an int nor a numeric string.
	 *
	 * @param Node\Expr $value Value.
	 * @return bool
	 */
	private static function not_int( Node\Expr $value ) {
		if ( $value instanceof Node\Scalar\String_ ) {
			return ! is_numeric( $value->value );
		}

		return $value instanceof Node\Expr\Array_;
	}
}
