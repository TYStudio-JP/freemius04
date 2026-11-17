<?php
/**
 * Method names given as plain strings to the XML parser, deprecated in PHP 8.4.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: call.xml_handler_method_name.
 *
 *     xml_set_object( $parser, $this );
 *     xml_set_element_handler( $parser, 'tag_open', 'tag_close' );
 *
 * The strings name methods of the object given to xml_set_object(); on their
 * own they are not callable. PHP 8.4 wants array( $this, 'tag_open' ).
 *
 * A string that names a function is fine and looks the same, so a call is
 * reported only when all of this holds:
 *
 * - the handler is a string literal that could be a method name,
 * - no function of that name is declared in the plugin or theme,
 * - the file mentions xml_set_object, without which a method name never worked.
 *
 * Each function listed in HANDLERS has a fixture of its own.
 *
 * Not visible here: when the handlers are set BEFORE xml_set_object(), PHP 8.4
 * throws a ValueError instead (fixture runtime.xml_handler_string~before_object).
 */
final class Php84DepXmlHandlerMethodName implements Rule {

	/**
	 * Number of handler arguments after the parser.
	 */
	const HANDLERS = array(
		'xml_set_element_handler'                => 2,
		'xml_set_character_data_handler'         => 1,
		'xml_set_default_handler'                => 1,
		'xml_set_processing_instruction_handler' => 1,
		'xml_set_start_namespace_decl_handler'   => 1,
		'xml_set_end_namespace_decl_handler'     => 1,
		'xml_set_external_entity_ref_handler'    => 1,
		'xml_set_notation_decl_handler'          => 1,
		'xml_set_unparsed_entity_decl_handler'   => 1,
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

		if ( null === $name || ! isset( self::HANDLERS[ $name ] ) || isset( $context->declared_functions[ $name ] ) ) {
			return;
		}

		if ( count( $node->args ) !== self::HANDLERS[ $name ] + 1 || false === stripos( $context->code, 'xml_set_object' ) ) {
			return;
		}

		$methods = array();

		foreach ( $node->args as $index => $arg ) {
			if ( ! $arg instanceof Node\Arg || $arg->unpack || null !== $arg->name ) {
				return;
			}

			if ( 0 === $index || ! $arg->value instanceof Node\Scalar\String_ ) {
				continue;
			}

			$handler = $arg->value->value;

			if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $handler ) ) {
				continue;
			}

			// A function of that name: declared by the code being scanned, or
			// known to the PHP that runs the scan (an internal function, or one
			// of WordPress). Then the string is callable as it is.
			if ( isset( $context->declared_functions[ strtolower( $handler ) ] ) || function_exists( $handler ) ) {
				continue;
			}

			$methods[] = $handler;
		}

		if ( array() === $methods ) {
			return;
		}

		$finding          = $context->report( 'call.xml_handler_method_name', $node, array( 'deprecated' => '8.4' ) );
		$finding->subject = $name . ': ' . implode( ', ', $methods );
		$finding->instead = 'array( $object, \'' . $methods[0] . '\' )';
		// Only a PHP version check keeps the notice away: function_exists() for the function itself
		// is true on every version and guards nothing (seen on the 8.4 runtime).
		$finding->guarded = $context->function_is_guarded( '8.4-dep: version check only' );
	}
}
