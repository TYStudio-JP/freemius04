<?php
/**
 * Regular expressions with braces that PCRE2 10.44 reads as a quantifier.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id (tranche 8.4-bic-rest): argument.pcre_brace_quantifier. Changed in 8.4.
 *
 *     preg_match( '/^a{,3}$/', $text );
 *     preg_replace( '/x{ 1,3 }/', '-', $text );
 *
 * PHP 8.4 bundles PCRE2 10.44. It reads {,n} as the quantifier {0,n}, and,
 * which the notes do not say but the runtimes show, accepts spaces and tabs
 * inside the braces ({ 1,3 }, {2 }, {1, 3}). Up to 8.3 all of these were
 * literal text. Where nothing precedes the braces that could be repeated, the
 * pattern no longer compiles in 8.4: the function warns and returns false or
 * null (fixtures argument.pcre_brace_quantifier~8.4-bic-rest-*).
 *
 * Only a pattern written as a string literal (or an array of them) in the
 * pattern argument of a preg_* function, or a literal key of the array given
 * to preg_replace_callback_array(), is read. Escaped braces, character
 * classes, \Q...\E, (?#...) comments and the braces of escapes such as
 * \x{41} or \p{L} are skipped. A pattern with the x modifier, an inline (?x,
 * or "{" as its delimiter is not reported, because its text cannot be told
 * apart reliably. Braces that were already a quantifier before ({3}, {1,},
 * {1,3}) are not reported.
 *
 * Not detected: the changed meaning of some character classes in UCP mode
 * (the u modifier); which classes changed depends on the subject string.
 */
final class Php84BicRestPcreBraceQuantifier implements Rule {

	const VERSION_GUARD = '8.4-bic-rest: version check only';

	/**
	 * Functions whose first argument ($pattern) is a pattern or an array of them.
	 */
	const FUNCTIONS = array( 'preg_match', 'preg_match_all', 'preg_replace', 'preg_replace_callback', 'preg_filter', 'preg_split', 'preg_grep' );

	/**
	 * Escapes that take an argument in braces: \x{41}, \o{101}, \N{U+41},
	 * \p{L}, \P{L}, \g{1}, \k{name}.
	 */
	const BRACE_ESCAPES = 'xoNpPgk';

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

		if ( null === $name || isset( $context->declared_functions[ $name ] ) ) {
			return;
		}

		$patterns = array();

		if ( 'preg_replace_callback_array' === $name ) {
			$value = self::argument( $node, 'pattern' );

			if ( $value instanceof Node\Expr\Array_ ) {
				foreach ( $value->items as $item ) {
					if ( null !== $item && $item->key instanceof Node\Scalar\String_ ) {
						$patterns[] = $item->key->value;
					}
				}
			}
		} elseif ( in_array( $name, self::FUNCTIONS, true ) ) {
			$value = self::argument( $node, 'pattern' );

			if ( $value instanceof Node\Scalar\String_ ) {
				$patterns[] = $value->value;
			} elseif ( $value instanceof Node\Expr\Array_ ) {
				foreach ( $value->items as $item ) {
					if ( null !== $item && $item->value instanceof Node\Scalar\String_ ) {
						$patterns[] = $item->value->value;
					}
				}
			}
		} else {
			return;
		}

		foreach ( $patterns as $pattern ) {
			$braces = self::new_quantifier( $pattern );

			if ( null !== $braces ) {
				$finding          = $context->report( 'argument.pcre_brace_quantifier', $node, array( 'changed' => '8.4' ) );
				$finding->subject = $name . '() with ' . $braces . ', a quantifier from PHP 8.4 (literal text before)';
				$finding->instead = 'escape the brace as \\{ to keep the text literal, or write the quantifier without spaces and with its minimum, such as {0,3}';
				$finding->guarded = $context->function_is_guarded( self::VERSION_GUARD );

				return;
			}
		}
	}

	/**
	 * The first argument of a call, by position or by the given name.
	 *
	 * @param Node\Expr\FuncCall $node Call.
	 * @param string             $name Parameter name.
	 * @return Node\Expr|null
	 */
	private static function argument( Node\Expr\FuncCall $node, $name ) {
		foreach ( $node->args as $index => $arg ) {
			if ( ! $arg instanceof Node\Arg || $arg->unpack ) {
				return null;
			}

			if ( null === $arg->name ? 0 === $index : $name === strtolower( $arg->name->toString() ) ) {
				return $arg->value;
			}
		}

		return null;
	}

	/**
	 * The first braces in a complete pattern (with delimiters and modifiers)
	 * that PCRE2 10.44 reads as a quantifier and older versions read as text,
	 * or null when there are none or the pattern is not judged.
	 *
	 * @param string $pattern Pattern as PHP sees it.
	 * @return string|null
	 */
	public static function new_quantifier( $pattern ) {
		$pattern = ltrim( $pattern );

		if ( '' === $pattern ) {
			return null;
		}

		$open = $pattern[0];

		// With braces as delimiters, the braces inside are counted by PHP.
		if ( ctype_alnum( $open ) || '\\' === $open || '{' === $open ) {
			return null;
		}

		$pairs = array(
			'(' => ')',
			'[' => ']',
			'<' => '>',
		);
		$close = isset( $pairs[ $open ] ) ? $pairs[ $open ] : $open;
		$end   = strrpos( $pattern, $close );

		if ( false === $end || 0 === $end ) {
			return null;
		}

		$body      = substr( $pattern, 1, $end - 1 );
		$modifiers = substr( $pattern, $end + 1 );

		if ( false === strpos( $body, '{' ) ) {
			return null;
		}

		// In extended mode a "#" starts a comment, which may hold any text.
		if ( false !== strpos( $modifiers, 'x' ) || preg_match( '/\(\?\^?[a-zA-Z-]*x/', $body ) ) {
			return null;
		}

		$length = strlen( $body );
		$i      = 0;

		while ( $i < $length ) {
			$char = $body[ $i ];

			if ( '\\' === $char ) {
				$next = $i + 1 < $length ? $body[ $i + 1 ] : '';

				if ( 'Q' === $next ) {
					$quoted = strpos( $body, '\\E', $i + 2 );
					$i      = false === $quoted ? $length : $quoted + 2;
					continue;
				}

				if ( '' !== $next && false !== strpos( self::BRACE_ESCAPES, $next ) && $i + 2 < $length && '{' === $body[ $i + 2 ] ) {
					$closing = strpos( $body, '}', $i + 3 );
					$i       = false === $closing ? $length : $closing + 1;
					continue;
				}

				// \c takes the next character, whatever it is.
				$i += 'c' === $next ? 3 : 2;
				continue;
			}

			if ( '[' === $char ) {
				$i = self::skip_class( $body, $i );
				continue;
			}

			if ( '(' === $char && '?#' === substr( $body, $i + 1, 2 ) ) {
				$comment = strpos( $body, ')', $i );
				$i       = false === $comment ? $length : $comment + 1;
				continue;
			}

			if ( '{' === $char && preg_match( '/\G\{[ \t]*(?:\d+[ \t]*(?:,[ \t]*\d*[ \t]*)?|,[ \t]*\d+[ \t]*)\}/', $body, $match, 0, $i ) ) {
				if ( ! preg_match( '/^\{\d+(?:,\d*)?\}$/', $match[0] ) ) {
					return $match[0];
				}

				$i += strlen( $match[0] );
				continue;
			}

			++$i;
		}

		return null;
	}

	/**
	 * Offset just after the character class that starts at $start.
	 *
	 * @param string $body  Pattern body.
	 * @param int    $start Offset of "[".
	 * @return int
	 */
	private static function skip_class( $body, $start ) {
		$length = strlen( $body );
		$i      = $start + 1;

		if ( $i < $length && '^' === $body[ $i ] ) {
			++$i;
		}

		// A "]" right at the start is a literal.
		if ( $i < $length && ']' === $body[ $i ] ) {
			++$i;
		}

		while ( $i < $length ) {
			$char = $body[ $i ];

			if ( '\\' === $char ) {
				$i += 2;
				continue;
			}

			if ( '[' === $char && $i + 1 < $length && ':' === $body[ $i + 1 ] ) {
				$posix = strpos( $body, ':]', $i + 2 );

				if ( false !== $posix ) {
					$i = $posix + 2;
					continue;
				}
			}

			if ( ']' === $char ) {
				return $i + 1;
			}

			++$i;
		}

		return $length;
	}
}
