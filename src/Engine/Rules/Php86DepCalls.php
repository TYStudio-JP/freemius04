<?php
/**
 * Function calls, method calls and object creations that PHP 8.6 deprecates
 * (tranche 8.6-dep).
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule ids (tranche 8.6-dep). There is no PHP 8.6 runtime here, so every
 * finding is "documented", from UPGRADING of PHP 8.6, "4. Deprecated
 * Functionality", quoted word for word:
 *
 * - call.define_third_argument (Core)
 *   "Passing a 3rd argument to define() is now deprecated."
 *   A literal true is left out: PHP 7.3 already deprecated it and PHP 8.0
 *   turned it into a warning, which call.define_case_insensitive covers
 *   where it changes what the program does.
 *
 * - call.is_a_string_without_allow_string (Core)
 *   "Calling is_a() or is_subclass_of() with a string as the first argument
 *   when $allow_string is false is now deprecated."
 *   Reported when the first argument is written as a string (a literal, an
 *   interpolated string, a concatenation, __CLASS__, Foo::class or
 *   get_class( $x )) and $allow_string is false: left out for is_a(), or a
 *   literal false for both. A string from a variable is known at run time
 *   only: runtime.is_a_string_variable.
 *
 * - call.arrayiterator_method (SPL)
 *   "The following ArrayIterator methods are now deprecated:" getFlags(),
 *   setFlags(), asort(), ksort(), uasort(), uksort(), natsort(),
 *   natcasesort(), unserialize(), serialize().
 *
 * - call.splfileobject_csv_method (SPL)
 *   "The following SplFileObject methods are now deprecated:" fgetcsv(),
 *   fputcsv(), setCsvControl(), getCsvControl().
 *
 * - call.mysqli_deprecated_method (MySQLi)
 *   "The mysqli_get_charset() function and mysqli::get_charset() method are
 *   now deprecated." and "The mysqli_stmt_init() function, mysqli::stmt_init()
 *   method, [...] are now deprecated." The functions are in the catalog.
 *
 * - call.mysqli_stmt_without_query (MySQLi)
 *   "[...] and calling the mysqli_stmt constructor without providing the
 *   $query parameter are now deprecated." new mysqli_stmt( $link ), or with a
 *   literal null as the query.
 *
 * - argument.object_instead_of_array (Standard, Zlib)
 *   "Passing an object as the $data argument to http_build_query() is now
 *   deprecated." and "Passing an object as the $option argument to
 *   deflate_init and inflate_init is now deprecated."
 *   Only an object written in the call: new Foo(), (object) ..., $this.
 *
 * The class of the object a method is called on is usually not in the
 * source. The method rules report only where it is certain, see
 * Php85DepMethods::known_class(); ArrayIterator, SplFileObject and mysqli
 * subclasses (RecursiveArrayIterator, SplTempFileObject) count as well.
 *
 * All of these are decided when the call runs, so a call behind a PHP
 * version check is marked as guarded.
 */
final class Php86DepCalls implements Rule {

	const VERSION_GUARD = '8.6-dep: version check only';

	const ARRAYITERATOR_METHODS = array( 'getflags', 'setflags', 'asort', 'ksort', 'uasort', 'uksort', 'natsort', 'natcasesort', 'unserialize', 'serialize' );

	const ARRAYITERATOR_CLASSES = array( 'arrayiterator', 'recursivearrayiterator' );

	const CSV_METHODS = array( 'fgetcsv', 'fputcsv', 'setcsvcontrol', 'getcsvcontrol' );

	const FILE_CLASSES = array( 'splfileobject', 'spltempfileobject' );

	const MYSQLI_METHODS = array(
		'get_charset' => 'mysqli::character_set_name()',
		'stmt_init'   => 'mysqli::prepare()',
	);

	/**
	 * Functions that take an array where an object is deprecated: position
	 * and name of the parameter.
	 */
	const ARRAY_PARAMETERS = array(
		'http_build_query' => array( 0, 'data' ),
		'deflate_init'     => array( 1, 'options' ),
		'inflate_init'     => array( 1, 'options' ),
	);

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Expr\FuncCall::class, Node\Expr\MethodCall::class, Node\Expr\NullsafeMethodCall::class, Node\Expr\New_::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Expr $node    Call or object creation.
	 * @param Context   $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( $node instanceof Node\Expr\FuncCall ) {
			$this->function_call( $node, $context );
		} elseif ( $node instanceof Node\Expr\New_ ) {
			$this->creation( $node, $context );
		} else {
			$this->method_call( $node, $context );
		}
	}

	/**
	 * define(), is_a(), is_subclass_of(), http_build_query(), deflate_init(),
	 * inflate_init().
	 *
	 * @param Node\Expr\FuncCall $node    Call.
	 * @param Context            $context Scan state.
	 */
	private function function_call( Node\Expr\FuncCall $node, Context $context ) {
		$name = InternalFunctions::global_name( $node->name );

		if ( null === $name || isset( $context->declared_functions[ $name ] ) || $node->isFirstClassCallable() ) {
			return;
		}

		if ( 'define' === $name ) {
			$args = self::arguments( $node->args, array( 'constant_name', 'value', 'case_insensitive' ) );

			if ( null === $args || ! isset( $args[2] ) || Php85DepFunctionArguments::is_literal( $args[2], 'true' ) ) {
				return;
			}

			$finding           = $context->report( 'call.define_third_argument', $node, array( 'deprecated' => '8.6' ) );
			$finding->subject  = 'define( $name, $value, $case_insensitive )';
			$finding->instead  = 'define( $name, $value ) or const';
			$finding->evidence = 'documented';
			$finding->guarded  = $context->function_is_guarded( self::VERSION_GUARD );

			return;
		}

		if ( 'is_a' === $name || 'is_subclass_of' === $name ) {
			$args = self::arguments( $node->args, array( 'object_or_class', 'class', 'allow_string' ) );

			if ( null === $args || ! isset( $args[0], $args[1] ) || ! self::is_string( $args[0] ) ) {
				return;
			}

			$allow_false = isset( $args[2] ) ? Php85DepFunctionArguments::is_literal( $args[2], 'false' ) : 'is_a' === $name;

			if ( ! $allow_false ) {
				return;
			}

			$finding           = $context->report( 'call.is_a_string_without_allow_string', $node, array( 'deprecated' => '8.6' ) );
			$finding->subject  = $name . '( <string>, ... ) with $allow_string false';
			$finding->instead  = $name . '( $class, ..., true ), or is_subclass_of() / a comparison of class names';
			$finding->evidence = 'documented';
			$finding->guarded  = $context->function_is_guarded( self::VERSION_GUARD );

			return;
		}

		if ( ! isset( self::ARRAY_PARAMETERS[ $name ] ) ) {
			return;
		}

		list( $position, $parameter ) = self::ARRAY_PARAMETERS[ $name ];

		$names = 0 === $position ? array( $parameter ) : array( 'encoding', $parameter );
		$args  = self::arguments( $node->args, $names );

		if ( null === $args || ! isset( $args[ $position ] ) || ! self::is_object( $args[ $position ] ) ) {
			return;
		}

		$finding           = $context->report( 'argument.object_instead_of_array', $node, array( 'deprecated' => '8.6' ) );
		$finding->subject  = $name . '() with an object as $' . $parameter;
		$finding->instead  = 'an array, for example get_object_vars()';
		$finding->evidence = 'documented';
		$finding->guarded  = $context->function_is_guarded( self::VERSION_GUARD );
	}

	/**
	 * Methods of ArrayIterator, SplFileObject and mysqli.
	 *
	 * @param Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $node    Call.
	 * @param Context                                           $context Scan state.
	 */
	private function method_call( Node\Expr $node, Context $context ) {
		if ( ! $node->name instanceof Node\Identifier || $node->isFirstClassCallable() ) {
			return;
		}

		$method = $node->name->toLowerString();

		if ( ! in_array( $method, self::ARRAYITERATOR_METHODS, true ) && ! in_array( $method, self::CSV_METHODS, true ) && ! isset( self::MYSQLI_METHODS[ $method ] ) ) {
			return;
		}

		$class = Php85DepMethods::known_class( $node->var );

		if ( null === $class ) {
			return;
		}

		if ( in_array( $method, self::ARRAYITERATOR_METHODS, true ) && in_array( $class, self::ARRAYITERATOR_CLASSES, true ) ) {
			$finding           = $context->report( 'call.arrayiterator_method', $node, array( 'deprecated' => '8.6' ) );
			$finding->subject  = 'ArrayIterator::' . $node->name->toString() . '()';
			$finding->instead  = 'the array itself, or ArrayObject';
			$finding->evidence = 'documented';
			$finding->guarded  = $context->function_is_guarded( self::VERSION_GUARD );
		} elseif ( in_array( $method, self::CSV_METHODS, true ) && in_array( $class, self::FILE_CLASSES, true ) ) {
			$finding           = $context->report( 'call.splfileobject_csv_method', $node, array( 'deprecated' => '8.6' ) );
			$finding->subject  = 'SplFileObject::' . $node->name->toString() . '()';
			$finding->instead  = 'fgetcsv() / fputcsv() / str_getcsv() on the file contents';
			$finding->evidence = 'documented';
			$finding->guarded  = $context->function_is_guarded( self::VERSION_GUARD );
		} elseif ( isset( self::MYSQLI_METHODS[ $method ] ) && 'mysqli' === $class ) {
			$finding           = $context->report( 'call.mysqli_deprecated_method', $node, array( 'deprecated' => '8.6' ) );
			$finding->subject  = 'mysqli::' . $node->name->toString() . '()';
			$finding->instead  = self::MYSQLI_METHODS[ $method ];
			$finding->evidence = 'documented';
			$finding->guarded  = $context->function_is_guarded( self::VERSION_GUARD );
		}
	}

	/**
	 * new mysqli_stmt( $link ) without a query.
	 *
	 * @param Node\Expr\New_ $node    Object creation.
	 * @param Context        $context Scan state.
	 */
	private function creation( Node\Expr\New_ $node, Context $context ) {
		if ( ! $node->class instanceof Node\Name || $node->isFirstClassCallable() ) {
			return;
		}

		$resolved = $node->class->getAttribute( 'resolvedName' );
		$class    = $resolved instanceof Node\Name ? $resolved : $node->class;

		if ( 1 !== count( $class->getParts() ) || 'mysqli_stmt' !== strtolower( $class->getLast() ) ) {
			return;
		}

		$args = self::arguments( $node->args, array( 'mysql', 'query' ) );

		if ( null === $args || ! isset( $args[0] ) ) {
			return;
		}

		if ( isset( $args[1] ) && ! Php85DepFunctionArguments::is_literal( $args[1], 'null' ) ) {
			return;
		}

		$finding           = $context->report( 'call.mysqli_stmt_without_query', $node, array( 'deprecated' => '8.6' ) );
		$finding->subject  = 'new mysqli_stmt( $mysql ) without $query';
		$finding->instead  = 'mysqli::prepare( $query )';
		$finding->evidence = 'documented';
		$finding->guarded  = $context->function_is_guarded( self::VERSION_GUARD );
	}

	/**
	 * The argument expressions by parameter position, or null when an
	 * argument is spread or has a name the function does not know.
	 *
	 * @param array    $args  Call arguments.
	 * @param string[] $names Parameter names in order.
	 * @return array<int,Node\Expr>|null
	 */
	private static function arguments( array $args, array $names ) {
		$by_position = array();

		foreach ( $args as $index => $arg ) {
			if ( ! $arg instanceof Node\Arg || $arg->unpack ) {
				return null;
			}

			if ( null === $arg->name ) {
				$by_position[ $index ] = $arg->value;
				continue;
			}

			$position = array_search( $arg->name->toString(), $names, true );

			if ( false === $position ) {
				return null;
			}

			$by_position[ $position ] = $arg->value;
		}

		return $by_position;
	}

	/**
	 * Whether an expression is certainly a string.
	 *
	 * @param Node\Expr $value Expression.
	 * @return bool
	 */
	private static function is_string( Node\Expr $value ) {
		if ( $value instanceof Node\Scalar\String_ || $value instanceof Node\Scalar\InterpolatedString || $value instanceof Node\Expr\BinaryOp\Concat || $value instanceof Node\Scalar\MagicConst\Class_ ) {
			return true;
		}

		if ( $value instanceof Node\Expr\ClassConstFetch && $value->name instanceof Node\Identifier && 'class' === $value->name->toLowerString() ) {
			return true;
		}

		return $value instanceof Node\Expr\FuncCall
			&& 'get_class' === InternalFunctions::global_name( $value->name )
			&& 1 === count( $value->args );
	}

	/**
	 * Whether an expression is certainly an object.
	 *
	 * @param Node\Expr $value Expression.
	 * @return bool
	 */
	private static function is_object( Node\Expr $value ) {
		return $value instanceof Node\Expr\New_
			|| $value instanceof Node\Expr\Cast\Object_
			|| ( $value instanceof Node\Expr\Variable && 'this' === $value->name );
	}
}
