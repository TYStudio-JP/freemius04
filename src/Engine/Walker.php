<?php
/**
 * Walks the syntax tree, keeps track of guards and hands nodes to the rules.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine;

use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Stmt;
use TYStudio\UpgradeWarden\Vendor\PhpParser\NodeVisitorAbstract;

defined( 'ABSPATH' ) || exit;

/**
 * Tree visitor.
 */
final class Walker extends NodeVisitorAbstract {

	/**
	 * Scan state.
	 *
	 * @var Context
	 */
	private $context;

	/**
	 * Rules by the node class they want to see.
	 *
	 * @var array<string,Rules\Rule[]>
	 */
	private $rules;

	/**
	 * Loop depths of the functions around the one being visited.
	 *
	 * @var int[]
	 */
	private $loop_depths = array();

	/**
	 * Constructor.
	 *
	 * @param Context                    $context Scan state.
	 * @param array<string,Rules\Rule[]> $rules   Rules by node class.
	 */
	public function __construct( Context $context, array $rules ) {
		$this->context = $context;
		$this->rules   = $rules;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node[] $nodes Statements of the file.
	 */
	public function beforeTraverse( array $nodes ) {
		$this->context->push_guard( Guards::from_early_exits( $nodes ) );

		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node[] $nodes Statements of the file.
	 */
	public function afterTraverse( array $nodes ) {
		$this->context->pop_guard();

		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node $node Node being entered.
	 */
	public function enterNode( Node $node ) {
		$guard = $this->guard_of( $node );

		if ( null !== $guard ) {
			$this->context->push_guard( $guard );
			$node->setAttribute( 'upgw_guard', true );
		}

		// A function starts counting loops afresh: a loop around the function
		// declaration is not a loop around the statements inside it.
		if ( $node instanceof Node\FunctionLike ) {
			$this->loop_depths[]       = $this->context->loop_depth;
			$this->context->loop_depth = 0;
		} elseif ( self::is_loop( $node ) ) {
			++$this->context->loop_depth;
		}

		$class = get_class( $node );

		if ( isset( $this->rules[ $class ] ) ) {
			foreach ( $this->rules[ $class ] as $rule ) {
				$rule->inspect( $node, $this->context );
			}
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node $node Node being left.
	 */
	public function leaveNode( Node $node ) {
		if ( $node->getAttribute( 'upgw_guard' ) ) {
			$this->context->pop_guard();
		}

		if ( $node instanceof Node\FunctionLike ) {
			$this->context->loop_depth = array_pop( $this->loop_depths );
		} elseif ( self::is_loop( $node ) ) {
			--$this->context->loop_depth;
		}

		return null;
	}

	/**
	 * Whether "break" and "continue" may refer to this node.
	 *
	 * @param Node $node Node.
	 * @return bool
	 */
	private static function is_loop( Node $node ) {
		return $node instanceof Stmt\For_
			|| $node instanceof Stmt\Foreach_
			|| $node instanceof Stmt\While_
			|| $node instanceof Stmt\Do_
			|| $node instanceof Stmt\Switch_;
	}

	/**
	 * The guard a node puts around what is inside it, or null.
	 *
	 * @param Node $node Node being entered.
	 * @return array|null
	 */
	private function guard_of( Node $node ) {
		if ( $node instanceof Stmt\If_ ) {
			$conditions = array( $node->cond );

			foreach ( $node->elseifs as $elseif ) {
				$conditions[] = $elseif->cond;
			}

			return Guards::from_conditions( $conditions );
		}

		if ( $node instanceof Expr\Ternary ) {
			return Guards::from_conditions( array( $node->cond ) );
		}

		if ( $node instanceof Expr\BinaryOp\BooleanAnd || $node instanceof Expr\BinaryOp\LogicalAnd ) {
			return Guards::from_conditions( array( $node->left ) );
		}

		if ( $node instanceof Node\FunctionLike && is_array( $node->getStmts() ) ) {
			return Guards::from_early_exits( $node->getStmts() );
		}

		return null;
	}
}
