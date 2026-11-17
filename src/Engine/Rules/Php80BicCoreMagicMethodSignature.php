<?php
/**
 * Magic methods declared with types that PHP 8.0 rejects.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: class.magic_method_signature. From 8.0 the declared types of magic
 * methods are checked when the class is compiled; PHP 7.4 accepted them.
 *
 * Reported, each seen as a compile error on the 8.x runtimes:
 *
 * - a return type other than the required one: bool for __isset(), void for
 *   __set(), __unset(), __wakeup() and __unserialize(), array for __sleep()
 *   and __serialize(), string for __toString(), and a built-in non-object
 *   type for __set_state() (which must return object). ?bool for __isset()
 *   is rejected too.
 * - a type other than string on the name parameter of __get(), __set(),
 *   __isset(), __unset(), __call() and __callStatic().
 *
 * Only plain types are judged: a nullable, union or class type on a name
 * parameter is left alone, as are __call(), __get() and __invoke() return
 * types (mixed accepts anything) and __clone() (any return type was a compile
 * error on 7.4 already).
 */
final class Php80BicCoreMagicMethodSignature implements Rule {

	/**
	 * Required return type by method.
	 *
	 * @var array<string,string>
	 */
	const RETURNS = array(
		'__isset'       => 'bool',
		'__set'         => 'void',
		'__unset'       => 'void',
		'__wakeup'      => 'void',
		'__unserialize' => 'void',
		'__sleep'       => 'array',
		'__serialize'   => 'array',
		'__tostring'    => 'string',
	);

	/**
	 * Methods whose first parameter is a name and must be string.
	 *
	 * @var string[]
	 */
	const NAMED = array( '__get', '__set', '__isset', '__unset', '__call', '__callstatic' );

	/**
	 * Built-in types that are not object types.
	 *
	 * @var string[]
	 */
	const NOT_OBJECT = array( 'int', 'float', 'string', 'bool', 'array', 'void', 'iterable', 'callable', 'null', 'false' );

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Stmt\ClassMethod::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Stmt\ClassMethod $node    Method.
	 * @param Context               $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		$name    = $node->name->toLowerString();
		$problem = null;
		$at      = $node->name;

		if ( null !== $node->returnType ) {
			$problem = self::return_problem( $name, $node->returnType );
			$at      = $node->returnType;
		}

		if ( null === $problem && in_array( $name, self::NAMED, true ) && isset( $node->params[0] ) ) {
			$type = $node->params[0]->type;

			if ( ( $type instanceof Node\Identifier || $type instanceof Node\Name ) && ! in_array( $type->toLowerString(), array( 'string', 'mixed' ), true ) ) {
				$problem = 'the name parameter is ' . $type->toString() . ', not string';
				$at      = $type;
			}
		}

		if ( null === $problem ) {
			return;
		}

		$finding          = $context->report( 'class.magic_method_signature', $at, array( 'breaks' => '8.0' ) );
		$finding->subject = $node->name->toString() . '(): ' . $problem;
	}

	/**
	 * What is wrong with a declared return type, or null.
	 *
	 * @param string $name Lower-case method name.
	 * @param Node   $type Return type.
	 * @return string|null
	 */
	private static function return_problem( $name, Node $type ) {
		if ( isset( self::RETURNS[ $name ] ) ) {
			$want = self::RETURNS[ $name ];

			// never is accepted from 8.1 on, so it is not a plain 8.0 break.
			if ( $type instanceof Node\Identifier && in_array( $type->toLowerString(), array( $want, 'never' ), true ) ) {
				return null;
			}

			// true and false (8.2) are part of bool, which the check accepts.
			if ( 'bool' === $want && $type instanceof Node\Identifier && in_array( $type->toLowerString(), array( 'true', 'false' ), true ) ) {
				return null;
			}

			// Only clear cases: a plain or nullable built-in or class type.
			if ( $type instanceof Node\NullableType || $type instanceof Node\Identifier || $type instanceof Node\Name ) {
				return 'returns ' . self::written( $type ) . ', must be ' . $want;
			}

			return null;
		}

		if ( '__set_state' === $name && $type instanceof Node\Identifier && in_array( $type->toLowerString(), self::NOT_OBJECT, true ) ) {
			return 'returns ' . $type->toString() . ', must be object';
		}

		return null;
	}

	/**
	 * A type as written.
	 *
	 * @param Node $type Type.
	 * @return string
	 */
	private static function written( Node $type ) {
		if ( $type instanceof Node\NullableType ) {
			return '?' . self::written( $type->type );
		}

		return $type->toString();
	}
}
