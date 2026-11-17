<?php
/**
 * Cloning a DOMXPath object, an Error from PHP 8.4.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\Assign;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\Clone_;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\MethodCall;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\New_;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\NullsafeMethodCall;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\Variable;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: clone.dom_xpath. Breaks in 8.4.
 *
 *     $xpath = new DOMXPath( $document );
 *     $copy  = clone $xpath;
 *
 * Up to 8.3 the clone succeeded (and gave an object that could not be used);
 * from 8.4 cloning throws "Trying to clone an uncloneable object of class
 * DOMXPath".
 *
 * The class of a variable is not in the source, so a clone is reported only
 * when it is "clone new DOMXPath(...)", or when it clones a variable of a
 * function or method in which every use of that variable is one of: an
 * assignment of new DOMXPath(...) to it, reading it into another variable,
 * a method call on it, or the clone. A variable that is a parameter, global,
 * static, captured by a closure, passed as an argument or assigned anything
 * else is not judged. Code outside functions is not judged either.
 */
final class Php84BicCoreDomXPathClone implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Clone_::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Clone_  $node    Clone expression.
	 * @param Context $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		$expr = $node->expr;

		if ( ! self::is_new_xpath( $expr ) && ! self::variable_holds_xpath( $node ) ) {
			return;
		}

		$finding          = $context->report( 'clone.dom_xpath', $node, array( 'breaks' => '8.4' ) );
		$finding->subject = 'clone of a DOMXPath';
		$finding->instead = 'new DOMXPath( $document ) for a second object';
	}

	/**
	 * Whether an expression is new DOMXPath(...), by its global name.
	 *
	 * @param Node $expr Expression.
	 * @return bool
	 */
	private static function is_new_xpath( Node $expr ) {
		if ( ! $expr instanceof New_ || ! $expr->class instanceof Node\Name ) {
			return false;
		}

		$name     = $expr->class;
		$resolved = $name->getAttribute( 'resolvedName' );

		if ( $resolved instanceof Node\Name ) {
			$name = $resolved;
		}

		return 1 === count( $name->getParts() ) && 'domxpath' === strtolower( $name->getLast() );
	}

	/**
	 * Whether the cloned variable can only hold a DOMXPath (see the class doc).
	 *
	 * @param Clone_ $clone Clone expression.
	 * @return bool
	 */
	private static function variable_holds_xpath( Clone_ $clone ) {
		$variable = $clone->expr;

		if ( ! $variable instanceof Variable || ! is_string( $variable->name ) || 'this' === $variable->name ) {
			return false;
		}

		$scope = $clone->getAttribute( 'parent' );

		while ( null !== $scope && ! $scope instanceof Node\FunctionLike ) {
			if ( $scope instanceof Node\Stmt\ClassLike ) {
				return false;
			}

			$scope = $scope->getAttribute( 'parent' );
		}

		// Arrow functions see the variables of the code around them.
		if ( ! $scope instanceof Node\Stmt\Function_ && ! $scope instanceof Node\Stmt\ClassMethod && ! $scope instanceof Node\Expr\Closure ) {
			return false;
		}

		foreach ( $scope->getParams() as $param ) {
			if ( $param->var instanceof Variable && $variable->name === $param->var->name ) {
				return false;
			}
		}

		if ( $scope instanceof Node\Expr\Closure ) {
			foreach ( $scope->uses as $use ) {
				if ( $variable->name === $use->var->name ) {
					return false;
				}
			}
		}

		$uses    = array();
		$pending = (array) $scope->getStmts();

		// Every occurrence of the variable in the body, without entering
		// functions, closures or classes declared inside it.
		while ( $pending ) {
			$current = array_pop( $pending );

			if ( ! $current instanceof Node ) {
				continue;
			}

			if ( $current instanceof Node\FunctionLike && ! $current instanceof Node\Expr\ArrowFunction ) {
				if ( $current instanceof Node\Expr\Closure ) {
					foreach ( $current->uses as $use ) {
						if ( $variable->name === $use->var->name ) {
							return false;
						}
					}
				}

				continue;
			}

			if ( $current instanceof Node\Stmt\ClassLike ) {
				continue;
			}

			if ( $current instanceof Variable && $variable->name === $current->name ) {
				$uses[] = $current;
			}

			// Code that can write any variable of the scope.
			if ( ( $current instanceof Variable && ! is_string( $current->name ) ) || $current instanceof Node\Expr\Include_ || $current instanceof Node\Expr\Eval_ ) {
				return false;
			}

			if ( $current instanceof Node\Expr\FuncCall && in_array( InternalFunctions::global_name( $current->name ), array( 'extract', 'parse_str' ), true ) ) {
				return false;
			}

			foreach ( $current->getSubNodeNames() as $sub ) {
				$child = $current->$sub;

				if ( is_array( $child ) ) {
					foreach ( $child as $item ) {
						$pending[] = $item;
					}
				} else {
					$pending[] = $child;
				}
			}
		}

		$assigned = false;

		foreach ( $uses as $use ) {
			$parent = $use->getAttribute( 'parent' );

			if ( $parent instanceof Assign && $parent->var === $use ) {
				if ( ! self::is_new_xpath( $parent->expr ) ) {
					return false;
				}

				$assigned = true;
			} elseif ( $parent instanceof Assign && $parent->expr === $use && $parent->var instanceof Variable ) {
				continue;
			} elseif ( ( $parent instanceof MethodCall || $parent instanceof NullsafeMethodCall ) && $parent->var === $use ) {
				continue;
			} elseif ( ! $parent instanceof Clone_ ) {
				return false;
			}
		}

		return $assigned;
	}
}
