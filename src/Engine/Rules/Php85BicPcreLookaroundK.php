<?php
/**
 * Regular expressions with \K inside a lookaround.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id (tranche 8.5-bic): argument.pcre_k_in_lookaround. Changed in 8.5.
 *
 *     preg_replace( '/(?<=\d\K)px/', 'em', $css );
 *
 * PHP 8.5 builds PCRE2 without PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK. Such a pattern
 * no longer compiles: the preg_* function raises a warning and returns false
 * or null, where 7.4 to 8.4 matched (fixtures argument.pcre_k_in_lookaround~8.5-bic-*).
 *
 * Only a pattern written as a string literal (or an array of them) in the
 * pattern argument of a preg_* function is read. It is parsed just enough to
 * know whether an unescaped \K sits inside (?=, (?!, (?<=, (?<! or one of the
 * (*pla: style spellings; character classes, \Q...\E and (?#...) comments
 * are skipped. A pattern with the x modifier, or with an inline (?x, is not
 * reported (nor one with an inline option group that names x, such as
 * (?ix)), because comments in it cannot be told from pattern text. The
 * warning is raised when the call runs, so a version check counts as a guard.
 */
final class Php85BicPcreLookaroundK implements Rule {

	const VERSION_GUARD = '8.5-bic: version check only';

	/**
	 * Functions whose first argument ($pattern) is a pattern or an array of them.
	 */
	const FUNCTIONS = array( 'preg_match', 'preg_match_all', 'preg_replace', 'preg_replace_callback', 'preg_filter', 'preg_split', 'preg_grep' );

	/**
	 * Lower-case names of the (*name: lookaround spellings.
	 */
	const ALPHA_LOOKAROUNDS = array(
		'pla',
		'plb',
		'nla',
		'nlb',
		'napla',
		'naplb',
		'positive_lookahead',
		'negative_lookahead',
		'positive_lookbehind',
		'negative_lookbehind',
		'non_atomic_positive_lookahead',
		'non_atomic_positive_lookbehind',
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
			if ( self::has_k_in_lookaround( $pattern ) ) {
				$finding          = $context->report( 'argument.pcre_k_in_lookaround', $node, array( 'changed' => '8.5' ) );
				$finding->subject = $name . '() with \\K inside a lookaround';
				$finding->instead = 'move \\K out of the lookaround, or match the prefix in a lookbehind without \\K';
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
	 * Whether a complete pattern, with delimiters and modifiers, has an
	 * unescaped \K inside a lookaround.
	 *
	 * @param string $pattern Pattern as PHP sees it.
	 * @return bool
	 */
	public static function has_k_in_lookaround( $pattern ) {
		$pattern = ltrim( $pattern );

		if ( '' === $pattern ) {
			return false;
		}

		$open = $pattern[0];

		if ( ctype_alnum( $open ) || '\\' === $open ) {
			return false;
		}

		$pairs = array(
			'(' => ')',
			'[' => ']',
			'{' => '}',
			'<' => '>',
		);
		$close = isset( $pairs[ $open ] ) ? $pairs[ $open ] : $open;
		$end   = strrpos( $pattern, $close );

		if ( false === $end || 0 === $end ) {
			return false;
		}

		$body      = substr( $pattern, 1, $end - 1 );
		$modifiers = substr( $pattern, $end + 1 );

		if ( false !== strpos( $modifiers, 'x' ) || preg_match( '/\(\?\^?[a-zA-Z-]*x/', $body ) ) {
			return false;
		}

		$length = strlen( $body );
		$stack  = array();
		$depth  = 0;
		$i      = 0;

		while ( $i < $length ) {
			$char = $body[ $i ];

			if ( '\\' === $char ) {
				$next = $i + 1 < $length ? $body[ $i + 1 ] : '';

				if ( 'K' === $next && $depth > 0 ) {
					return true;
				}

				if ( 'Q' === $next ) {
					$quoted = strpos( $body, '\\E', $i + 2 );
					$i      = false === $quoted ? $length : $quoted + 2;
					continue;
				}

				$i += 2;
				continue;
			}

			if ( '[' === $char ) {
				$i = self::skip_class( $body, $i );
				continue;
			}

			if ( '(' === $char ) {
				$rest = substr( $body, $i + 1, 40 );

				if ( 0 === strpos( $rest, '?#' ) ) {
					$comment = strpos( $body, ')', $i );
					$i       = false === $comment ? $length : $comment + 1;
					continue;
				}

				$lookaround = 0 === strpos( $rest, '?=' ) || 0 === strpos( $rest, '?!' ) || 0 === strpos( $rest, '?<=' ) || 0 === strpos( $rest, '?<!' );

				if ( ! $lookaround && preg_match( '/^\*([a-z_]+):/i', $rest, $match ) ) {
					$lookaround = in_array( strtolower( $match[1] ), self::ALPHA_LOOKAROUNDS, true );
				}

				$stack[] = $lookaround;
				$depth  += $lookaround ? 1 : 0;
				++$i;
				continue;
			}

			if ( ')' === $char && count( $stack ) > 0 ) {
				$depth -= array_pop( $stack ) ? 1 : 0;
			}

			++$i;
		}

		return false;
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
