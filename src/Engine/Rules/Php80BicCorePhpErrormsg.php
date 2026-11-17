<?php
/**
 * Reads of $php_errormsg, which only the removed track_errors setting filled.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\NodeFinder;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: variable.php_errormsg. Changed in 8.0: track_errors is gone, so
 * PHP never sets $php_errormsg any more and the variable stays undefined.
 *
 * The runtimes show no deprecation on 7.4 for ini_set( 'track_errors', '1' ),
 * although the 7.2 notes deprecate the setting (it is reported at start-up
 * only, when it is in php.ini), so the finding has no deprecation version.
 *
 * PHP set the variable in the scope where the error happened. So a read is
 * reported only when the same function (or the top level of the file) has
 * an @ before it, the way such code silences the error it then inspects. A
 * read with no error before it in its scope, like a logger method that only
 * appends $php_errormsg when it happens to be set, was never filled on 7.4
 * either. Not reported either when the same scope assigns the variable
 * itself: then it is an ordinary variable of that code.
 */
final class Php80BicCorePhpErrormsg implements Rule {

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
		if ( 'php_errormsg' !== $node->name ) {
			return;
		}

		$parent = $node->getAttribute( 'parent' );

		if ( self::is_write( $node, $parent ) || $parent instanceof Node\Stmt\Global_ || $parent instanceof Node\Stmt\Unset_ || $parent instanceof Node\Param || $parent instanceof Node\ClosureUse ) {
			return;
		}

		$scope = $parent;

		while ( null !== $scope && ! $scope instanceof Node\FunctionLike ) {
			$scope = $scope->getAttribute( 'parent' );
		}

		if ( ! $this->silenced_before( $scope, $node, $context ) || $this->assigned_in( $scope, $node ) ) {
			return;
		}

		$finding          = $context->report( 'variable.php_errormsg', $node, array( 'changed' => '8.0' ) );
		$finding->subject = '$php_errormsg';
		$finding->instead = 'error_get_last()';
	}

	/**
	 * Whether the variable is the target of a write.
	 *
	 * @param Node      $node   Variable.
	 * @param Node|null $parent Its parent.
	 * @return bool
	 */
	private static function is_write( Node $node, $parent ) {
		if ( ( $parent instanceof Node\Expr\Assign || $parent instanceof Node\Expr\AssignRef || $parent instanceof Node\Expr\AssignOp ) && $parent->var === $node ) {
			return true;
		}

		return ( $parent instanceof Node\ArrayItem || $parent instanceof Node\Expr\List_ ) || ( $parent instanceof Node\Stmt\Foreach_ && ( $parent->valueVar === $node || $parent->keyVar === $node ) ) || ( $parent instanceof Node\Stmt\Catch_ && $parent->var === $node ) || $parent instanceof Node\StaticVar;
	}

	/**
	 * Whether an @ comes before the read in the same scope.
	 *
	 * @param Node|null $scope   Function-like, or null for the top level.
	 * @param Node      $node    The variable that is read.
	 * @param Context   $context Scan state.
	 * @return bool
	 */
	private function silenced_before( $scope, Node $node, Context $context ) {
		$at = $node->getStartFilePos();

		if ( null === $scope ) {
			foreach ( Php80BicCoreIndex::of( $context )['silenced'] as $start ) {
				if ( $start < $at ) {
					return true;
				}
			}

			return false;
		}

		$stmts = $scope->getStmts();

		if ( ! is_array( $stmts ) ) {
			return false;
		}

		$finder = new NodeFinder();

		return null !== $finder->findFirst(
			$stmts,
			static function ( Node $inner ) use ( $at ) {
				return $inner instanceof Node\Expr\ErrorSuppress && $inner->getStartFilePos() < $at;
			}
		);
	}

	/**
	 * Whether the code of a function, or of the whole file when $scope is
	 * null, writes the variable, or receives it as a parameter.
	 *
	 * @param Node|null $scope Function-like, or null for the top level.
	 * @param Node      $node  The variable that is read.
	 * @return bool
	 */
	private function assigned_in( $scope, Node $node ) {
		if ( $scope instanceof Node\FunctionLike ) {
			foreach ( $scope->getParams() as $param ) {
				if ( $param->var instanceof Node\Expr\Variable && 'php_errormsg' === $param->var->name ) {
					return true;
				}
			}

			if ( $scope instanceof Node\Expr\Closure ) {
				foreach ( $scope->uses as $use ) {
					if ( 'php_errormsg' === $use->var->name ) {
						return true;
					}
				}
			}

			$nodes = $scope->getStmts();
			$nodes = is_array( $nodes ) ? $nodes : array();
		} else {
			$top = $node;

			while ( null !== $top->getAttribute( 'parent' ) ) {
				$top = $top->getAttribute( 'parent' );
			}

			// Only the statement the read is in is at hand at the top level.
			$nodes = array( $top );
		}

		$finder = new NodeFinder();
		$writes = $finder->findFirst(
			$nodes,
			static function ( Node $inner ) {
				if ( ! $inner instanceof Node\Expr\Variable || 'php_errormsg' !== $inner->name ) {
					return false;
				}

				return self::is_write( $inner, $inner->getAttribute( 'parent' ) );
			}
		);

		return null !== $writes;
	}
}
