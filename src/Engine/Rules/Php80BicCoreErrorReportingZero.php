<?php
/**
 * error_reporting() compared with 0 to detect the @ operator.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\BinaryOp;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: call.error_reporting_zero_check. Changed in 8.0: inside an error
 * handler, error_reporting() is no longer 0 for an error silenced with @, so
 * a test like error_reporting() == 0 or ! error_reporting() no longer sees
 * the silence. The notes advise ! ( error_reporting() & $errno ).
 *
 * Reported only inside a function, method or closure with two to five
 * parameters (the shape of an error handler) in a file that calls
 * set_error_handler, for error_reporting() without arguments compared with
 * the literal 0 by ==, ===, != or !==, or negated with !.
 */
final class Php80BicCoreErrorReportingZero implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array(
			BinaryOp\Equal::class,
			BinaryOp\Identical::class,
			BinaryOp\NotEqual::class,
			BinaryOp\NotIdentical::class,
			Node\Expr\BooleanNot::class,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Expr $node    Comparison or negation.
	 * @param Context   $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( $node instanceof Node\Expr\BooleanNot ) {
			if ( ! $this->is_error_reporting( $node->expr, $context ) ) {
				return;
			}
		} elseif ( ! ( $this->is_error_reporting( $node->left, $context ) && self::is_zero( $node->right ) )
			&& ! ( $this->is_error_reporting( $node->right, $context ) && self::is_zero( $node->left ) ) ) {
			return;
		}

		$around = $node->getAttribute( 'parent' );

		while ( null !== $around && ! $around instanceof Node\FunctionLike ) {
			$around = $around->getAttribute( 'parent' );
		}

		// Only in something shaped like an error handler ($errno, $errstr, ...)
		// in a file that installs one: elsewhere error_reporting() == 0 asks
		// whether reporting is off altogether, which means the same on 8.0.
		if ( null === $around || count( $around->getParams() ) < 2 || count( $around->getParams() ) > 5 || false === stripos( $context->code, 'set_error_handler' ) ) {
			return;
		}

		$finding          = $context->report( 'call.error_reporting_zero_check', $node, array( 'changed' => '8.0' ) );
		$finding->subject = 'error_reporting()';
		$finding->instead = '! ( error_reporting() & $errno )';
	}

	/**
	 * Whether the expression is a call of the global error_reporting() without arguments.
	 *
	 * @param Node\Expr $expr    Expression.
	 * @param Context   $context Scan state.
	 * @return bool
	 */
	private function is_error_reporting( Node\Expr $expr, Context $context ) {
		return $expr instanceof Node\Expr\FuncCall
			&& 'error_reporting' === InternalFunctions::global_name( $expr->name )
			&& ! isset( $context->declared_functions['error_reporting'] )
			&& array() === $expr->args;
	}

	/**
	 * Whether the expression is the literal 0.
	 *
	 * @param Node\Expr $expr Expression.
	 * @return bool
	 */
	private static function is_zero( Node\Expr $expr ) {
		return $expr instanceof Node\Scalar\Int_ && 0 === $expr->value;
	}
}
