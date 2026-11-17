<?php
/**
 * Reading $http_response_header, deprecated in PHP 8.5.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: variable.http_response_header (tranche 8.5-dep).
 *
 * The notice is raised when the file is compiled, whether or not the code
 * runs, for each fetch of the variable that PHP compiles as a read, until the
 * same scope has assigned the variable (in source order). What the PHP 8.5
 * runtime showed, and what this rule follows:
 *
 * - raised: a plain read, a read of $http_response_header[0], foreach over it
 *   or into it, "global" and "static" of it, ".=" and the like, an array item,
 *   an argument of a method, a constructor, a closure in a variable, an
 *   unpacked argument, and of a function PHP does not know yet when it
 *   compiles the call (a user function: also when it takes the parameter by
 *   reference), an argument taken by value by an internal function; reads in
 *   an arrow function, which has its own scope, even when the function around
 *   it assigned the variable;
 * - not raised: isset(), empty(), unset() and the left side of ??, ++ and --,
 *   use ( $http_response_header ) in a closure, a parameter of that name,
 *   an assignment to it, list() into it, references to it, passing it to a
 *   parameter of an internal function taken by reference;
 * - after "$http_response_header = ...", "$http_response_header[...] = ...",
 *   list() into it, a reference to it or a by-reference internal argument,
 *   later reads in that scope raise nothing; after foreach into it, ++, unset()
 *   or a closure use by reference they still do.
 *
 * Where the runtimes were not asked, the rule assumes nothing is raised and
 * that the variable counts as assigned from there on (argument of a function
 * declared with a by-reference parameter in this file or of a function from
 * another file, named arguments of internal functions, .= and global/static
 * for the reads after them, catch). In code outside functions the statements
 * around a node are not at hand, so any assignment written in the file, or an
 * earlier write the walker has seen, counts.
 *
 * No PHP version check keeps a compile-time notice away, so a finding is
 * never guarded. The replacement, http_get_last_response_headers(), exists
 * from PHP 8.4 on.
 */
final class Php85DepHttpResponseHeader implements Rule {

	const NAME = 'http_response_header';

	/**
	 * Scan state the earlier top-level writes belong to.
	 *
	 * @var Context|null
	 */
	private $context = null;

	/**
	 * Whether a write in code outside functions has been seen in this file.
	 *
	 * @var bool
	 */
	private $top_level_written = false;

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Expr\Variable::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Expr\Variable $node    Variable.
	 * @param Context            $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( self::NAME !== $node->name ) {
			return;
		}

		if ( $this->context !== $context ) {
			$this->context           = $context;
			$this->top_level_written = false;
		}

		$scope = self::scope_of( $node );

		if ( null === $scope ) {
			$raises = self::raises( $node, $context );

			if ( self::writes( $node, $context ) ) {
				$this->top_level_written = true;
			}

			// Code outside functions: the statements around it are not at hand,
			// so any assignment in the file counts.
			if ( ! $raises || $this->top_level_written || preg_match( '/&\s*\$http_response_header\b|\$http_response_header\s*(?:\[[^\]]*\]\s*)*(?:[-+*\/.%&|^]|\?\?|<<|>>|\*\*)?=(?!=)/', $context->code ) ) {
				return;
			}
		} else {
			if ( $node->getAttribute( 'parent' ) instanceof Node\Param || ! self::raises( $node, $context ) ) {
				return;
			}

			foreach ( $scope->getParams() as $param ) {
				if ( $param->var instanceof Node\Expr\Variable && self::NAME === $param->var->name ) {
					return;
				}
			}

			if ( self::written_before( $scope instanceof Node\Expr\ArrowFunction ? array( $scope->expr ) : $scope->getStmts(), $node->getStartFilePos(), $context ) ) {
				return;
			}
		}

		$finding          = $context->report( 'variable.http_response_header', $node, array( 'deprecated' => '8.5' ) );
		$finding->subject = '$http_response_header';
		$finding->instead = 'http_get_last_response_headers() (PHP 8.4+)';
	}

	/**
	 * The function, method, closure or arrow function a node is in, or null.
	 *
	 * @param Node $node Node.
	 * @return Node\FunctionLike|null
	 */
	private static function scope_of( Node $node ) {
		$scope = $node->getAttribute( 'parent' );

		while ( null !== $scope && ! $scope instanceof Node\FunctionLike ) {
			$scope = $scope->getAttribute( 'parent' );
		}

		return $scope;
	}

	/**
	 * The node whose use decides how the variable is fetched: the variable,
	 * or the outermost $http_response_header[...] / ->... around it.
	 *
	 * @param Node\Expr\Variable $node Variable.
	 * @return array{0: Node, 1: Node|null} The access and its parent.
	 */
	private static function access( Node\Expr\Variable $node ) {
		$child  = $node;
		$parent = $node->getAttribute( 'parent' );

		while ( ( $parent instanceof Node\Expr\ArrayDimFetch || $parent instanceof Node\Expr\PropertyFetch || $parent instanceof Node\Expr\NullsafePropertyFetch ) && $parent->var === $child ) {
			$child  = $parent;
			$parent = $parent->getAttribute( 'parent' );
		}

		return array( $child, $parent );
	}

	/**
	 * Whether this occurrence of the variable raises the notice, if the scope
	 * has not assigned the variable before it.
	 *
	 * @param Node\Expr\Variable $node    Variable.
	 * @param Context            $context Scan state.
	 * @return bool
	 */
	private static function raises( Node\Expr\Variable $node, Context $context ) {
		$direct = $node->getAttribute( 'parent' );

		if ( $direct instanceof Node\Stmt\Global_ || $direct instanceof Node\StaticVar ) {
			return true;
		}

		if ( $direct instanceof Node\Param ) {
			return false;
		}

		list( $child, $parent ) = self::access( $node );

		if ( $parent instanceof Node\Expr\Isset_ || $parent instanceof Node\Expr\Empty_ || $parent instanceof Node\Stmt\Unset_ ) {
			return false;
		}

		if ( $parent instanceof Node\Expr\BinaryOp\Coalesce && $parent->left === $child ) {
			return false;
		}

		if ( $parent instanceof Node\Expr\AssignOp && $parent->var === $child ) {
			// Shown for the variable itself only.
			return $child === $node;
		}

		if ( $parent instanceof Node\Expr\Assign || $parent instanceof Node\Expr\AssignRef ) {
			return $parent instanceof Node\Expr\Assign && $parent->expr === $child;
		}

		if ( $parent instanceof Node\Expr\PreInc || $parent instanceof Node\Expr\PreDec || $parent instanceof Node\Expr\PostInc || $parent instanceof Node\Expr\PostDec ) {
			return false;
		}

		if ( $parent instanceof Node\ClosureUse || $parent instanceof Node\Stmt\Catch_ ) {
			return false;
		}

		if ( $parent instanceof Node\ArrayItem ) {
			return $parent->value === $child && ! $parent->byRef && ! self::is_destructuring( $parent );
		}

		if ( $parent instanceof Node\Stmt\Foreach_ ) {
			if ( $parent->expr === $child ) {
				return ! $parent->byRef;
			}

			// foreach ( ... as $http_response_header ) raised the notice too.
			return $child === $node && ( $parent->keyVar === $child || ! $parent->byRef );
		}

		if ( $parent instanceof Node\Arg ) {
			return $parent->value === $child && true === self::argument_mode( $parent, $context );
		}

		return true;
	}

	/**
	 * Whether this occurrence counts as an assignment for the reads after it.
	 *
	 * @param Node\Expr\Variable $node    Variable.
	 * @param Context            $context Scan state.
	 * @return bool
	 */
	private static function writes( Node\Expr\Variable $node, Context $context ) {
		$direct = $node->getAttribute( 'parent' );

		if ( $direct instanceof Node\Stmt\Global_ || $direct instanceof Node\StaticVar || $direct instanceof Node\Stmt\Catch_ ) {
			return true;
		}

		list( $child, $parent ) = self::access( $node );

		if ( ( $parent instanceof Node\Expr\Assign || $parent instanceof Node\Expr\AssignOp ) && $parent->var === $child ) {
			return true;
		}

		if ( $parent instanceof Node\Expr\AssignRef ) {
			return true;
		}

		if ( $parent instanceof Node\ArrayItem ) {
			return $parent->value === $child && ( $parent->byRef || self::is_destructuring( $parent ) );
		}

		if ( $parent instanceof Node\Arg ) {
			return $parent->value === $child && true !== self::argument_mode( $parent, $context );
		}

		return false;
	}

	/**
	 * How an argument is passed, as far as PHP knows when it compiles the call:
	 * true when it is fetched as a read (by value to an internal function, or
	 * to anything PHP cannot know yet), false when it is passed by reference,
	 * null when this rule cannot tell.
	 *
	 * @param Node\Arg $arg     Argument.
	 * @param Context  $context Scan state.
	 * @return bool|null
	 */
	private static function argument_mode( Node\Arg $arg, Context $context ) {
		$call = $arg->getAttribute( 'parent' );

		if ( $arg->unpack ) {
			return true;
		}

		if ( $call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall || $call instanceof Node\Expr\StaticCall || $call instanceof Node\Expr\New_ ) {
			return true;
		}

		if ( ! $call instanceof Node\Expr\FuncCall ) {
			return null;
		}

		if ( ! $call->name instanceof Node\Name ) {
			// $callback( $http_response_header ).
			return true;
		}

		$short = strtolower( $call->name->getLast() );

		// A function of that name declared in this file: by reference unless
		// its parameters show no &.
		if ( preg_match_all( '/function\s+&?\s*' . preg_quote( $short, '/' ) . '\s*\(([^)]*)\)/i', $context->code, $matches ) ) {
			foreach ( $matches[1] as $params ) {
				if ( false !== strpos( $params, '&' ) ) {
					return null;
				}
			}

			return true;
		}

		$name = InternalFunctions::global_name( $call->name );

		if ( null === $name ) {
			return true;
		}

		if ( isset( $context->declared_functions[ $name ] ) ) {
			// Declared in another file of the plugin: parameters unknown.
			return null;
		}

		if ( ! function_exists( $name ) ) {
			return true;
		}

		$function = new \ReflectionFunction( $name );

		// A user function: the runtimes raised the notice for those, by
		// reference or not. Inside WordPress the PHP running the scan also knows
		// the functions of other plugins; answering the same way as for an
		// unknown function keeps the result independent of what is loaded.
		if ( ! $function->isInternal() ) {
			return true;
		}

		if ( null !== $arg->name ) {
			return null;
		}

		$index  = array_search( $arg, $call->args, true );
		$params = $function->getParameters();

		if ( false === $index || 0 === count( $params ) ) {
			return null;
		}

		$param = isset( $params[ $index ] ) ? $params[ $index ] : end( $params );

		if ( ! isset( $params[ $index ] ) && ! $param->isVariadic() ) {
			return null;
		}

		return ! $param->isPassedByReference();
	}

	/**
	 * Whether an array item is a target of list() or [ ... ] = destructuring.
	 *
	 * @param Node\ArrayItem $item Array item.
	 * @return bool
	 */
	private static function is_destructuring( Node\ArrayItem $item ) {
		$array = $item->getAttribute( 'parent' );

		while ( $array instanceof Node\Expr\List_ || $array instanceof Node\Expr\Array_ ) {
			if ( $array instanceof Node\Expr\List_ ) {
				return true;
			}

			$parent = $array->getAttribute( 'parent' );

			if ( $parent instanceof Node\Stmt\Foreach_ && $parent->valueVar === $array ) {
				return true;
			}

			if ( ( $parent instanceof Node\Expr\Assign || $parent instanceof Node\Expr\AssignRef ) && $parent->var === $array ) {
				return true;
			}

			if ( ! $parent instanceof Node\ArrayItem ) {
				return false;
			}

			$array = $parent->getAttribute( 'parent' );
		}

		return false;
	}

	/**
	 * Whether a scope body writes the variable at a position before $before,
	 * not looking into nested functions, closures or classes, which have
	 * scopes of their own.
	 *
	 * @param mixed   $nodes   Statements or expression.
	 * @param int     $before  File offset of the read.
	 * @param Context $context Scan state.
	 * @return bool
	 */
	private static function written_before( $nodes, $before, Context $context ) {
		if ( $nodes instanceof Node ) {
			$nodes = array( $nodes );
		}

		if ( ! is_array( $nodes ) ) {
			return false;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof Node || $node->getStartFilePos() >= $before ) {
				continue;
			}

			if ( $node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike ) {
				continue;
			}

			if ( $node instanceof Node\Expr\Variable && self::NAME === $node->name && self::writes( $node, $context ) ) {
				// A write that starts before the read, as in $x = f( $x ), counts.
				return true;
			}

			foreach ( $node->getSubNodeNames() as $sub ) {
				if ( self::written_before( $node->$sub, $before, $context ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
