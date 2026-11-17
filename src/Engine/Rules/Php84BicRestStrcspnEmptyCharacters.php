<?php
/**
 * strcspn() with an empty $characters string.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id (tranche 8.4-bic-rest): argument.strcspn_empty_characters. Changed in 8.4.
 *
 *     $length = strcspn( $data, '' );
 *
 * Up to 8.3 such a call stopped at the first NUL byte of the string; from 8.4
 * it returns the length of the string (from the offset, if one is given).
 * Fixtures argument.strcspn_empty_characters~8.4-bic-rest*.
 *
 * Reported only when $characters is the literal empty string. A call whose
 * string is itself a literal without a NUL byte gives the same result on
 * every version and is not reported. A version check counts as a guard.
 */
final class Php84BicRestStrcspnEmptyCharacters implements Rule {

	const VERSION_GUARD = '8.4-bic-rest: version check only';

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

		if ( 'strcspn' !== $name || isset( $context->declared_functions[ $name ] ) ) {
			return;
		}

		$string     = null;
		$characters = null;

		foreach ( $node->args as $index => $arg ) {
			if ( ! $arg instanceof Node\Arg || $arg->unpack ) {
				return;
			}

			$key = null === $arg->name ? $index : strtolower( $arg->name->toString() );

			if ( 0 === $key || 'string' === $key ) {
				$string = $arg->value;
			} elseif ( 1 === $key || 'characters' === $key ) {
				$characters = $arg->value;
			}
		}

		if ( ! $characters instanceof Node\Scalar\String_ || '' !== $characters->value ) {
			return;
		}

		if ( $string instanceof Node\Scalar\String_ && false === strpos( $string->value, "\0" ) ) {
			return;
		}

		$finding          = $context->report( 'argument.strcspn_empty_characters', $node, array( 'changed' => '8.4' ) );
		$finding->subject = 'strcspn() with empty $characters: stopped at the first NUL byte before PHP 8.4, returns the full length from 8.4';
		$finding->instead = 'strlen() for the length, or strpos( $string, "\\0" ) for the position of the first NUL byte';
		$finding->guarded = $context->function_is_guarded( self::VERSION_GUARD );
	}
}
