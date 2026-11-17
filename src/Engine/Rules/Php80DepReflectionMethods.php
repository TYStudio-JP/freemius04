<?php
/**
 * Reflection methods that PHP 8.0 deprecates.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\NodeFinder;

defined( 'ABSPATH' ) || exit;

/**
 * Rule ids (tranche 8.0-dep):
 *
 * - call.reflection_function_is_disabled  ReflectionFunction::isDisabled()
 * - call.reflection_parameter_type_method ReflectionParameter::getClass(),
 *                                          ::isArray(), ::isCallable()
 *
 * getClass(), isArray(), isCallable() and isDisabled() are common method
 * names, so a call is reported only where the code makes the receiver
 * certain: see reflection_type(). That covers new ReflectionParameter(...),
 * a parameter declared as ReflectionParameter, and the usual
 * "new ReflectionClass -> getConstructor() / getMethod() -> getParameters()
 * -> foreach" chain through local variables that are only ever assigned
 * such values. Anything else (properties, values returned by other
 * functions, top-level code) is not reported.
 *
 * The notices are raised when the call runs, so a PHP version check marks
 * a finding as guarded.
 */
final class Php80DepReflectionMethods implements Rule {

	const VERSION_GUARD = '8.0-dep: version check only';

	/** Reflection classes whose objects are followed. */
	const CLASSES = array( 'reflectionclass', 'reflectionobject', 'reflectionmethod', 'reflectionfunction', 'reflectionparameter' );

	/** Lower-case method name => what replaces it. */
	const PARAMETER_METHODS = array(
		'getclass'   => 'getClass',
		'isarray'    => 'isArray',
		'iscallable' => 'isCallable',
	);

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Expr\MethodCall::class, Node\Expr\NullsafeMethodCall::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Expr\MethodCall $node    Method call.
	 * @param Context              $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( ! $node->name instanceof Node\Identifier || $node->isFirstClassCallable() || 0 !== count( $node->args ) ) {
			return;
		}

		$method = $node->name->toLowerString();

		if ( 'isdisabled' === $method ) {
			if ( 'reflectionfunction' !== self::reflection_type( $node->var ) ) {
				return;
			}

			$finding          = $context->report( 'call.reflection_function_is_disabled', $node, array( 'deprecated' => '8.0' ) );
			$finding->subject = 'ReflectionFunction::isDisabled()';
			$finding->instead = 'nothing: since PHP 8.0 it always returns false, a disabled function cannot be reflected at all';
			$finding->guarded = $context->function_is_guarded( self::VERSION_GUARD );

			return;
		}

		if ( ! isset( self::PARAMETER_METHODS[ $method ] ) || 'reflectionparameter' !== self::reflection_type( $node->var ) ) {
			return;
		}

		$finding          = $context->report( 'call.reflection_parameter_type_method', $node, array( 'deprecated' => '8.0' ) );
		$finding->subject = 'ReflectionParameter::' . self::PARAMETER_METHODS[ $method ] . '()';
		$finding->instead = 'ReflectionParameter::getType() and the ReflectionNamedType API';
		$finding->guarded = $context->function_is_guarded( self::VERSION_GUARD );
	}

	/**
	 * The reflection class (lower case) of the value an expression certainly
	 * holds; "reflectionparameter[]" or "reflectionmethod[]" for the arrays
	 * getParameters() and getMethods() return; null when not certain.
	 *
	 * @param Node\Expr $expr  Expression.
	 * @param int       $depth Recursion depth.
	 * @return string|null
	 */
	public static function reflection_type( Node\Expr $expr, $depth = 0 ) {
		if ( $depth > 8 ) {
			return null;
		}

		if ( $expr instanceof Node\Expr\New_ ) {
			$class = self::global_class( $expr->class );

			return in_array( $class, self::CLASSES, true ) ? $class : null;
		}

		if ( $expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\NullsafeMethodCall ) {
			if ( ! $expr->name instanceof Node\Identifier || $expr->isFirstClassCallable() ) {
				return null;
			}

			$receiver = self::reflection_type( $expr->var, $depth + 1 );
			$method   = $expr->name->toLowerString();
			$class    = in_array( $receiver, array( 'reflectionclass', 'reflectionobject' ), true );

			if ( $class && in_array( $method, array( 'getconstructor', 'getmethod' ), true ) ) {
				return 'reflectionmethod';
			}

			if ( $class && 'getmethods' === $method ) {
				return 'reflectionmethod[]';
			}

			if ( in_array( $receiver, array( 'reflectionmethod', 'reflectionfunction' ), true ) && 'getparameters' === $method && 0 === count( $expr->args ) ) {
				return 'reflectionparameter[]';
			}

			return null;
		}

		if ( $expr instanceof Node\Expr\ArrayDimFetch && null !== $expr->dim ) {
			return self::element_of( self::reflection_type( $expr->var, $depth + 1 ) );
		}

		if ( $expr instanceof Node\Expr\Variable && is_string( $expr->name ) && 'this' !== $expr->name ) {
			$arrow = self::arrow_param_type( $expr );

			return false !== $arrow ? $arrow : self::variable_type( $expr, $depth + 1 );
		}

		return null;
	}

	/**
	 * Type of a variable that is a parameter of an enclosing arrow function:
	 * the declared class when the arrow function never writes the variable,
	 * null when it is such a parameter but not certain, false when no
	 * enclosing arrow function declares it.
	 *
	 * @param Node\Expr\Variable $variable Variable.
	 * @return string|null|false
	 */
	private static function arrow_param_type( Node\Expr\Variable $variable ) {
		$scope = $variable->getAttribute( 'parent' );

		while ( null !== $scope && ( ! $scope instanceof Node\FunctionLike || $scope instanceof Node\Expr\ArrowFunction ) ) {
			if ( $scope instanceof Node\Expr\ArrowFunction ) {
				foreach ( $scope->getParams() as $param ) {
					if ( ! $param->var instanceof Node\Expr\Variable || $variable->name !== $param->var->name ) {
						continue;
					}

					$type  = $param->type instanceof Node\NullableType ? $param->type->type : $param->type;
					$class = self::global_class( $type );

					if ( $param->byRef || $param->variadic || ! in_array( $class, self::CLASSES, true ) ) {
						return null;
					}

					$finder = new NodeFinder();
					$writes = $finder->find(
						$scope->expr,
						static function ( Node $node ) use ( $variable ) {
							return ( $node instanceof Node\Expr\Variable && ( ! is_string( $node->name ) || $variable->name === $node->name ) && null !== self::write_of( $node, $node->getAttribute( 'parent' ) ) )
								|| ( $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && in_array( strtolower( $node->name->getLast() ), array( 'extract', 'parse_str' ), true ) );
						}
					);

					return array() === $writes ? $class : null;
				}
			}

			$scope = $scope->getAttribute( 'parent' );
		}

		return false;
	}

	/**
	 * Element type of an array type, or null.
	 *
	 * @param string|null $type Type from reflection_type().
	 * @return string|null
	 */
	private static function element_of( $type ) {
		return is_string( $type ) && '[]' === substr( $type, -2 ) ? substr( $type, 0, -2 ) : null;
	}

	/**
	 * Type of a local variable of a function, method or closure: every value
	 * the body writes to it (and its declared parameter type) must agree, and
	 * every write must be visible.
	 *
	 * @param Node\Expr\Variable $variable Variable.
	 * @param int                $depth    Recursion depth.
	 * @return string|null
	 */
	private static function variable_type( Node\Expr\Variable $variable, $depth ) {
		$scope = self::scope_of( $variable );

		if ( ! $scope instanceof Node\Stmt\Function_ && ! $scope instanceof Node\Stmt\ClassMethod && ! $scope instanceof Node\Expr\Closure ) {
			return null;
		}

		$name  = $variable->name;
		$types = array();

		foreach ( $scope->getParams() as $param ) {
			if ( ! $param->var instanceof Node\Expr\Variable || $name !== $param->var->name ) {
				continue;
			}

			$type  = $param->type instanceof Node\NullableType ? $param->type->type : $param->type;
			$class = self::global_class( $type );

			if ( $param->byRef || $param->variadic || ! in_array( $class, self::CLASSES, true ) ) {
				return null;
			}

			$types[] = $class;
		}

		if ( $scope instanceof Node\Expr\Closure ) {
			foreach ( $scope->uses as $use ) {
				if ( $name === $use->var->name ) {
					return null;
				}
			}
		}

		$stmts = $scope->getStmts();

		if ( null === $stmts ) {
			return null;
		}

		$finder = new NodeFinder();
		$nodes  = $finder->find(
			$stmts,
			static function ( Node $node ) {
				return $node instanceof Node\Expr\Variable
					|| ( $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && in_array( strtolower( $node->name->getLast() ), array( 'extract', 'parse_str', 'get_defined_vars' ), true ) );
			}
		);

		foreach ( $nodes as $node ) {
			if ( $node instanceof Node\Expr\FuncCall ) {
				if ( self::scope_of( $node ) === $scope ) {
					return null;
				}

				continue;
			}

			// $$name may be anything.
			if ( ! is_string( $node->name ) ) {
				if ( self::scope_of( $node ) === $scope ) {
					return null;
				}

				continue;
			}

			if ( $name !== $node->name ) {
				continue;
			}

			$parent = $node->getAttribute( 'parent' );

			// A closure that takes the variable by reference may change it.
			if ( $parent instanceof Node\ClosureUse ) {
				if ( $parent->byRef ) {
					return null;
				}

				continue;
			}

			if ( self::scope_of( $node ) !== $scope ) {
				continue;
			}

			$write = self::write_of( $node, $parent );

			if ( false === $write ) {
				return null;
			}

			if ( null === $write ) {
				continue;
			}

			$type = $write instanceof Node\Stmt\Foreach_
				? self::element_of( self::reflection_type( $write->expr, $depth + 1 ) )
				: self::reflection_type( $write, $depth + 1 );

			if ( null === $type ) {
				return null;
			}

			$types[] = $type;
		}

		$types = array_unique( $types );

		return 1 === count( $types ) ? reset( $types ) : null;
	}

	/**
	 * What an occurrence of a variable does to it: null for a read, the
	 * assigned expression, the Foreach_ whose value it receives, or false
	 * for a write whose value is not visible.
	 *
	 * @param Node\Expr\Variable $node   Occurrence.
	 * @param Node|null          $parent Its parent.
	 * @return Node\Expr|Node\Stmt\Foreach_|false|null
	 */
	private static function write_of( Node\Expr\Variable $node, $parent ) {
		if ( $parent instanceof Node\Expr\Assign && $parent->var === $node ) {
			return $parent->expr;
		}

		if ( $parent instanceof Node\Stmt\Foreach_ ) {
			if ( $parent->valueVar === $node ) {
				return $parent;
			}

			return $parent->keyVar === $node ? false : null;
		}

		if ( $parent instanceof Node\Expr\AssignRef
			|| ( $parent instanceof Node\Expr\AssignOp && $parent->var === $node )
			|| $parent instanceof Node\Expr\PreInc
			|| $parent instanceof Node\Expr\PreDec
			|| $parent instanceof Node\Expr\PostInc
			|| $parent instanceof Node\Expr\PostDec
			|| $parent instanceof Node\Stmt\Global_
			|| $parent instanceof Node\StaticVar
			|| $parent instanceof Node\Stmt\Catch_
			|| $parent instanceof Node\Stmt\Unset_
			|| $parent instanceof Node\Expr\List_
			|| $parent instanceof Node\Param ) {
			// A Param here belongs to an arrow function, which shadows the variable.
			return false;
		}

		if ( $parent instanceof Node\ArrayItem ) {
			if ( $parent->byRef ) {
				return false;
			}

			// [ $a, $b ] = ...: destructuring writes the variable.
			$outer = $parent->getAttribute( 'parent' );

			while ( $outer instanceof Node\Expr\Array_ || $outer instanceof Node\Expr\List_ || $outer instanceof Node\ArrayItem ) {
				$inner = $outer;
				$outer = $outer->getAttribute( 'parent' );

				if ( $outer instanceof Node\Expr\Assign || $outer instanceof Node\Expr\AssignRef ) {
					return $outer->var === $inner ? false : null;
				}

				if ( $outer instanceof Node\Stmt\Foreach_ ) {
					return $outer->valueVar === $inner || $outer->keyVar === $inner ? false : null;
				}
			}
		}

		return null;
	}

	/**
	 * The function, method or closure whose variables a node uses. Arrow
	 * functions read the variables around them, so they are passed over.
	 *
	 * @param Node $node Node.
	 * @return Node|null
	 */
	private static function scope_of( Node $node ) {
		$scope = $node->getAttribute( 'parent' );

		while ( null !== $scope && ( ! $scope instanceof Node\FunctionLike || $scope instanceof Node\Expr\ArrowFunction ) ) {
			$scope = $scope->getAttribute( 'parent' );
		}

		return $scope;
	}

	/**
	 * Lower-case name of the global class a name refers to, or null.
	 *
	 * @param mixed $name Class name node or expression.
	 * @return string|null
	 */
	private static function global_class( $name ) {
		if ( ! $name instanceof Node\Name || $name->isSpecialClassName() ) {
			return null;
		}

		$resolved = $name->getAttribute( 'resolvedName' );
		$name     = $resolved instanceof Node\Name ? $resolved : $name;

		return 1 === count( $name->getParts() ) ? strtolower( $name->getLast() ) : null;
	}
}
