<?php
/**
 * Literal arguments that PHP 8.4 rejects with a ValueError or TypeError.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\Array_;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\FuncCall;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\UnaryMinus;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Scalar\Float_;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Scalar\Int_;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Scalar\String_;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: call.invalid_literal_argument. Breaks in 8.4 (round() modes 5 to 8:
 * changed in 8.4).
 *
 * Arguments that PHP up to 8.3 accepted (sometimes with a warning) and that
 * are a ValueError or TypeError from 8.4, when they are written as literals:
 *
 * - round( $n, $p, 0 ): a mode other than 1 to 8 was read as
 *   PHP_ROUND_HALF_UP up to 8.3 and is a ValueError from 8.4. The modes 5 to 8
 *   were read as PHP_ROUND_HALF_UP up to 8.3 too, but 8.4 accepts them as the
 *   new modes (ceiling, floor, toward zero, away from zero), so a literal 5
 *   to 8 is reported as changed in 8.4, not as breaking.
 * - str_getcsv( $s, '||' ): a separator or enclosure that is not exactly one
 *   byte, or an escape longer than one byte.
 * - php_uname( 'os' ): a mode other than "a", "m", "n", "r", "s" or "v" was
 *   read as "a".
 * - imagejpeg( $im, $file, 150 ): a quality outside -1..100.
 * - imagepng( $im, $file, 90 ): a quality (compression level) outside -1..9;
 *   up to 8.3 a warning and a broken or empty image.
 * - curl_multi_select( $mh, -1 ): a negative timeout.
 * - unserialize( $s, array( 'allowed_classes' => array( 1 ) ) ): an int in the
 *   list of class names.
 *
 * The same checks apply to values computed at run time; those cannot be seen
 * in the source and are not reported. imagewebp() and imageavif() are left
 * out: imagewebp() still accepts a quality above 100 on 8.4, and a negative
 * one already failed on 7.4 and 8.0; imageavif() does not exist before 8.1
 * (fixtures runtime.gd_argument_range~8.4-bic-core-*).
 */
final class Php84BicCoreLiteralArguments implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( FuncCall::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param FuncCall $node    Function call.
	 * @param Context  $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		$name = InternalFunctions::global_name( $node->name );

		if ( null === $name || isset( $context->declared_functions[ $name ] ) ) {
			return;
		}

		foreach ( $node->args as $arg ) {
			if ( ! $arg instanceof Node\Arg || $arg->unpack ) {
				return;
			}
		}

		$problem = null;
		$when    = array( 'breaks' => '8.4' );

		switch ( $name ) {
			case 'round':
				$mode = self::int_value( self::argument( $node, 2, 'mode' ) );

				if ( null !== $mode && ( $mode < 1 || $mode > 8 ) ) {
					$problem = 'mode ' . $mode;
				} elseif ( null !== $mode && $mode > 4 ) {
					// 5 to 8 are the int values of the rounding modes that 8.4
					// added (ceiling, floor, toward zero, away from zero): read
					// as PHP_ROUND_HALF_UP up to 8.3, honoured from 8.4.
					$problem = 'mode ' . $mode;
					$when    = array( 'changed' => '8.4' );
				}
				break;

			case 'str_getcsv':
				$problem = self::csv_problem( $node );
				break;

			case 'php_uname':
				$mode = self::argument( $node, 0, 'mode' );

				if ( $mode instanceof String_ && ! in_array( $mode->value, array( 'a', 'm', 'n', 'r', 's', 'v' ), true ) ) {
					$problem = 'mode "' . $mode->value . '"';
				}
				break;

			case 'imagejpeg':
				$quality = self::int_value( self::argument( $node, 2, 'quality' ) );

				if ( null !== $quality && ( $quality < -1 || $quality > 100 ) ) {
					$problem = 'quality ' . $quality;
				}
				break;

			case 'imagepng':
				$quality = self::int_value( self::argument( $node, 2, 'quality' ) );

				if ( null !== $quality && ( $quality < -1 || $quality > 9 ) ) {
					$problem = 'quality ' . $quality;
				}
				break;

			case 'curl_multi_select':
				$timeout = self::argument( $node, 1, 'timeout' );

				if ( $timeout instanceof UnaryMinus && ( $timeout->expr instanceof Int_ || $timeout->expr instanceof Float_ ) && 0 != $timeout->expr->value ) { // phpcs:ignore Universal.Operators.StrictComparisons
					$problem = 'negative timeout';
				}
				break;

			case 'unserialize':
				if ( self::allowed_classes_has_int( self::argument( $node, 1, 'options' ) ) ) {
					$problem = 'an int in allowed_classes';
				}
				break;
		}

		if ( null === $problem ) {
			return;
		}

		$finding          = $context->report( 'call.invalid_literal_argument', $node, $when );
		$finding->subject = $name . '(): ' . $problem;
		$finding->guarded = $context->function_is_guarded( '8.4-bic-core: version check only' );
	}

	/**
	 * The argument at a position or with a name, or null.
	 *
	 * @param FuncCall $node     Call.
	 * @param int      $position Position, counted from 0.
	 * @param string   $name     Parameter name.
	 * @return Node\Expr|null
	 */
	private static function argument( FuncCall $node, $position, $name ) {
		foreach ( $node->args as $index => $arg ) {
			if ( null !== $arg->name ) {
				if ( $name === $arg->name->toString() ) {
					return $arg->value;
				}
			} elseif ( $index === $position ) {
				return $arg->value;
			}
		}

		return null;
	}

	/**
	 * The value of an int literal, negative ones included, or null.
	 *
	 * @param Node\Expr|null $expr Expression.
	 * @return int|null
	 */
	private static function int_value( $expr ) {
		if ( $expr instanceof Int_ ) {
			return $expr->value;
		}

		if ( $expr instanceof UnaryMinus && $expr->expr instanceof Int_ ) {
			return -$expr->expr->value;
		}

		return null;
	}

	/**
	 * What is wrong with literal separator, enclosure or escape of str_getcsv().
	 *
	 * @param FuncCall $node Call.
	 * @return string|null
	 */
	private static function csv_problem( FuncCall $node ) {
		$separator = self::argument( $node, 1, 'separator' );
		$enclosure = self::argument( $node, 2, 'enclosure' );
		$escape    = self::argument( $node, 3, 'escape' );

		if ( $separator instanceof String_ && 1 !== strlen( $separator->value ) ) {
			return 'separator "' . $separator->value . '"';
		}

		if ( $enclosure instanceof String_ && 1 !== strlen( $enclosure->value ) ) {
			return 'enclosure "' . $enclosure->value . '"';
		}

		if ( $escape instanceof String_ && strlen( $escape->value ) > 1 ) {
			return 'escape "' . $escape->value . '"';
		}

		return null;
	}

	/**
	 * Whether a literal options array has an allowed_classes list with an int.
	 *
	 * @param Node\Expr|null $options Options argument.
	 * @return bool
	 */
	private static function allowed_classes_has_int( $options ) {
		if ( ! $options instanceof Array_ ) {
			return false;
		}

		foreach ( $options->items as $item ) {
			if ( null === $item || ! $item->key instanceof String_ || 'allowed_classes' !== $item->key->value || ! $item->value instanceof Array_ ) {
				continue;
			}

			foreach ( $item->value->items as $class ) {
				if ( null !== $class && $class->value instanceof Int_ ) {
					return true;
				}
			}
		}

		return false;
	}
}
