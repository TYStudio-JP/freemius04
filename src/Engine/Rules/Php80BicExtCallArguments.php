<?php
/**
 * Calls to standard, mbstring, GD, OpenSSL and Date functions whose literal
 * arguments, or missing arguments, PHP 8.0 no longer accepts or reads
 * differently (tranche 8.0-bic-ext).
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
 * Rule ids, each verified by its fixtures on PHP 7.4 to 8.5:
 *
 * - call.mktime_without_argument        mktime(), gmmktime(): deprecated 7.4, ArgumentCountError from 8.0
 * - call.parse_str_without_result       parse_str( $q ): deprecated 7.2; mb_parse_str( $q ): deprecated 7.4
 *                                       (the 7.x notes do not date it); ArgumentCountError from 8.0
 * - argument.imagecropauto_minus_one    imagecropauto( $im, -1 ): deprecated 7.4, ValueError from 8.0
 * - argument.needle_not_string          strpos( $s, 7 ): deprecated 7.3, read as "7" from 8.0
 * - argument.empty_needle               strpos( $s, '' ): false (and a warning) on 7.4, a position from 8.0
 * - call.mb_decode_numericentity_is_hex a fourth argument: ArgumentCountError from 8.0
 * - argument.mb_strrpos_encoding        mb_strrpos( $s, $n, 'UTF-8' ): deprecated 7.4, TypeError from 8.0
 * - argument.mb_iso8859_alias           'ISO_8859-1' to mb_*(): ValueError from 8.0
 * - call.openssl_seal_without_method    openssl_seal()/openssl_open() without $method: ArgumentCountError from 8.0
 * - call.assert_string                  assert( '...' ): deprecated 7.2, not evaluated (so passes) from 8.0
 * - argument.strip_tags_filter          the string.strip_tags filter: deprecated 7.3, missing from 8.0
 * - argument.length_null                substr( $s, 1, null ) and friends: '' on 7.4, the rest of the string from 8.0
 * - argument.vsprintf_non_array         vsprintf( $f, 'x' ): TypeError from 8.0
 * - argument.password_hash_salt         array( 'salt' => ... ): deprecated 7.4, ignored from 8.0
 * - call.implode_reversed               implode( $pieces, ', ' ): deprecated 7.4, TypeError from 8.0
 * - call.crypt_without_salt             crypt( $s ): ArgumentCountError from 8.0
 *
 * The 'e' option and an int pattern of mb_ereg_replace(), and the
 * ISO8859_1 names for mb_regex_encoding(), are not reported here: every call
 * to the mb_ereg*() functions is already reported as deprecated in 8.6 by the
 * catalog, and a fixture for them could not show one rule alone (fixtures
 * runtime.mb_ereg_replace_e_option, runtime.mb_ereg_replace_int_pattern and
 * runtime.mb_regex_iso8859_alias hold the evidence).
 *
 * Only literals are judged; a variable holding the same value is a run-time
 * matter. A call is skipped when it unpacks or names arguments, or when the
 * code declares a function of that name.
 */
final class Php80BicExtCallArguments implements Rule {

	/**
	 * Functions whose second argument is a needle that was read as a code
	 * point when it was an int.
	 */
	const NEEDLE_INT = array( 'strpos', 'strrpos', 'stripos', 'strripos', 'strstr', 'strchr', 'strrchr', 'stristr' );

	/**
	 * Functions that returned false for an empty needle and give a result
	 * from 8.0. strrchr() is not one: it returns false on every runtime.
	 */
	const NEEDLE_EMPTY = array(
		'strpos',
		'strrpos',
		'stripos',
		'strripos',
		'strstr',
		'strchr',
		'stristr',
		'mb_strpos',
		'mb_strrpos',
		'mb_stripos',
		'mb_strripos',
		'mb_strstr',
		'mb_stristr',
		'mb_strrchr',
		'mb_strrichr',
	);

	/**
	 * Functions with a length argument that null now leaves out, and its
	 * position. substr_compare() is not one: it behaved the same on 7.4.
	 */
	const LENGTH_NULL = array(
		'substr'       => 2,
		'substr_count' => 3,
		'iconv_substr' => 2,
		'array_splice' => 2,
	);

	/**
	 * Position of the values argument of the v*printf() functions.
	 */
	const VALUES = array(
		'vsprintf' => 1,
		'vprintf'  => 1,
		'vfprintf' => 2,
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

		$args = self::plain_args( $node );

		if ( null === $args ) {
			return;
		}

		$count = count( $args );

		if ( ( 'mktime' === $name || 'gmmktime' === $name ) && 0 === $count ) {
			$finding          = $context->report( 'call.mktime_without_argument', $node, array( 'deprecated' => '7.4', 'breaks' => '8.0' ) );
			$finding->subject = $name . '()';
			$finding->instead = 'time()';
			$finding->fix     = array(
				'kind'  => 'replace',
				'start' => $node->getStartFilePos(),
				'end'   => $node->getEndFilePos(),
				'text'  => 'time()',
			);
			return;
		}

		if ( ( 'parse_str' === $name || 'mb_parse_str' === $name ) && 1 === $count ) {
			// The 7.2 notes deprecate parse_str() only; mb_parse_str() is dated by the 7.4 runtime.
			$finding          = $context->report( 'call.parse_str_without_result', $node, array( 'deprecated' => 'parse_str' === $name ? '7.2' : '7.4', 'breaks' => '8.0' ) );
			$finding->subject = $name . '() without a result array';
			$finding->instead = $name . '( $query, $result )';
			return;
		}

		if ( 'imagecropauto' === $name && $count >= 2 && self::is_minus_one( $args[1] ) ) {
			$finding          = $context->report( 'argument.imagecropauto_minus_one', $args[1], array( 'deprecated' => '7.4', 'breaks' => '8.0' ) );
			$finding->subject = 'imagecropauto( ..., -1 )';
			$finding->instead = 'IMG_CROP_DEFAULT';
			$finding->fix     = array(
				'kind'  => 'replace',
				'start' => $args[1]->getStartFilePos(),
				'end'   => $args[1]->getEndFilePos(),
				'text'  => 'IMG_CROP_DEFAULT',
			);
			return;
		}

		if ( in_array( $name, self::NEEDLE_INT, true ) && $count >= 2 && $args[1] instanceof Scalar\Int_ ) {
			$finding          = $context->report( 'argument.needle_not_string', $args[1], array( 'deprecated' => '7.3', 'changed' => '8.0' ) );
			$finding->subject = $name . '( ..., ' . $args[1]->value . ' )';
			$finding->instead = 'chr( ' . $args[1]->value . ' ) for the code point, or \'' . $args[1]->value . '\'';
			return;
		}

		if ( in_array( $name, self::NEEDLE_EMPTY, true ) && $count >= 2 && $args[1] instanceof Scalar\String_ && '' === $args[1]->value ) {
			$finding          = $context->report( 'argument.empty_needle', $args[1], array( 'changed' => '8.0' ) );
			$finding->subject = $name . '( ..., \'\' )';
			return;
		}

		if ( 'mb_decode_numericentity' === $name && 4 === $count ) {
			$finding          = $context->report( 'call.mb_decode_numericentity_is_hex', $args[3], array( 'breaks' => '8.0' ) );
			$finding->subject = 'mb_decode_numericentity() with $is_hex';
			$finding->instead = 'three arguments';
			$finding->fix     = array(
				'kind'  => 'replace',
				'start' => $args[2]->getEndFilePos() + 1,
				'end'   => $args[3]->getEndFilePos(),
				'text'  => '',
			);
			return;
		}

		if ( 'mb_strrpos' === $name && 3 === $count && $args[2] instanceof Scalar\String_ && ! is_numeric( $args[2]->value ) ) {
			$finding          = $context->report( 'argument.mb_strrpos_encoding', $args[2], array( 'deprecated' => '7.4', 'breaks' => '8.0' ) );
			$finding->subject = 'mb_strrpos( ..., \'' . $args[2]->value . '\' )';
			$finding->instead = 'mb_strrpos( $haystack, $needle, 0, \'' . $args[2]->value . '\' )';
			$finding->fix     = array(
				'kind' => 'insert',
				'at'   => $args[2]->getStartFilePos(),
				'text' => '0, ',
			);
			return;
		}

		if ( 0 === strpos( $name, 'mb_' ) && $this->iso8859_alias( $name, $args, $node, $context ) ) {
			return;
		}

		if ( ( 'openssl_seal' === $name || 'openssl_open' === $name ) && 4 === $count ) {
			$finding          = $context->report( 'call.openssl_seal_without_method', $node, array( 'breaks' => '8.0' ) );
			$finding->subject = $name . '() without $cipher_algo';
			$finding->instead = 'a fifth argument naming the cipher, for example \'AES-256-CBC\' (and $iv)';
			return;
		}

		if ( 'assert' === $name && $count >= 1 && $args[0] instanceof Scalar\String_ ) {
			$finding          = $context->report( 'call.assert_string', $args[0], array( 'deprecated' => '7.2', 'changed' => '8.0' ) );
			$finding->subject = 'assert( \'' . $args[0]->value . '\' )';
			$finding->instead = 'assert( ' . $args[0]->value . ' )';
			return;
		}

		if ( $this->strip_tags_filter( $name, $args, $context ) ) {
			return;
		}

		if ( isset( self::LENGTH_NULL[ $name ] ) && $count > self::LENGTH_NULL[ $name ] && self::is_null( $args[ self::LENGTH_NULL[ $name ] ] ) ) {
			$finding          = $context->report( 'argument.length_null', $args[ self::LENGTH_NULL[ $name ] ], array( 'changed' => '8.0' ) );
			$finding->subject = $name . '() with a null length';
			$finding->instead = 'leave the length out for the rest, or 0 for nothing';
			return;
		}

		if ( isset( self::VALUES[ $name ] ) && $count > self::VALUES[ $name ] ) {
			$values = $args[ self::VALUES[ $name ] ];

			if ( $values instanceof Scalar\String_ || $values instanceof Scalar\Int_ || $values instanceof Scalar\Float_ ) {
				$text             = $context->text( $values->getStartFilePos(), $values->getEndFilePos() );
				$finding          = $context->report( 'argument.vsprintf_non_array', $values, array( 'breaks' => '8.0' ) );
				$finding->subject = $name . '() with ' . $text . ' as its values';
				$finding->instead = 'array( ' . $text . ' )';
				$finding->fix     = array(
					'kind'  => 'replace',
					'start' => $values->getStartFilePos(),
					'end'   => $values->getEndFilePos(),
					'text'  => 'array( ' . $text . ' )',
				);
				return;
			}
		}

		if ( 'password_hash' === $name && $count >= 3 && $args[2] instanceof Expr\Array_ ) {
			foreach ( $args[2]->items as $item ) {
				if ( null !== $item && $item->key instanceof Scalar\String_ && 'salt' === $item->key->value ) {
					$finding          = $context->report( 'argument.password_hash_salt', $item, array( 'deprecated' => '7.4', 'changed' => '8.0' ) );
					$finding->subject = 'password_hash() with a salt option';
					$finding->instead = 'leave the salt out; password_hash() makes one';
					return;
				}
			}
		}

		if ( ( 'implode' === $name || 'join' === $name ) && 2 === $count && self::reversed( $args[0], $args[1] ) ) {
			$first            = $context->text( $args[0]->getStartFilePos(), $args[0]->getEndFilePos() );
			$second           = $context->text( $args[1]->getStartFilePos(), $args[1]->getEndFilePos() );
			$finding          = $context->report( 'call.implode_reversed', $node, array( 'deprecated' => '7.4', 'breaks' => '8.0' ) );
			$finding->subject = $name . '( $pieces, $glue )';
			$finding->instead = $name . '( ' . $second . ', ' . $first . ' )';
			$finding->fix     = array(
				'kind'  => 'replace',
				'start' => $args[0]->getStartFilePos(),
				'end'   => $args[1]->getEndFilePos(),
				'text'  => $second . ', ' . $first,
			);
			return;
		}

		if ( 'crypt' === $name && 1 === $count ) {
			$finding          = $context->report( 'call.crypt_without_salt', $node, array( 'breaks' => '8.0' ) );
			$finding->subject = 'crypt() without a salt';
			$finding->instead = 'password_hash()';
		}
	}

	/**
	 * The argument values of a call, or null when it unpacks, names an
	 * argument or is a first-class callable.
	 *
	 * @param Expr\FuncCall $node Call.
	 * @return Expr[]|null
	 */
	private static function plain_args( Expr\FuncCall $node ) {
		$values = array();

		foreach ( $node->args as $arg ) {
			if ( ! $arg instanceof Node\Arg || $arg->unpack || null !== $arg->name ) {
				return null;
			}

			$values[] = $arg->value;
		}

		return $values;
	}

	/**
	 * Whether the expression is the literal -1.
	 *
	 * @param Expr $value Expression.
	 * @return bool
	 */
	private static function is_minus_one( Expr $value ) {
		return $value instanceof Expr\UnaryMinus && $value->expr instanceof Scalar\Int_ && 1 === $value->expr->value;
	}

	/**
	 * Whether the expression is the constant null.
	 *
	 * @param Expr $value Expression.
	 * @return bool
	 */
	private static function is_null( Expr $value ) {
		return $value instanceof Expr\ConstFetch && 'null' === $value->name->toLowerString();
	}

	/**
	 * Whether implode( $first, $second ) has its arguments the old way round:
	 * a string literal second, or an array literal first and no array second.
	 *
	 * @param Expr $first  First argument.
	 * @param Expr $second Second argument.
	 * @return bool
	 */
	private static function reversed( Expr $first, Expr $second ) {
		$is_string = static function ( $value ) {
			return $value instanceof Scalar\String_ || $value instanceof Scalar\InterpolatedString;
		};

		if ( $is_string( $second ) ) {
			return ! $is_string( $first );
		}

		return $first instanceof Expr\Array_ && ! $second instanceof Expr\Array_;
	}

	/**
	 * Reports an ISO 8859 encoding name that mbstring no longer knows.
	 *
	 * @param string        $name    Lower-case function name, starting with mb_.
	 * @param Expr[]        $args    Argument values.
	 * @param Expr\FuncCall $node    Call.
	 * @param Context       $context Scan state.
	 * @return bool Whether something was reported.
	 */
	private function iso8859_alias( $name, array $args, Expr\FuncCall $node, Context $context ) {
		if ( 0 === strpos( $name, 'mb_ereg' ) || 'mb_regex_encoding' === $name || 'mb_regex_set_options' === $name ) {
			return false;
		}

		$pattern = '/^ISO_8859-(\d+)$/i';

		foreach ( $args as $value ) {
			if ( ! $value instanceof Scalar\String_ || ! preg_match( $pattern, $value->value, $match ) ) {
				continue;
			}

			$finding          = $context->report( 'argument.mb_iso8859_alias', $value, array( 'breaks' => '8.0' ) );
			$finding->subject = $name . '( \'' . $value->value . '\' )';
			$finding->instead = '\'ISO-8859-' . $match[1] . '\'';
			$finding->fix     = array(
				'kind'  => 'replace',
				'start' => $value->getStartFilePos(),
				'end'   => $value->getEndFilePos(),
				'text'  => '\'ISO-8859-' . $match[1] . '\'',
			);

			return true;
		}

		return false;
	}

	/**
	 * Reports the string.strip_tags stream filter, appended by name or used
	 * in a php://filter URL.
	 *
	 * @param string  $name    Lower-case function name.
	 * @param Expr[]  $args    Argument values.
	 * @param Context $context Scan state.
	 * @return bool Whether something was reported.
	 */
	private function strip_tags_filter( $name, array $args, Context $context ) {
		foreach ( $args as $index => $value ) {
			if ( ! $value instanceof Scalar\String_ ) {
				continue;
			}

			$text   = strtolower( $value->value );
			$append = 1 === $index && ( 'stream_filter_append' === $name || 'stream_filter_prepend' === $name ) && 'string.strip_tags' === $text;
			$url    = 0 === strpos( $text, 'php://filter/' ) && preg_match( '#[/=|]string\.strip_tags(?:[/|]|$)#', $text );

			if ( $append || $url ) {
				$finding          = $context->report( 'argument.strip_tags_filter', $value, array( 'deprecated' => '7.3', 'changed' => '8.0' ) );
				$finding->subject = 'the string.strip_tags filter';
				$finding->instead = 'strip_tags() on the data';

				return true;
			}
		}

		return false;
	}
}
