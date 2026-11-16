<?php
/**
 * trigger_error() with E_USER_ERROR, deprecated in PHP 8.4.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: call.trigger_error_e_user_error.
 *
 * Only a level written out as the constant E_USER_ERROR or as the number 256
 * is reported. A level that comes from a variable is known at run time only.
 */
final class Php84DepTriggerError implements Rule {

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

		if ( ( 'trigger_error' !== $name && 'user_error' !== $name ) || isset( $context->declared_functions[ $name ] ) ) {
			return;
		}

		$level = null;

		foreach ( $node->args as $index => $arg ) {
			if ( ! $arg instanceof Node\Arg || $arg->unpack ) {
				return;
			}

			if ( null === $arg->name ? 1 === $index : 'error_level' === $arg->name->toString() ) {
				$level = $arg->value;
			}
		}

		if ( $level instanceof Node\Expr\ConstFetch ) {
			$resolved = $level->name->getAttribute( 'resolvedName' );
			$constant = $resolved instanceof Node\Name ? $resolved : $level->name;

			if ( 1 !== count( $constant->getParts() ) || 'E_USER_ERROR' !== $constant->getLast() ) {
				return;
			}
		} elseif ( ! $level instanceof Node\Scalar\Int_ || 256 !== $level->value ) {
			return;
		}

		$finding          = $context->report( 'call.trigger_error_e_user_error', $node, array( 'deprecated' => '8.4' ) );
		$finding->subject = $name . '( ..., E_USER_ERROR )';
		$finding->instead = 'throw an exception, or call exit() with the message';
		$finding->guarded = $context->function_is_guarded( $name );
	}
}
