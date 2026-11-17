<?php
/**
 * Case-insensitive constants: define( 'NAME', $value, true ), used in another case.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: call.define_case_insensitive. Deprecated in 7.3, and from 8.0 the
 * third argument is ignored (with a warning), so the constant is case-sensitive
 * and a use in another case is an Error.
 *
 * Reported only when the same file uses the constant in a case other than the
 * one it is defined in. Where every use has the defined case, the runtimes
 * show only a warning from 8.0 and the same output, which no finding level
 * describes, so that is left unreported.
 */
final class Php80BicCoreDefineCaseInsensitive implements Rule {

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
		if ( 'define' !== InternalFunctions::global_name( $node->name ) || isset( $context->declared_functions['define'] ) || 3 !== count( $node->args ) ) {
			return;
		}

		foreach ( $node->args as $arg ) {
			if ( ! $arg instanceof Node\Arg || $arg->unpack || null !== $arg->name ) {
				return;
			}
		}

		$name = $node->args[0]->value;
		$flag = $node->args[2]->value;

		if ( ! $name instanceof Node\Scalar\String_ || ! $flag instanceof Node\Expr\ConstFetch || 'true' !== $flag->name->toLowerString() ) {
			return;
		}

		$defined = ltrim( $name->value, '\\' );

		if ( '' === $defined || false !== strpos( $defined, '\\' ) ) {
			return;
		}

		$index = Php80BicCoreIndex::of( $context );
		$other = null;

		if ( isset( $index['constants'][ strtolower( $defined ) ] ) ) {
			foreach ( $index['constants'][ strtolower( $defined ) ] as $fetch ) {
				// Foo\DEBUG is another constant; only DEBUG and \DEBUG can be this one.
				if ( 1 === count( $fetch->name->getParts() ) && $fetch->name->getLast() !== $defined ) {
					$other = $fetch->name->getLast();
					break;
				}
			}
		}

		if ( null === $other ) {
			return;
		}

		$finding          = $context->report(
			'call.define_case_insensitive',
			$node,
			array(
				'deprecated' => '7.3',
				'breaks'     => '8.0',
			)
		);
		$finding->subject = $defined . ' (used as ' . $other . ')';
		$finding->instead = 'define( \'' . $defined . '\', ... ) and the same case everywhere';
	}
}
