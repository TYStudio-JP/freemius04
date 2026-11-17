<?php
/**
 * Local variables whose value the PHP 8.0 extension rules (tranche
 * 8.0-bic-ext) can be sure of.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr;
use TYStudio\UpgradeWarden\Vendor\PhpParser\NodeFinder;

defined( 'ABSPATH' ) || exit;

/**
 * Not a rule of its own: it reports nothing and asks for no nodes. It lives in
 * Rules/ only so that the 8.0-bic-ext rules can share it without a change to a
 * shared file.
 *
 * only_assigned() answers: is this variable a local of a function, method or
 * closure that is written only by assignments of a known kind of value, one
 * of them before the use? It gives up (false) as soon as anything else could
 * write to the variable: a parameter or closure "use" of that name, another
 * assignment, a reference, list(), foreach, global, static, unset(),
 * extract(), parse_str() or compact() in the function, $$name, or any use that
 * the caller does not accept as a plain read. A variable outside a function
 * is never followed.
 */
final class Php80BicExtLocal implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node    $node    Never called.
	 * @param Context $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
	}

	/**
	 * The function, method or closure whose local variables the node uses,
	 * or null at the top of the file. Arrow functions see the variables of
	 * the code around them, so they are looked through.
	 *
	 * @param Node $node Node.
	 * @return Node\FunctionLike|null
	 */
	public static function scope( Node $node ) {
		$current = $node->getAttribute( 'parent' );

		while ( null !== $current ) {
			if ( $current instanceof Node\FunctionLike && ! $current instanceof Expr\ArrowFunction ) {
				return $current;
			}

			$current = $current->getAttribute( 'parent' );
		}

		return null;
	}

	/**
	 * Whether the variable is a local whose only writes in its function are
	 * values that $is_source accepts, one of them before $variable.
	 *
	 * @param Expr\Variable $variable  The use being judged.
	 * @param callable      $is_source function ( Expr $value ): bool, for the right side of an assignment.
	 * @param callable      $is_read   function ( Expr\Variable $use, Node $parent ): bool, for every other use.
	 * @return bool
	 */
	public static function only_assigned( Expr\Variable $variable, $is_source, $is_read ) {
		if ( ! is_string( $variable->name ) || 'this' === $variable->name ) {
			return false;
		}

		$scope = self::scope( $variable );

		if ( null === $scope || null === $scope->getStmts() ) {
			return false;
		}

		$name = $variable->name;

		foreach ( $scope->getParams() as $param ) {
			if ( $param->var instanceof Expr\Variable && $param->var->name === $name ) {
				return false;
			}
		}

		if ( $scope instanceof Expr\Closure ) {
			foreach ( $scope->uses as $use ) {
				if ( $use->var->name === $name ) {
					return false;
				}
			}
		}

		$finder   = new NodeFinder();
		$stmts    = $scope->getStmts();
		$assigned = false;

		foreach ( $finder->findInstanceOf( $stmts, Expr\FuncCall::class ) as $call ) {
			if ( in_array( InternalFunctions::global_name( $call->name ), array( 'extract', 'parse_str', 'compact', 'get_defined_vars' ), true ) && self::scope( $call ) === $scope ) {
				return false;
			}
		}

		foreach ( $finder->findInstanceOf( $stmts, Node\ClosureUse::class ) as $closure_use ) {
			if ( $closure_use->byRef && $closure_use->var->name === $name ) {
				return false;
			}
		}

		foreach ( $finder->findInstanceOf( $stmts, Expr\Variable::class ) as $use ) {
			if ( ! is_string( $use->name ) ) {
				if ( self::scope( $use ) === $scope ) {
					return false;
				}

				continue;
			}

			if ( $use->name !== $name || $use === $variable || self::scope( $use ) !== $scope ) {
				continue;
			}

			$parent = $use->getAttribute( 'parent' );

			if ( $parent instanceof Expr\Assign && $parent->var === $use ) {
				if ( ! call_user_func( $is_source, $parent->expr ) ) {
					return false;
				}

				if ( $parent->getEndFilePos() < $variable->getStartFilePos() ) {
					$assigned = true;
				}

				continue;
			}

			if ( $parent instanceof Expr\Assign && $parent->expr === $use ) {
				continue;
			}

			if ( ! call_user_func( $is_read, $use, $parent ) ) {
				return false;
			}
		}

		return $assigned;
	}

	/**
	 * Uses that read a variable and cannot change it, whatever it holds.
	 *
	 * @param Expr\Variable $use    Variable.
	 * @param Node|null     $parent Its parent node.
	 * @return bool
	 */
	public static function plain_read( Expr\Variable $use, $parent ) {
		if ( $parent instanceof Node\Stmt\Expression || $parent instanceof Node\Stmt\Return_ || $parent instanceof Node\Stmt\If_ || $parent instanceof Node\Stmt\ElseIf_ || $parent instanceof Node\Stmt\Echo_ ) {
			return true;
		}

		if ( $parent instanceof Expr\BooleanNot || $parent instanceof Expr\BinaryOp || $parent instanceof Expr\Ternary || $parent instanceof Expr\Isset_ || $parent instanceof Expr\Empty_ || $parent instanceof Expr\Instanceof_ ) {
			return true;
		}

		// $x->method(), $x->prop and $x->prop = ...: the object stays what it is.
		if ( ( $parent instanceof Expr\MethodCall || $parent instanceof Expr\PropertyFetch ) && $parent->var === $use ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether the expression, after any @, is a call to one of the global
	 * functions named (lower case), with no function of that name declared
	 * in the code that is scanned.
	 *
	 * @param Node    $value     Expression.
	 * @param array   $functions Lower-case function name => anything.
	 * @param Context $context   Scan state.
	 * @return string|null The function name, or null.
	 */
	public static function call_to( $value, array $functions, Context $context ) {
		while ( $value instanceof Expr\ErrorSuppress ) {
			$value = $value->expr;
		}

		if ( ! $value instanceof Expr\FuncCall ) {
			return null;
		}

		$name = InternalFunctions::global_name( $value->name );

		if ( null === $name || ! isset( $functions[ $name ] ) || isset( $context->declared_functions[ $name ] ) ) {
			return null;
		}

		return $name;
	}

	/**
	 * The lower-case global class name a name node refers to, or null for an
	 * expression, self, static or parent.
	 *
	 * @param Node $name Name node or expression.
	 * @return string|null
	 */
	public static function class_name( $name ) {
		if ( ! $name instanceof Node\Name ) {
			return null;
		}

		$resolved = $name->getAttribute( 'resolvedName' );
		$text     = strtolower( ltrim( $resolved instanceof Node\Name ? $resolved->toString() : $name->toString(), '\\' ) );

		if ( in_array( $text, array( 'self', 'static', 'parent' ), true ) ) {
			return null;
		}

		return $text;
	}
}
