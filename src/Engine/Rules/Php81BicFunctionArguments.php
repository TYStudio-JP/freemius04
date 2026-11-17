<?php
/**
 * Calls to internal functions whose result PHP 8.1 changes because of how
 * the arguments are written (tranche 8.1-bic).
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Scalar;

defined( 'ABSPATH' ) || exit;

/**
 * Rule ids:
 *
 * - argument.version_compare_operator       breaks in 8.1
 *       version_compare( $a, $b, 'l' )   the abbreviations l, g, e and n, and an
 *                                        empty operator, throw a ValueError
 * - argument.html_default_flags             changed in 8.1
 *       htmlspecialchars( "Don't" )      the default flags are now
 *                                        ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401
 *                                        instead of ENT_COMPAT | ENT_HTML401
 * - argument.openssl_pkcs7_default_cipher   changed in 8.1
 *       openssl_pkcs7_encrypt( $in, $out, $cert, $headers )
 *                                        encrypts with AES-128-CBC, not RC2-40
 *
 * For the HTML functions only calls without flags whose result is sure to
 * differ are reported: htmlspecialchars() and htmlentities() of a string
 * literal that contains ', the two decode functions of a string literal that
 * contains &#039; or &#39;, and get_html_translation_table() (its table
 * gains the entry for '). A variable may or may not hold such text, so it is
 * not reported (fixtures runtime.html_default_flags_variable and
 * runtime.html_malformed_utf8).
 *
 * All three are decided when the call runs, so a PHP version check around the
 * call marks the finding as guarded.
 */
final class Php81BicFunctionArguments implements Rule {

	/**
	 * Asked of Context::function_is_guarded() to learn about a version check
	 * only. Not a valid function name, so no function_exists() matches it.
	 */
	const VERSION_GUARD = '8.1-bic: version check only';

	/**
	 * Operators version_compare() accepted before 8.1 and no longer does =>
	 * the spelling that works on every version, or null when there is none.
	 */
	const OPERATORS = array(
		'l' => 'lt',
		'g' => 'gt',
		'e' => 'eq',
		'n' => 'ne',
		''  => null,
	);

	/**
	 * HTML functions => position of the $flags parameter.
	 */
	const HTML_FLAGS = array(
		'htmlspecialchars'           => 1,
		'htmlentities'               => 1,
		'htmlspecialchars_decode'    => 1,
		'html_entity_decode'         => 1,
		'get_html_translation_table' => 1,
	);

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Expr\FuncCall::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Expr\FuncCall $node    Function call.
	 * @param Context       $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		$name = InternalFunctions::global_name( $node->name );

		if ( null === $name || isset( $context->declared_functions[ $name ] ) ) {
			return;
		}

		if ( 'version_compare' !== $name && 'openssl_pkcs7_encrypt' !== $name && ! isset( self::HTML_FLAGS[ $name ] ) ) {
			return;
		}

		$positional = array();
		$named      = array();

		foreach ( $node->args as $arg ) {
			// A first-class callable (f(...)) or an unpacked array: arguments unknown.
			if ( ! $arg instanceof Node\Arg || $arg->unpack ) {
				return;
			}

			if ( null !== $arg->name ) {
				$named[ strtolower( $arg->name->toString() ) ] = $arg->value;
			} else {
				$positional[] = $arg->value;
			}
		}

		if ( 'version_compare' === $name ) {
			$operator = isset( $positional[2] ) ? $positional[2] : ( isset( $named['operator'] ) ? $named['operator'] : null );

			if ( $operator instanceof Scalar\String_ && array_key_exists( $operator->value, self::OPERATORS ) ) {
				$this->operator( $node, $operator, $context );
			}

			return;
		}

		if ( 'openssl_pkcs7_encrypt' === $name ) {
			if ( count( $positional ) < 6 && ! isset( $named['cipher_algo'] ) ) {
				$finding          = $context->report( 'argument.openssl_pkcs7_default_cipher', $node, array( 'changed' => '8.1' ) );
				$finding->subject = 'openssl_pkcs7_encrypt() without $cipher_algo';
				$finding->instead = 'openssl_pkcs7_encrypt( ..., $flags, OPENSSL_CIPHER_AES_128_CBC ) or another cipher named explicitly';
				$finding->guarded = $context->function_is_guarded( self::VERSION_GUARD );
			}

			return;
		}

		if ( isset( $positional[ self::HTML_FLAGS[ $name ] ] ) || isset( $named['flags'] ) ) {
			return;
		}

		if ( 'get_html_translation_table' !== $name ) {
			$text = isset( $positional[0] ) ? $positional[0] : ( isset( $named['string'] ) ? $named['string'] : null );

			if ( ! $text instanceof Scalar\String_ ) {
				return;
			}

			$decode = 'htmlspecialchars_decode' === $name || 'html_entity_decode' === $name;

			if ( $decode ? ( false === strpos( $text->value, '&#039;' ) && false === strpos( $text->value, '&#39;' ) ) : false === strpos( $text->value, "'" ) ) {
				return;
			}
		}

		$finding          = $context->report( 'argument.html_default_flags', $node, array( 'changed' => '8.1' ) );
		$finding->subject = $name . '() without $flags';
		$finding->instead = $name . '( ..., ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) for the new behaviour on every version, or ENT_COMPAT | ENT_HTML401 for the old one';
		$finding->guarded = $context->function_is_guarded( self::VERSION_GUARD );
	}

	/**
	 * Reports an operator that version_compare() no longer accepts.
	 *
	 * @param Expr\FuncCall  $call     The call.
	 * @param Scalar\String_ $operator The operator literal.
	 * @param Context        $context  Scan state.
	 */
	private function operator( Expr\FuncCall $call, Scalar\String_ $operator, Context $context ) {
		$full = self::OPERATORS[ $operator->value ];

		$finding          = $context->report( 'argument.version_compare_operator', $operator, array( 'breaks' => '8.1' ) );
		$finding->subject = "version_compare( ..., '" . $operator->value . "' )";
		$finding->instead = null === $full ? "one of '<', 'lt', '<=', 'le', '>', 'gt', '>=', 'ge', '==', 'eq', '!=', 'ne', or no operator" : "'" . $full . "'";
		$finding->guarded = $context->function_is_guarded( self::VERSION_GUARD );

		if ( null === $full ) {
			return;
		}

		$quote = $context->text( $operator->getStartFilePos(), $operator->getStartFilePos() );

		if ( "'" !== $quote && '"' !== $quote ) {
			return;
		}

		$finding->fix = array(
			'kind'  => 'replace',
			'start' => $operator->getStartFilePos(),
			'end'   => $operator->getEndFilePos(),
			'text'  => $quote . $full . $quote,
		);
	}
}
