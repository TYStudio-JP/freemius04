<?php
/**
 * fputcsv(), fgetcsv() and str_getcsv() without the $escape argument, deprecated in PHP 8.4.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: call.csv_escape_default.
 *
 * The default of $escape is going to change, so PHP 8.4 asks for the argument
 * to be written out, by position or by name. A call that spreads an array
 * into its arguments is not reported.
 *
 * The same holds for the methods of SplFileObject, but the class of an object
 * is not visible in the source, so those are not reported.
 */
final class Php84DepCsvEscape implements Rule {

	/**
	 * Position of $escape, counted from 0.
	 */
	const ESCAPE_AT = array(
		'fputcsv'    => 4,
		'fgetcsv'    => 4,
		'str_getcsv' => 3,
	);

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

		if ( null === $name || ! isset( self::ESCAPE_AT[ $name ] ) || isset( $context->declared_functions[ $name ] ) ) {
			return;
		}

		$positional = 0;

		foreach ( $node->args as $arg ) {
			if ( ! $arg instanceof Node\Arg || $arg->unpack ) {
				return;
			}

			if ( null === $arg->name ) {
				++$positional;
			} elseif ( 'escape' === $arg->name->toString() ) {
				return;
			}
		}

		if ( $positional > self::ESCAPE_AT[ $name ] ) {
			return;
		}

		$finding          = $context->report( 'call.csv_escape_default', $node, array( 'deprecated' => '8.4' ) );
		$finding->subject = $name;
		$finding->instead = 'pass $escape explicitly; "\\\\" keeps what the call does today';
		// Only a PHP version check keeps the notice away: function_exists() for the function itself
		// is true on every version and guards nothing (seen on the 8.4 runtime).
		$finding->guarded = $context->function_is_guarded( '8.4-dep: version check only' );
	}
}
