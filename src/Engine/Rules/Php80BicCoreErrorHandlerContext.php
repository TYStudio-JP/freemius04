<?php
/**
 * Error handlers that require the fifth argument, $errcontext.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: callable.error_handler_errcontext. From 8.0 PHP calls an error
 * handler with four arguments, so a handler whose fifth parameter has no
 * default fails with an ArgumentCountError the first time an error is raised.
 * PHP 7.4 raises no deprecation for it (the runtimes show none, although the
 * 7.2 notes call the argument deprecated).
 *
 * Looked at: set_error_handler() with a closure, an arrow function, or the
 * name of a function declared in the same file. A fifth parameter with a
 * default, or a variadic one, is fine.
 */
final class Php80BicCoreErrorHandlerContext implements Rule {

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
		if ( 'set_error_handler' !== InternalFunctions::global_name( $node->name ) || isset( $context->declared_functions['set_error_handler'] ) ) {
			return;
		}

		if ( ! isset( $node->args[0] ) || ! $node->args[0] instanceof Node\Arg || $node->args[0]->unpack || null !== $node->args[0]->name ) {
			return;
		}

		$handler = $node->args[0]->value;
		$params  = null;

		if ( $handler instanceof Node\Expr\Closure || $handler instanceof Node\Expr\ArrowFunction ) {
			$params = $handler->getParams();
		} elseif ( $handler instanceof Node\Scalar\String_ ) {
			$name  = strtolower( ltrim( $handler->value, '\\' ) );
			$index = Php80BicCoreIndex::of( $context );

			if ( isset( $index['functions'][ $name ] ) ) {
				$params = $index['functions'][ $name ]->getParams();
			}
		}

		if ( null === $params || count( $params ) < 5 ) {
			return;
		}

		$fifth = $params[4];

		if ( null !== $fifth->default || $fifth->variadic ) {
			return;
		}

		$finding          = $context->report( 'callable.error_handler_errcontext', $fifth, array( 'breaks' => '8.0' ) );
		$finding->subject = $fifth->var instanceof Node\Expr\Variable && is_string( $fifth->var->name ) ? '$' . $fifth->var->name : '';
		$finding->instead = 'drop the fifth parameter, or give it a default';
	}
}
