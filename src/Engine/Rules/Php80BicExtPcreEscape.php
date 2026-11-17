<?php
/**
 * Regular expressions with an escape sequence that PCRE no longer reads as a
 * literal letter (tranche 8.0-bic-ext).
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
 * Rule id: argument.pcre_invalid_escape. Changed in 8.0.
 *
 *     preg_match( '/^\i\d+$/', $id );   // matched "i42" on 7.4; false and a warning from 8.0
 *
 * Up to 7.4 PCRE read an unknown escape such as \i as the letter itself;
 * from 8.0 the pattern does not compile, so preg_*() return false (or null)
 * with a warning.
 *
 * Only the letters that compiled on 7.4 and fail from 8.0 on the runtimes,
 * both outside and inside a character class, are looked for: i j l m q u y
 * F I J L M O T U Y. Letters with a meaning when followed by more (\c, \g,
 * \k, \o, \p, \N ...) are left alone.
 *
 * Judged: the pattern argument of preg_*() when it is a string literal, or an
 * array literal of string literals (keys for preg_replace_callback_array()).
 * Skipped: patterns with the x flag or an inline (?x) or (?#...) part, where
 * a backslash may sit in a comment, and \Q...\E spans.
 */
final class Php80BicExtPcreEscape implements Rule {

	/**
	 * Functions whose first argument is a pattern or an array of patterns.
	 */
	const FUNCTIONS = array(
		'preg_match'                 => true,
		'preg_match_all'             => true,
		'preg_replace'               => true,
		'preg_replace_callback'      => true,
		'preg_split'                 => true,
		'preg_grep'                  => true,
		'preg_filter'                => true,
		'preg_replace_callback_array' => true,
	);

	/**
	 * Escaped letters that were literals up to 7.4 and are errors from 8.0.
	 */
	const INVALID = 'ijlmquyFIJLMOTUY';

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

		if ( null === $name || ! isset( self::FUNCTIONS[ $name ] ) || isset( $context->declared_functions[ $name ] ) ) {
			return;
		}

		if ( 0 === count( $node->args ) || ! $node->args[0] instanceof Node\Arg || $node->args[0]->unpack || null !== $node->args[0]->name ) {
			return;
		}

		$value    = $node->args[0]->value;
		$patterns = array();

		if ( $value instanceof Scalar\String_ ) {
			$patterns[] = $value;
		} elseif ( $value instanceof Expr\Array_ ) {
			foreach ( $value->items as $item ) {
				if ( null === $item ) {
					continue;
				}

				$pattern = 'preg_replace_callback_array' === $name ? $item->key : $item->value;

				if ( $pattern instanceof Scalar\String_ ) {
					$patterns[] = $pattern;
				}
			}
		}

		foreach ( $patterns as $pattern ) {
			$escape = self::invalid_escape( $pattern->value );

			if ( null === $escape ) {
				continue;
			}

			$finding          = $context->report( 'argument.pcre_invalid_escape', $pattern, array( 'changed' => '8.0' ) );
			$finding->subject = $name . '() pattern with \\' . $escape;
			$finding->instead = $escape . ' without the backslash';
		}
	}

	/**
	 * The first letter of an escape sequence in the pattern that PCRE no
	 * longer accepts, or null.
	 *
	 * @param string $regex Pattern with delimiters and flags.
	 * @return string|null
	 */
	public static function invalid_escape( $regex ) {
		$regex = ltrim( $regex );

		if ( strlen( $regex ) < 2 ) {
			return null;
		}

		$open = $regex[0];

		if ( ctype_alnum( $open ) || '\\' === $open || ctype_space( $open ) ) {
			return null;
		}

		$pairs = array(
			'(' => ')',
			'[' => ']',
			'{' => '}',
			'<' => '>',
		);
		$close = isset( $pairs[ $open ] ) ? $pairs[ $open ] : $open;
		$end   = strrpos( $regex, $close );

		if ( false === $end || 0 === $end ) {
			return null;
		}

		$body  = substr( $regex, 1, $end - 1 );
		$flags = substr( $regex, $end + 1 );

		if ( false !== strpos( $flags, 'x' ) || preg_match( '/\(\?[a-zA-Z^-]*x/', $body ) || false !== strpos( $body, '(?#' ) ) {
			return null;
		}

		$length = strlen( $body );

		for ( $at = 0; $at < $length; $at++ ) {
			if ( '\\' !== $body[ $at ] || $at + 1 >= $length ) {
				continue;
			}

			$next = $body[ $at + 1 ];

			if ( 'Q' === $next ) {
				$quoted = strpos( $body, '\\E', $at + 2 );

				if ( false === $quoted ) {
					return null;
				}

				$at = $quoted + 1;
				continue;
			}

			if ( 'c' === $next ) {
				// \cX: the character after \c is part of the escape.
				$at += 2;
				continue;
			}

			if ( false !== strpos( self::INVALID, $next ) ) {
				return $next;
			}

			// Any other escape, \\ included: step over the escaped character.
			$at++;
		}

		return null;
	}
}
