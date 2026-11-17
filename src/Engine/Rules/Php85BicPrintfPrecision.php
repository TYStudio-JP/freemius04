<?php
/**
 * printf-family formats with a "." but no precision digits for a float.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id (tranche 8.5-bic): argument.printf_empty_precision. Changed in 8.5.
 *
 *     printf( '%.f', 1.5 );   // 7.4 to 8.4: "1.500000"; 8.5: "2"
 *
 * A "." with no digits after it used to reset the precision to the default
 * of 6; 8.5 reads it as a precision of 0 (GH-18897). The runtimes showed the
 * change for the conversions e, E, f, F, g and G (fixtures
 * argument.printf_empty_precision~8.5-bic-*). %.s and %.d print the same on
 * every version and are not reported. %.h and %.H changed as well, but those
 * conversions do not exist before 8.0, so no fixture can compare them with
 * 7.4; they are left out.
 *
 * Read in the format argument of printf(), sprintf(), vprintf(), vsprintf()
 * (first argument) and fprintf(), vfprintf() (second), when it is a string
 * literal. The output differs when the call runs, so a version check counts
 * as a guard.
 */
final class Php85BicPrintfPrecision implements Rule {

	const VERSION_GUARD = '8.5-bic: version check only';

	/**
	 * Function => position of the format argument.
	 */
	const FUNCTIONS = array(
		'printf'   => 0,
		'sprintf'  => 0,
		'vprintf'  => 0,
		'vsprintf' => 0,
		'fprintf'  => 1,
		'vfprintf' => 1,
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

		if ( null === $name || ! isset( self::FUNCTIONS[ $name ] ) || isset( $context->declared_functions[ $name ] ) ) {
			return;
		}

		$format = null;

		foreach ( $node->args as $index => $arg ) {
			if ( ! $arg instanceof Node\Arg || $arg->unpack ) {
				return;
			}

			if ( null === $arg->name ? self::FUNCTIONS[ $name ] === $index : 'format' === strtolower( $arg->name->toString() ) ) {
				$format = $arg->value;
				break;
			}
		}

		if ( ! $format instanceof Node\Scalar\String_ ) {
			return;
		}

		$spec = self::empty_precision( $format->value );

		if ( null === $spec ) {
			return;
		}

		$finding          = $context->report( 'argument.printf_empty_precision', $node, array( 'changed' => '8.5' ) );
		$finding->subject = $name . "( '" . $spec . "' )";
		$dot              = strrpos( $spec, '.' );
		$finding->instead = substr_replace( $spec, '.0', $dot, 1 ) . ' for no decimals (what 8.5 prints), or ' . substr_replace( $spec, '.6', $dot, 1 ) . ' for what earlier versions printed';
		$finding->guarded = $context->function_is_guarded( self::VERSION_GUARD );
	}

	/**
	 * The first conversion specification that has a "." without digits and a
	 * float conversion, or null.
	 *
	 * @param string $format Format string.
	 * @return string|null
	 */
	public static function empty_precision( $format ) {
		$length = strlen( $format );
		$i      = 0;

		while ( $i < $length ) {
			if ( '%' !== $format[ $i ] ) {
				++$i;
				continue;
			}

			$start = $i;
			++$i;

			if ( $i < $length && '%' === $format[ $i ] ) {
				++$i;
				continue;
			}

			// Argument number: 1$.
			if ( preg_match( '/\G[0-9]+\$/', $format, $match, 0, $i ) ) {
				$i += strlen( $match[0] );
			}

			// Flags; "'" takes the next character as the padding.
			while ( $i < $length ) {
				if ( false !== strpos( '-+ 0', $format[ $i ] ) ) {
					++$i;
				} elseif ( "'" === $format[ $i ] ) {
					$i += 2;
				} else {
					break;
				}
			}

			// Width.
			if ( $i < $length && '*' === $format[ $i ] ) {
				++$i;
			} else {
				while ( $i < $length && ctype_digit( $format[ $i ] ) ) {
					++$i;
				}
			}

			if ( $i >= $length || '.' !== $format[ $i ] ) {
				continue;
			}

			++$i;

			if ( $i < $length && ( '*' === $format[ $i ] || ctype_digit( $format[ $i ] ) ) ) {
				continue;
			}

			// PHP skips an "l" length modifier.
			if ( $i < $length && 'l' === $format[ $i ] ) {
				++$i;
			}

			if ( $i < $length && false !== strpos( 'eEfFgG', $format[ $i ] ) ) {
				return substr( $format, $start, $i - $start + 1 );
			}
		}

		return null;
	}
}
