<?php
/**
 * mb_strimwidth() with a negative literal width, deprecated in PHP 8.3.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: argument.mb_strimwidth_negative_width.
 *
 * Only a width written as a negative number is reported. A width from a
 * variable is known at run time only (fixture
 * runtime.mb_strimwidth_negative_width). A plugin that declares its own global
 * mb_strimwidth(), a polyfill, is left alone.
 */
final class Php83MbStrimwidthNegative implements Rule {

	/**
	 * A name no function has, so that only a version check counts as a guard.
	 */
	const VERSION_GUARD = '8.3: version check only';

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
		if ( 'mb_strimwidth' !== InternalFunctions::global_name( $node->name ) || isset( $context->declared_functions['mb_strimwidth'] ) ) {
			return;
		}

		$width = Php83Range::argument( $node, 2, 'width' );

		if ( ! $width instanceof Node\Expr ) {
			return;
		}

		$value = Php83Range::number( $width );

		if ( ! is_int( $value ) || $value >= 0 ) {
			return;
		}

		$finding          = $context->report( 'argument.mb_strimwidth_negative_width', $node, array( 'deprecated' => '8.3' ) );
		$finding->subject = 'mb_strimwidth()';
		$finding->instead = 'a positive width, worked out from mb_strwidth() of the string';
		$finding->guarded = $context->function_is_guarded( self::VERSION_GUARD );
	}
}
