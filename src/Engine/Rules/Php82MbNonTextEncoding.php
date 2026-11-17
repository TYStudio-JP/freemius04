<?php
/**
 * Mbstring functions asked for Base64, QPrint, Uuencode or HTML-ENTITIES.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Scalar\String_;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: argument.mb_non_text_encoding. Deprecated in 8.2.
 *
 *     mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' );
 *
 * Reported when the encoding argument of a function in ENCODING_AT is one of
 * the names in INSTEAD, written as a string literal. Every function and every
 * name has a fixture.
 *
 * The notes say "all Mbstring functions". The runtimes showed otherwise:
 *
 * - as the SOURCE encoding of mb_convert_encoding() (third argument) the names
 *   raise nothing (fixture runtime.mb_convert_encoding_from_non_text), so the
 *   third argument is not looked at;
 * - mb_detect_encoding(), mb_detect_order(), mb_http_output(),
 *   mb_internal_encoding() and mb_preferred_mime_name() raise nothing
 *   (fixtures runtime.mb_non_text_encoding_not_raised) and are not listed.
 *
 * function_exists() around the call keeps nothing away; only a PHP version
 * check marks the finding as guarded.
 */
final class Php82MbNonTextEncoding implements Rule {

	/**
	 * Function => position of the encoding argument.
	 */
	const ENCODING_AT = array(
		'mb_convert_encoding'     => 1,
		'mb_convert_variables'    => 0,
		'mb_encoding_aliases'     => 0,
		'mb_check_encoding'       => 1,
		'mb_strlen'               => 1,
		'mb_strtolower'           => 1,
		'mb_strtoupper'           => 1,
		'mb_strwidth'             => 1,
		'mb_scrub'                => 1,
		'mb_encode_mimeheader'    => 1,
		'mb_convert_case'         => 2,
		'mb_substr_count'         => 2,
		'mb_str_split'            => 2,
		'mb_encode_numericentity' => 2,
		'mb_decode_numericentity' => 2,
		'mb_substr'               => 3,
		'mb_strcut'               => 3,
		'mb_strpos'               => 3,
		'mb_strrpos'              => 3,
		'mb_stripos'              => 3,
		'mb_strripos'             => 3,
		'mb_strstr'               => 3,
		'mb_strrchr'              => 3,
		'mb_stristr'              => 3,
		'mb_strrichr'             => 3,
		'mb_strimwidth'           => 4,
	);

	/**
	 * Encoding name, lower case => what PHP's own notice recommends.
	 */
	const INSTEAD = array(
		'html-entities'    => 'htmlspecialchars(), htmlentities(), or mb_encode_numericentity() / mb_decode_numericentity()',
		'html'             => 'htmlspecialchars(), htmlentities(), or mb_encode_numericentity() / mb_decode_numericentity()',
		'base64'           => 'base64_encode() / base64_decode()',
		'qprint'           => 'quoted_printable_encode() / quoted_printable_decode()',
		'quoted-printable' => 'quoted_printable_encode() / quoted_printable_decode()',
		'uuencode'         => 'convert_uuencode() / convert_uudecode()',
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

		if ( null === $name || ! isset( self::ENCODING_AT[ $name ] ) || isset( $context->declared_functions[ $name ] ) ) {
			return;
		}

		$at = self::ENCODING_AT[ $name ];

		if ( ! isset( $node->args[ $at ] ) ) {
			return;
		}

		// With named or spread arguments the position says nothing.
		for ( $index = 0; $index <= $at; $index++ ) {
			$arg = $node->args[ $index ];

			if ( ! $arg instanceof Node\Arg || $arg->unpack || null !== $arg->name ) {
				return;
			}
		}

		$value = $node->args[ $at ]->value;

		if ( ! $value instanceof String_ || ! isset( self::INSTEAD[ strtolower( $value->value ) ] ) ) {
			return;
		}

		$finding          = $context->report( 'argument.mb_non_text_encoding', $node, array( 'deprecated' => '8.2' ) );
		$finding->subject = $name . ': ' . $value->value;
		$finding->instead = self::INSTEAD[ strtolower( $value->value ) ];
		$finding->guarded = $context->function_is_guarded( Php82TentativeReturnType::VERSION_GUARD );
	}
}
