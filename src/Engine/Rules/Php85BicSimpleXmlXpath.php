<?php
/**
 * SimpleXMLElement::xpath() with an expression that cannot return nodes.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id (tranche 8.5-bic): call.simplexml_xpath_non_node_set. Changed in 8.5.
 *
 *     $total = $xml->xpath( 'count(//item)' );
 *
 * PHP 7.4 to 8.4 returned an empty array; 8.5 raises a warning ("XPath
 * expression must return a node set, number returned") and returns false
 * (fixtures call.simplexml_xpath_non_node_set~8.5-bic-*).
 *
 * Reported for a ->xpath() call whose only argument is a string literal that
 * starts with a call to an XPath function returning a number, a string or a
 * boolean (count(), string(), sum(), boolean(), ...). An XPath expression that
 * starts with such a call can never be a node set, whatever follows it. The
 * class of the object is not known; the method name, the single argument and
 * the XPath function together make anything but SimpleXML very unlikely.
 * XPath function names are case-sensitive: COUNT(...) is an invalid
 * expression on every version and is not reported.
 */
final class Php85BicSimpleXmlXpath implements Rule {

	const VERSION_GUARD = '8.5-bic: version check only';

	/**
	 * XPath 1.0 functions that return a number, a string or a boolean.
	 */
	const SCALAR_FUNCTIONS = array(
		'count',
		'sum',
		'string',
		'number',
		'boolean',
		'not',
		'true',
		'false',
		'concat',
		'contains',
		'starts-with',
		'string-length',
		'normalize-space',
		'substring',
		'substring-before',
		'substring-after',
		'translate',
		'round',
		'floor',
		'ceiling',
		'last',
		'position',
		'local-name',
		'name',
		'namespace-uri',
		'lang',
	);

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Expr\MethodCall::class, Node\Expr\NullsafeMethodCall::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Expr\MethodCall $node    Method call.
	 * @param Context              $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( ! $node->name instanceof Node\Identifier || 'xpath' !== strtolower( $node->name->toString() ) || 1 !== count( $node->args ) ) {
			return;
		}

		$arg = $node->args[0];

		if ( ! $arg instanceof Node\Arg || $arg->unpack || ! $arg->value instanceof Node\Scalar\String_ ) {
			return;
		}

		if ( ! preg_match( '/^\s*([a-z][a-z-]*)\s*\(/', $arg->value->value, $match ) || ! in_array( $match[1], self::SCALAR_FUNCTIONS, true ) ) {
			return;
		}

		$finding          = $context->report( 'call.simplexml_xpath_non_node_set', $node, array( 'changed' => '8.5' ) );
		$finding->subject = 'xpath( \'' . $match[1] . '(...)\' )';
		$finding->instead = 'select the nodes with xpath() and compute in PHP, for example count( $xml->xpath( \'//item\' ) )';
		$finding->guarded = $context->function_is_guarded( self::VERSION_GUARD );
	}
}
