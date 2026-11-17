<?php
/**
 * is_resource() on what finfo_open() returned (tranche 8.1-bic).
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
 * Rule id: call.is_resource_finfo. Changed in 8.1.
 *
 *     $finfo = finfo_open( FILEINFO_MIME_TYPE );
 *     if ( is_resource( $finfo ) ) { ... }      // false from 8.1: finfo object
 *
 * From 8.1 finfo_open() returns a finfo object, so is_resource() is false
 * even when it worked, and the code silently takes the other branch.
 *
 * Reported when is_resource() is given
 *
 * - a finfo_open() call directly (with or without @), or
 * - a local variable of a function, method or closure that is only ever
 *   assigned the result of finfo_open() in that function, where the
 *   is_resource() call comes after such an assignment.
 *
 * The variable is given up on as soon as anything else could write to it: a
 * parameter or closure "use" of that name, any other assignment, a reference,
 * list(), foreach, global, static, unset(), extract() or parse_str() in the
 * function, or passing it to any call but finfo_*() and a few that only look
 * at it. A handle kept in a property or in a variable outside a function is
 * not followed (fixture runtime.is_resource_finfo_elsewhere).
 *
 * The fix writes ( false !== ... ) in place of the is_resource() call, the
 * check the notes recommend; finfo_open() returns false when it fails on
 * every version.
 */
final class Php81BicFinfoIsResource implements Rule {

	/**
	 * Functions that may be given the handle without making it something else.
	 */
	const ONLY_LOOK = array(
		'is_resource'       => true,
		'is_object'         => true,
		'is_bool'           => true,
		'gettype'           => true,
		'get_resource_type' => true,
		'var_dump'          => true,
	);

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Expr\FuncCall::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Expr\FuncCall $node    Function call.
	 * @param Context       $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( 'is_resource' !== InternalFunctions::global_name( $node->name ) || isset( $context->declared_functions['is_resource'] ) ) {
			return;
		}

		if ( 1 !== count( $node->args ) || ! $node->args[0] instanceof Node\Arg || $node->args[0]->unpack ) {
			return;
		}

		$value = $node->args[0]->value;

		if ( self::is_finfo_open( $value, $context ) ) {
			$this->report( $node, $value, $context );
			return;
		}

		if ( $value instanceof Expr\Variable && is_string( $value->name ) && $this->only_finfo( $value, $context ) ) {
			$this->report( $node, $value, $context );
		}
	}

	/**
	 * Records the finding and its fix.
	 *
	 * @param Expr\FuncCall $call    The is_resource() call.
	 * @param Expr          $value   Its argument.
	 * @param Context       $context Scan state.
	 */
	private function report( Expr\FuncCall $call, Expr $value, Context $context ) {
		$argument = $context->text( $value->getStartFilePos(), $value->getEndFilePos() );

		$finding          = $context->report( 'call.is_resource_finfo', $call, array( 'changed' => '8.1' ) );
		$finding->subject = 'is_resource( ' . $argument . ' )';
		$finding->instead = 'false !== ' . $argument;
		$finding->fix     = array(
			'kind'  => 'replace',
			'start' => $call->getStartFilePos(),
			'end'   => $call->getEndFilePos(),
			'text'  => '( false !== ' . $argument . ' )',
		);
	}

	/**
	 * Whether the expression is a call to the global finfo_open().
	 *
	 * @param Node    $value   Expression.
	 * @param Context $context Scan state.
	 * @return bool
	 */
	private static function is_finfo_open( $value, Context $context ) {
		// @finfo_open( ... ): the @ changes nothing about what is returned.
		while ( $value instanceof Expr\ErrorSuppress ) {
			$value = $value->expr;
		}

		return $value instanceof Expr\FuncCall
			&& 'finfo_open' === InternalFunctions::global_name( $value->name )
			&& ! isset( $context->declared_functions['finfo_open'] );
	}

	/**
	 * The function, method or closure whose local variables the node uses,
	 * or null at the top of the file. Arrow functions see the variables of
	 * the code around them, so they are looked through.
	 *
	 * @param Node $node Node.
	 * @return Node\FunctionLike|null
	 */
	private static function scope( Node $node ) {
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
	 * finfo_open() results, one of them before the is_resource() call.
	 *
	 * @param Expr\Variable $variable The is_resource() argument.
	 * @param Context       $context  Scan state.
	 * @return bool
	 */
	private function only_finfo( Expr\Variable $variable, Context $context ) {
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
		$assigned = false;

		foreach ( $finder->findInstanceOf( $scope->getStmts(), Expr\FuncCall::class ) as $call ) {
			if ( in_array( InternalFunctions::global_name( $call->name ), array( 'extract', 'parse_str', 'compact' ), true ) && self::scope( $call ) === $scope ) {
				return false;
			}
		}

		// A closure inside that takes the variable by reference may change it.
		foreach ( $finder->findInstanceOf( $scope->getStmts(), Node\ClosureUse::class ) as $closure_use ) {
			if ( $closure_use->byRef && $closure_use->var->name === $name ) {
				return false;
			}
		}

		foreach ( $finder->findInstanceOf( $scope->getStmts(), Expr\Variable::class ) as $use ) {
			if ( ! is_string( $use->name ) ) {
				// $$name: any variable may be written.
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
				if ( ! self::is_finfo_open( $parent->expr, $context ) ) {
					return false;
				}

				if ( $parent->getEndFilePos() < $variable->getStartFilePos() ) {
					$assigned = true;
				}

				continue;
			}

			if ( ! $this->only_read( $use, $parent ) ) {
				return false;
			}
		}

		return $assigned;
	}

	/**
	 * Whether this use of the variable only reads it.
	 *
	 * @param Expr\Variable $use    Variable.
	 * @param Node|null     $parent Its parent node.
	 * @return bool
	 */
	private function only_read( Expr\Variable $use, $parent ) {
		if ( $parent instanceof Node\Arg ) {
			$call = $parent->getAttribute( 'parent' );

			if ( ! $call instanceof Expr\FuncCall ) {
				return false;
			}

			$function = InternalFunctions::global_name( $call->name );

			return null !== $function && ( 0 === strpos( $function, 'finfo_' ) || isset( self::ONLY_LOOK[ $function ] ) );
		}

		if ( $parent instanceof Expr\Assign || $parent instanceof Expr\AssignOp || $parent instanceof Expr\AssignRef ) {
			// On the right of a plain assignment it is only read.
			return $parent instanceof Expr\Assign && $parent->expr === $use;
		}

		if ( $parent instanceof Node\Stmt\Expression || $parent instanceof Node\Stmt\Return_ || $parent instanceof Node\Stmt\If_ || $parent instanceof Node\Stmt\ElseIf_ ) {
			return true;
		}

		if ( $parent instanceof Expr\BooleanNot || $parent instanceof Expr\BinaryOp || $parent instanceof Expr\Ternary || $parent instanceof Expr\Isset_ || $parent instanceof Expr\Empty_ || $parent instanceof Expr\Instanceof_ ) {
			return true;
		}

		if ( $parent instanceof Expr\MethodCall && $parent->var === $use ) {
			return true;
		}

		// unset(), foreach, list(), global, static, ++, by-reference closures,
		// array items and everything else: may write, so give up.
		return false;
	}
}
