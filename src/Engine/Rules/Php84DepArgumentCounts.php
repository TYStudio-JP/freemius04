<?php
/**
 * Calls whose number of arguments PHP 8.4 deprecates.
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
 * - call.mysqli_store_result_mode                 mysqli_store_result( $link, $mode )
 * - call.session_set_save_handler_callbacks       session_set_save_handler() with more than 2 arguments
 * - call.stream_context_set_option_two_arguments  stream_context_set_option( $context, $options )
 *
 * Each is decided by counting the arguments that are written out. A call that
 * spreads an array into its arguments is not counted and not reported.
 *
 * The method forms ($mysqli->store_result( $mode )) are not reported: the
 * class of the object is not visible in the source.
 */
final class Php84DepArgumentCounts implements Rule {

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

		if ( 'mysqli_store_result' !== $name && 'session_set_save_handler' !== $name && 'stream_context_set_option' !== $name ) {
			return;
		}

		foreach ( $node->args as $arg ) {
			if ( ! $arg instanceof Node\Arg || $arg->unpack ) {
				return;
			}
		}

		$count   = count( $node->args );
		$finding = null;

		if ( 'mysqli_store_result' === $name && 2 === $count ) {
			$finding          = $context->report( 'call.mysqli_store_result_mode', $node, array( 'deprecated' => '8.4' ) );
			$finding->instead = 'mysqli_store_result( $link )';
		} elseif ( 'session_set_save_handler' === $name && $count > 2 ) {
			$finding          = $context->report( 'call.session_set_save_handler_callbacks', $node, array( 'deprecated' => '8.4' ) );
			$finding->instead = 'session_set_save_handler( $object_implementing_SessionHandlerInterface )';
		} elseif ( 'stream_context_set_option' === $name && 2 === $count ) {
			$finding          = $context->report( 'call.stream_context_set_option_two_arguments', $node, array( 'deprecated' => '8.4' ) );
			$finding->instead = 'stream_context_set_options() (PHP 8.3 and later)';
		}

		if ( null === $finding ) {
			return;
		}

		$finding->subject = $name;
		// Only a PHP version check keeps the notice away; function_exists() for the
		// function itself is true on every version and guards nothing.
		$finding->guarded = $context->function_is_guarded( '8.4-dep: version check only' );

		// "if ( function_exists( 'stream_context_set_options' ) ) { new } else { old }"
		// is how code that supports PHP 8.2 and 8.4 alike is written.
		if ( 'stream_context_set_option' === $name && $context->function_is_guarded( 'stream_context_set_options' ) ) {
			$finding->guarded = true;
		}
	}
}
