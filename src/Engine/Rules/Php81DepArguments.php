<?php
/**
 * Calls to internal functions with arguments that PHP 8.1 deprecates.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\FuncCall;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Scalar;

defined( 'ABSPATH' ) || exit;

/**
 * Rule ids, all deprecated in 8.1:
 *
 * - argument.ctype_non_string             ctype_digit( 5 ), ctype_digit( (int) $x )
 * - argument.polygon_num_points           imagepolygon( $image, $points, 3, $color )
 * - argument.mb_check_encoding_none       mb_check_encoding()
 * - argument.mysqli_get_client_info_link  mysqli_get_client_info( $link )
 * - argument.array_pointer_on_object      current( $this ), key( (object) $data )
 * - ini.auto_detect_line_endings          ini_set( 'auto_detect_line_endings', true )
 *
 * The functions themselves stay. Only the way the call is written is
 * reported, and only where the source shows it: an argument held in a
 * variable is not examined, because its type is known only at run time.
 */
final class Php81DepArguments implements Rule {

	/**
	 * Asked of Context::function_is_guarded() to learn about a version check
	 * only. Not a valid function name, so no function_exists() matches it.
	 */
	const VERSION_GUARD = 'php 8.1 version check';

	const CTYPE = array(
		'ctype_alnum'  => true,
		'ctype_alpha'  => true,
		'ctype_cntrl'  => true,
		'ctype_digit'  => true,
		'ctype_graph'  => true,
		'ctype_lower'  => true,
		'ctype_print'  => true,
		'ctype_punct'  => true,
		'ctype_space'  => true,
		'ctype_upper'  => true,
		'ctype_xdigit' => true,
	);

	const POLYGON = array(
		'imagepolygon'       => true,
		'imageopenpolygon'   => true,
		'imagefilledpolygon' => true,
	);

	/**
	 * Array pointer functions; true when the array is taken by reference, so
	 * that only a variable can be passed.
	 */
	const ARRAY_POINTER = array(
		'key'     => false,
		'current' => false,
		'next'    => true,
		'prev'    => true,
		'reset'   => true,
		'end'     => true,
	);

	/**
	 * Internal functions that return an integer whatever they are given.
	 */
	const RETURNS_INT = array(
		'intval' => true,
		'strlen' => true,
		'count'  => true,
	);

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

		$args = self::plain_arguments( $node );

		if ( null === $args ) {
			return;
		}

		$finding = null;

		if ( isset( self::CTYPE[ $name ] ) ) {
			$type = 1 === count( $args ) ? self::non_string_type( $args[0], $context ) : null;

			if ( null !== $type ) {
				$finding          = $context->report( 'argument.ctype_non_string', $node, array( 'deprecated' => '8.1' ) );
				$finding->instead = 'int' === $type ? $name . '( (string) $value ), or ' . $name . '( chr( $codepoint ) ) for a character code' : $name . '( (string) $value )';
			}
		} elseif ( isset( self::POLYGON[ $name ] ) ) {
			if ( 4 === count( $args ) ) {
				$finding = $context->report( 'argument.polygon_num_points', $node, array( 'deprecated' => '8.1' ) );
				// PHP 7.4 still requires the number of points.
				$finding->instead = $name . '( $image, $points, $color ) on PHP 8.0 and later';
			}
		} elseif ( 'mb_check_encoding' === $name ) {
			// A literal null as the value is the same as no value, with or
			// without an encoding (fixture ...~8.1-dep-null_with_encoding).
			if ( 0 === count( $args ) || ( count( $args ) <= 2 && self::is_constant( $args[0], array( 'null' ) ) ) ) {
				$finding = $context->report( 'argument.mb_check_encoding_none', $node, array( 'deprecated' => '8.1' ) );
			}
		} elseif ( 'mysqli_get_client_info' === $name ) {
			if ( count( $args ) > 0 ) {
				$finding          = $context->report( 'argument.mysqli_get_client_info_link', $node, array( 'deprecated' => '8.1' ) );
				$finding->instead = 'mysqli_get_client_info()';
			}
		} elseif ( isset( self::ARRAY_POINTER[ $name ] ) ) {
			if ( 1 === count( $args ) && self::is_object( $args[0], self::ARRAY_POINTER[ $name ] ) ) {
				$finding          = $context->report( 'argument.array_pointer_on_object', $node, array( 'deprecated' => '8.1' ) );
				$finding->instead = 'get_object_vars() or an ArrayIterator';
			}
		} elseif ( 'ini_set' === $name || 'ini_alter' === $name ) {
			if ( 2 === count( $args ) && $args[0] instanceof Scalar\String_ && 'auto_detect_line_endings' === $args[0]->value && self::switches_on( $args[1] ) ) {
				$finding = $context->report( 'ini.auto_detect_line_endings', $node, array( 'deprecated' => '8.1' ) );
			}
		}

		if ( null !== $finding ) {
			$finding->subject = $name;
			// Only a PHP version check keeps the notice away. function_exists()
			// around the call does not: the function exists on every version,
			// it is the way it is called that is deprecated (fixtures
			// ...~8.1-dep-function_exists). The name below is no function, so
			// only the version part of the guard can match.
			$finding->guarded = $context->function_is_guarded( self::VERSION_GUARD );
		}
	}

	/**
	 * The argument expressions of a call, or null when the call spreads an
	 * array, names its arguments or is a first-class callable: then the source
	 * does not show which value goes where.
	 *
	 * @param FuncCall $node Function call.
	 * @return Expr[]|null
	 */
	private static function plain_arguments( FuncCall $node ) {
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
	 * The type of an expression that is certainly not a string, or null.
	 *
	 * @param Expr    $value   Argument.
	 * @param Context $context Scan state.
	 * @return string|null "int", "float", "bool" or "null".
	 */
	private static function non_string_type( Expr $value, Context $context ) {
		if ( $value instanceof Expr\UnaryMinus || $value instanceof Expr\UnaryPlus ) {
			return $value->expr instanceof Scalar\Int_ ? 'int' : null;
		}

		if ( $value instanceof Scalar\Int_ || $value instanceof Expr\Cast\Int_ ) {
			return 'int';
		}

		if ( $value instanceof Scalar\Float_ || $value instanceof Expr\Cast\Double ) {
			return 'float';
		}

		if ( self::is_constant( $value, array( 'true', 'false' ) ) ) {
			return 'bool';
		}

		if ( self::is_constant( $value, array( 'null' ) ) ) {
			return 'null';
		}

		if ( $value instanceof FuncCall ) {
			$name = InternalFunctions::global_name( $value->name );

			if ( null !== $name && isset( self::RETURNS_INT[ $name ] ) && ! isset( $context->declared_functions[ $name ] ) ) {
				return 'int';
			}
		}

		return null;
	}

	/**
	 * Whether an expression is always an object.
	 *
	 * @param Expr $value         Argument.
	 * @param bool $variable_only Whether the function takes a reference.
	 * @return bool
	 */
	private static function is_object( Expr $value, $variable_only ) {
		if ( $value instanceof Expr\Variable ) {
			return 'this' === $value->name;
		}

		return ! $variable_only && ( $value instanceof Expr\Cast\Object_ || $value instanceof Expr\New_ );
	}

	/**
	 * Whether a literal switches a boolean INI setting on. Switching it off
	 * raises nothing (fixture runtime.ini_auto_detect_line_endings_off).
	 *
	 * @param Expr $value Second argument of ini_set().
	 * @return bool
	 */
	private static function switches_on( Expr $value ) {
		if ( self::is_constant( $value, array( 'true' ) ) ) {
			return true;
		}

		if ( $value instanceof Scalar\Int_ ) {
			return 0 !== $value->value;
		}

		if ( $value instanceof Scalar\Float_ ) {
			return abs( $value->value ) >= 1 && abs( $value->value ) < 1e9;
		}

		if ( $value instanceof Scalar\String_ ) {
			$text = strtolower( $value->value );

			return in_array( $text, array( 'on', 'yes', 'true' ), true ) || ( 1 === preg_match( '/^[0-9]+$/', $text ) && 0 !== (int) $text );
		}

		return false;
	}

	/**
	 * Whether an expression is one of the constants true, false or null.
	 *
	 * @param Expr     $value Expression.
	 * @param string[] $names Lower-case names to accept.
	 * @return bool
	 */
	private static function is_constant( Expr $value, array $names ) {
		return $value instanceof Expr\ConstFetch && in_array( strtolower( $value->name->toString() ), $names, true );
	}
}
