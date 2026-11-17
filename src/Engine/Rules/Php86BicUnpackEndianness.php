<?php
/**
 * unpack() formats with "<" or ">" right after a format code (tranche 8.6-bic).
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: argument.unpack_endianness_modifier. Documented for 8.6 only.
 *
 * The PHP 8.6 upgrade notes, word for word:
 *
 *     unpack() now reads a "<" or ">" immediately following a format code as an
 *     endianness modifier rather than as the first character of the element name.
 *     Formats such as "s<value" now produce the key "value" instead of "<value",
 *     and "C>name" raises a ValueError because the C format code accepts no
 *     endianness modifier. A name starting with these characters is unaffected
 *     when a repeater precedes it, as in "s1<value".
 *
 *     print_r( unpack( 's<value', "\x01\x00" ) );   // 7.4-8.5: [<value] => 1
 *
 * The runtimes (7.4 to 8.5) showed the key "<value" and ">name" on every
 * version (fixtures argument.unpack_endianness_modifier~8.6-bic-*). There is
 * no 8.6 runtime, so every finding has the evidence "documented".
 *
 * Reported when the format is a string literal and an element (the parts
 * between "/") has "<" or ">" as its second character. For the code "C" the
 * notes say ValueError, so that is "breaks 8.6"; for any other code the key
 * changes, "changed 8.6". The notes do not list which other codes accept no
 * modifier: for those 8.6 would throw as well, so the finding may understate
 * the effect, never invent one.
 *
 * The result differs when the call runs, so a version check counts as a guard.
 */
final class Php86BicUnpackEndianness implements Rule {

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

		if ( 'unpack' !== $name || isset( $context->declared_functions[ $name ] ) ) {
			return;
		}

		$format = null;

		foreach ( $node->args as $index => $arg ) {
			if ( ! $arg instanceof Node\Arg || $arg->unpack ) {
				return;
			}

			if ( null === $arg->name ? 0 === $index : 'format' === strtolower( $arg->name->toString() ) ) {
				$format = $arg->value;
				break;
			}
		}

		if ( ! $format instanceof Node\Scalar\String_ ) {
			return;
		}

		$element = self::modified_element( $format->value );

		if ( null === $element ) {
			return;
		}

		$breaks = 'C' === $element[0];

		$finding           = $context->report(
			'argument.unpack_endianness_modifier',
			$node,
			$breaks ? array( 'breaks' => '8.6' ) : array( 'changed' => '8.6' )
		);
		$finding->subject  = "unpack( '" . $element . "' )";
		$finding->evidence = 'documented';
		$finding->instead  = $breaks
			? 'a name that does not start with "<" or ">", or a repeater before it ("' . $element[0] . '1' . substr( $element, 1 ) . '"); documented for PHP 8.6, not verified on an 8.6 runtime'
			: 'a repeater before the name ("' . $element[0] . '1' . substr( $element, 1 ) . '") keeps the key "' . substr( $element, 1 ) . '"; documented for PHP 8.6, not verified on an 8.6 runtime';
		$finding->guarded  = $context->function_is_guarded( self::VERSION_GUARD );
	}

	/**
	 * The first format element whose code is directly followed by "<" or ">".
	 *
	 * @param string $format unpack() format.
	 * @return string|null
	 */
	public static function modified_element( $format ) {
		foreach ( explode( '/', $format ) as $element ) {
			if ( strlen( $element ) < 2 ) {
				continue;
			}

			if ( ( '<' === $element[1] || '>' === $element[1] ) && ctype_alpha( $element[0] ) ) {
				return $element;
			}
		}

		return null;
	}
}
