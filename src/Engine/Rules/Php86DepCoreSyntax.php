<?php
/**
 * Declarations and statements that PHP 8.6 deprecates (tranche 8.6-dep, Core).
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule ids (tranche 8.6-dep). There is no PHP 8.6 runtime here, so every
 * finding is "documented": the versions come from UPGRADING of PHP 8.6,
 * section "4. Deprecated Functionality", Core, quoted word for word below.
 * The fixtures only show that PHP 7.4 to 8.5 accept the code silently.
 *
 * - syntax.class_constant_namespace
 *   "Using "namespace" as a class constant name is deprecated."
 *   Reported for every case of the name (const NAMESPACE as well): PHP
 *   compares class constant names with "namespace" without regard to case
 *   (zend_declare_typed_class_constant() in the php-8.6.0RC7 source). An
 *   enum case is declared through the same function, so "case Namespace;"
 *   is reported as well (Zend/tests/deprecate_namespace_class_constant.phpt
 *   expects "Declaring enum constant called 'namespace' is deprecated").
 *
 * - syntax.return_in_finally
 *   "Using the return statement in a finally block is now deprecated."
 *   Any return, with or without a value, that is inside a finally block of
 *   the same function. A return in a closure or class declared inside the
 *   finally block belongs to that function and is not reported.
 *
 * - signature.debuginfo_nullable_return
 *   "Specifying a return type of array|null / ?array for __debugInfo() is
 *   now deprecated. Specify array instead."
 *   Exactly the types ?array, array|null and null|array.
 *
 * - syntax.constructor_return_value
 *   "Returning values from __construct() and __destruct() is now deprecated."
 *   A return with a value, null included, written directly in a method named
 *   __construct or __destruct. A bare "return;" is not reported.
 *
 * - syntax.constructor_generator
 *   "Making __construct() and __destruct() a Generator is now deprecated."
 *   A yield or yield from written directly in __construct or __destruct.
 *
 * - syntax.function_named_readonly
 *   "Naming a function readonly is now deprecated."
 *   A function declaration (not a method) named readonly, in any case.
 *
 * All of these are decided when the file is compiled, so no check around
 * the code keeps them away and none is marked as guarded.
 */
final class Php86DepCoreSyntax implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array(
			Node\Stmt\ClassConst::class,
			Node\Stmt\EnumCase::class,
			Node\Stmt\Return_::class,
			Node\Stmt\ClassMethod::class,
			Node\Stmt\Function_::class,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node    $node    Node of one of the types above.
	 * @param Context $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( $node instanceof Node\Stmt\ClassConst ) {
			foreach ( $node->consts as $const ) {
				$this->class_constant( $const->name, 'const ', $context );
			}
		} elseif ( $node instanceof Node\Stmt\EnumCase ) {
			$this->class_constant( $node->name, 'case ', $context );
		} elseif ( $node instanceof Node\Stmt\Return_ ) {
			$this->return_statement( $node, $context );
		} elseif ( $node instanceof Node\Stmt\ClassMethod ) {
			$this->method( $node, $context );
		} elseif ( $node instanceof Node\Stmt\Function_ ) {
			if ( 'readonly' === $node->name->toLowerString() ) {
				$finding           = $context->report( 'syntax.function_named_readonly', $node->name, array( 'deprecated' => '8.6' ) );
				$finding->subject  = 'function ' . $node->name->toString() . '()';
				$finding->instead  = 'another function name';
				$finding->evidence = 'documented';
			}
		}
	}

	/**
	 * const namespace = ... in a class, interface, trait or enum, or
	 * case namespace in an enum.
	 *
	 * @param Node\Identifier $name    Constant or case name.
	 * @param string          $keyword "const " or "case ".
	 * @param Context         $context Scan state.
	 */
	private function class_constant( Node\Identifier $name, $keyword, Context $context ) {
		if ( 'namespace' !== $name->toLowerString() ) {
			return;
		}

		$finding           = $context->report( 'syntax.class_constant_namespace', $name, array( 'deprecated' => '8.6' ) );
		$finding->subject  = $keyword . $name->toString();
		$finding->instead  = 'another constant name';
		$finding->evidence = 'documented';
	}

	/**
	 * A return in a finally block, or a return with a value in a constructor
	 * or destructor.
	 *
	 * @param Node\Stmt\Return_ $node    Return statement.
	 * @param Context           $context Scan state.
	 */
	private function return_statement( Node\Stmt\Return_ $node, Context $context ) {
		$in_finally = false;
		$function   = null;
		$parent     = $node->getAttribute( 'parent' );

		while ( null !== $parent ) {
			if ( $parent instanceof Node\FunctionLike || $parent instanceof Node\Stmt\ClassLike ) {
				$function = $parent;
				break;
			}

			if ( $parent instanceof Node\Stmt\Finally_ ) {
				$in_finally = true;
			}

			$parent = $parent->getAttribute( 'parent' );
		}

		if ( $in_finally ) {
			$finding           = $context->report( 'syntax.return_in_finally', $node, array( 'deprecated' => '8.6' ) );
			$finding->subject  = 'return in finally';
			$finding->instead  = 'return after the try statement';
			$finding->evidence = 'documented';
		}

		if ( null !== $node->expr && $function instanceof Node\Stmt\ClassMethod && null !== self::structor( $function ) ) {
			$finding           = $context->report( 'syntax.constructor_return_value', $node, array( 'deprecated' => '8.6' ) );
			$finding->subject  = 'return with a value in ' . self::structor( $function ) . '()';
			$finding->instead  = 'return; without a value';
			$finding->evidence = 'documented';
		}
	}

	/**
	 * __debugInfo(): ?array, and a constructor or destructor with a yield.
	 *
	 * @param Node\Stmt\ClassMethod $node    Method.
	 * @param Context               $context Scan state.
	 */
	private function method( Node\Stmt\ClassMethod $node, Context $context ) {
		if ( '__debuginfo' === $node->name->toLowerString() && self::is_nullable_array( $node->returnType ) ) {
			$finding           = $context->report( 'signature.debuginfo_nullable_return', $node->returnType, array( 'deprecated' => '8.6' ) );
			$finding->subject  = '__debugInfo(): ' . $context->text( $node->returnType->getStartFilePos(), $node->returnType->getEndFilePos() );
			$finding->instead  = '__debugInfo(): array';
			$finding->evidence = 'documented';

			return;
		}

		$structor = self::structor( $node );

		if ( null === $structor || null === $node->stmts || ! self::has_yield( $node->stmts ) ) {
			return;
		}

		$finding           = $context->report( 'syntax.constructor_generator', $node->name, array( 'deprecated' => '8.6' ) );
		$finding->subject  = $structor . '() with yield';
		$finding->instead  = 'a separate method that returns the generator';
		$finding->evidence = 'documented';
	}

	/**
	 * "__construct" or "__destruct" as written, when the method is one of them.
	 *
	 * @param Node\Stmt\ClassMethod $method Method.
	 * @return string|null
	 */
	private static function structor( Node\Stmt\ClassMethod $method ) {
		$name = $method->name->toLowerString();

		return '__construct' === $name || '__destruct' === $name ? $method->name->toString() : null;
	}

	/**
	 * Whether a return type is ?array, array|null or null|array.
	 *
	 * @param mixed $type Return type node or null.
	 * @return bool
	 */
	private static function is_nullable_array( $type ) {
		if ( $type instanceof Node\NullableType ) {
			return $type->type instanceof Node\Identifier && 'array' === $type->type->toLowerString();
		}

		if ( ! $type instanceof Node\UnionType || 2 !== count( $type->types ) ) {
			return false;
		}

		$names = array();

		foreach ( $type->types as $member ) {
			if ( ! $member instanceof Node\Identifier ) {
				return false;
			}

			$names[] = $member->toLowerString();
		}

		sort( $names );

		return array( 'array', 'null' ) === $names;
	}

	/**
	 * Whether a body has a yield of its own, not looking into nested
	 * functions or classes.
	 *
	 * @param mixed $nodes Node or nodes.
	 * @return bool
	 */
	private static function has_yield( $nodes ) {
		if ( $nodes instanceof Node ) {
			$nodes = array( $nodes );
		}

		if ( ! is_array( $nodes ) ) {
			return false;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof Node || $node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike ) {
				continue;
			}

			if ( $node instanceof Node\Expr\Yield_ || $node instanceof Node\Expr\YieldFrom ) {
				return true;
			}

			foreach ( $node->getSubNodeNames() as $name ) {
				if ( self::has_yield( $node->$name ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
