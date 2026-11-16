<?php
/**
 * A float with a fractional part written where PHP needs an integer.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Scalar\Float_;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: conversion.float_literal_as_int. Deprecated in 8.1.
 *
 * "Implicit conversion from float 1.5 to int loses precision". Whether a
 * value is such a float is normally known only when the code runs (fixture
 * runtime.float_to_int_precision_loss). This rule reports the part that is
 * written out: a float literal with a fractional part as an array key, or as
 * an operand of %, |, &, ^, <<, >> and ~, where PHP always turns it into an
 * integer.
 *
 * Not reported: $value[1.5], because $value may be a string or an object
 * with ArrayAccess, and floats too large for an integer, which PHP 8.5 treats
 * differently again.
 */
final class Php81DepFloatLiteral implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array(
			Node\ArrayItem::class,
			Expr\BinaryOp\Mod::class,
			Expr\BinaryOp\BitwiseAnd::class,
			Expr\BinaryOp\BitwiseOr::class,
			Expr\BinaryOp\BitwiseXor::class,
			Expr\BinaryOp\ShiftLeft::class,
			Expr\BinaryOp\ShiftRight::class,
			Expr\AssignOp\Mod::class,
			Expr\AssignOp\BitwiseAnd::class,
			Expr\AssignOp\BitwiseOr::class,
			Expr\AssignOp\BitwiseXor::class,
			Expr\AssignOp\ShiftLeft::class,
			Expr\AssignOp\ShiftRight::class,
			Expr\BitwiseNot::class,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node    $node    Array item or integer operator.
	 * @param Context $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		$operands = array();

		if ( $node instanceof Node\ArrayItem ) {
			$operands[] = $node->key;
		} elseif ( $node instanceof Expr\BinaryOp ) {
			$operands[] = $node->left;
			$operands[] = $node->right;
		} else {
			$operands[] = $node->expr;
		}

		foreach ( $operands as $operand ) {
			$literal = $operand instanceof Expr\UnaryMinus ? $operand->expr : $operand;

			if ( ! $literal instanceof Float_ || ! self::loses_precision( $literal->value ) ) {
				continue;
			}

			$finding          = $context->report( 'conversion.float_literal_as_int', $operand, array( 'deprecated' => '8.1' ) );
			$finding->subject = $context->text( $operand->getStartFilePos(), $operand->getEndFilePos() );
			$finding->instead = (string) (int) ( $operand instanceof Expr\UnaryMinus ? -$literal->value : $literal->value );
		}
	}

	/**
	 * Whether the float has a fractional part and still fits an integer.
	 *
	 * @param float $value Value of the literal.
	 * @return bool
	 */
	private static function loses_precision( $value ) {
		return is_finite( $value ) && abs( $value ) < 1e15 && floor( $value ) !== $value;
	}
}
