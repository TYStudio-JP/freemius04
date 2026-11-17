<?php
/**
 * setlocale() with an array in a place that 8.6 no longer accepts (tranche 8.6-bic).
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: argument.setlocale_array_position. Documented for 8.6 only.
 *
 * The PHP 8.6 upgrade notes, word for word:
 *
 *     Arrays are now accepted only for the $locales argument. Passing an array as
 *     a later variadic locale argument now throws a TypeError. Passing any
 *     additional locale arguments when $locales is an array now throws an
 *     ArgumentCountError.
 *
 *     setlocale( LC_ALL, 'de_DE', array( 'de' ) );   // TypeError on 8.6
 *     setlocale( LC_ALL, array( 'de_DE' ), 'de' );   // ArgumentCountError on 8.6
 *
 * Both calls run without a diagnostic on 7.4 to 8.5 (fixtures
 * argument.setlocale_array_position~8.6-bic-*). There is no 8.6 runtime, so
 * the findings have the evidence "documented".
 *
 * Only array literals are recognised; a variable holding an array cannot be
 * told from one holding a string. Calls with named or unpacked arguments are
 * left alone.
 */
final class Php86BicSetlocaleArrays implements Rule {

	const VERSION_GUARD = '8.6-bic: version check only';

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

		if ( 'setlocale' !== $name || isset( $context->declared_functions[ $name ] ) ) {
			return;
		}

		foreach ( $node->args as $arg ) {
			if ( ! $arg instanceof Node\Arg || $arg->unpack || null !== $arg->name ) {
				return;
			}
		}

		$args = $node->args;
		$why  = null;

		foreach ( $args as $index => $arg ) {
			if ( $index >= 2 && $arg->value instanceof Node\Expr\Array_ ) {
				$why = 'an array after the $locales argument (TypeError)';
				break;
			}
		}

		if ( null === $why && isset( $args[1] ) && $args[1]->value instanceof Node\Expr\Array_ && count( $args ) > 2 ) {
			$why = 'more locale arguments after an array $locales (ArgumentCountError)';
		}

		if ( null === $why ) {
			return;
		}

		$finding           = $context->report( 'argument.setlocale_array_position', $node, array( 'breaks' => '8.6' ) );
		$finding->subject  = 'setlocale() with ' . $why;
		$finding->evidence = 'documented';
		$finding->instead  = 'all locale names in the one array passed as $locales; documented for PHP 8.6, not verified on an 8.6 runtime';
		$finding->guarded  = $context->function_is_guarded( self::VERSION_GUARD );
	}
}
