<?php
/**
 * __debugInfo() that returns null, deprecated in PHP 8.5.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: method.debuginfo_return_null (tranche 8.5-dep).
 *
 * Reported at each "return null;" and bare "return;" written directly in a
 * __debugInfo() method. The notice comes when var_dump() or print_r() reads
 * the object; the fix is to return array() instead. A null that comes from a
 * variable or a call is known at run time only and is not reported, nor are
 * returns inside closures or classes nested in the method.
 */
final class Php85DepDebugInfoNull implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Stmt\ClassMethod::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Stmt\ClassMethod $node    Method.
	 * @param Context               $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( '__debuginfo' !== $node->name->toLowerString() || null === $node->stmts ) {
			return;
		}

		// With a plain "array" return type, null is a TypeError, not this notice.
		if ( $node->returnType instanceof Node\Identifier && 'array' === $node->returnType->toLowerString() ) {
			return;
		}

		foreach ( self::returns( $node->stmts ) as $return ) {
			if ( null !== $return->expr && ! Php85DepFunctionArguments::is_literal( $return->expr, 'null' ) ) {
				continue;
			}

			$finding          = $context->report( 'method.debuginfo_return_null', $return, array( 'deprecated' => '8.5' ) );
			$finding->subject = '__debugInfo()';
			$finding->instead = 'return array();';
		}
	}

	/**
	 * Return statements of a body, not looking into nested functions or classes.
	 *
	 * @param Node[] $nodes Statements.
	 * @return Node\Stmt\Return_[]
	 */
	private static function returns( array $nodes ) {
		$found = array();

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof Node || $node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike ) {
				continue;
			}

			if ( $node instanceof Node\Stmt\Return_ ) {
				$found[] = $node;
				continue;
			}

			foreach ( $node->getSubNodeNames() as $name ) {
				$child = $node->$name;

				if ( $child instanceof Node ) {
					$child = array( $child );
				}

				if ( is_array( $child ) ) {
					$found = array_merge( $found, self::returns( $child ) );
				}
			}
		}

		return $found;
	}
}
