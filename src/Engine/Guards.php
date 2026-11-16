<?php
/**
 * Recognises code that only runs under a check.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine;

use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Stmt;
use TYStudio\UpgradeWarden\Vendor\PhpParser\NodeFinder;

defined( 'ABSPATH' ) || exit;

/**
 * Well-kept plugins call a removed function only after asking whether it
 * exists, or only on old PHP versions. Reporting those calls as breakage would
 * be wrong, and wrong reports are what made earlier checkers hard to trust.
 * Such findings are kept, but marked as guarded.
 *
 * The reading is deliberately generous: a check anywhere in the condition
 * covers both branches. It can mark too much as guarded, never too little
 * marked as safe without a check being present at all.
 */
final class Guards {

	/**
	 * Functions that release a handle which used to be a resource and is an
	 * object in newer PHP. They are deprecated because they no longer do
	 * anything. Behind "if ( is_resource( $handle ) )" they are never reached
	 * on a version where the handle is an object, so no notice is raised.
	 *
	 * Only functions with a fixture (guard.is_resource~*.fixture) that showed
	 * this on real runtimes are listed.
	 */
	const RELEASES_A_FORMER_RESOURCE = array(
		'curl_close'      => true,
		'xml_parser_free' => true,
		'imagedestroy'    => true,
		'finfo_close'     => true,
	);

	/**
	 * A guard that covers nothing.
	 *
	 * @return array
	 */
	public static function none() {
		return array(
			'functions' => array(),
			'constants' => array(),
			'version'   => false,
			'resource'  => false,
		);
	}

	/**
	 * What the given conditions check for.
	 *
	 * @param Node[] $conditions Condition expressions.
	 * @return array Guard with the keys functions, constants and version.
	 */
	public static function from_conditions( array $conditions ) {
		$guard  = self::none();
		$finder = new NodeFinder();
		$nodes  = $finder->find(
			$conditions,
			static function ( Node $node ) {
				return $node instanceof Expr\FuncCall || $node instanceof Expr\ConstFetch;
			}
		);

		foreach ( $nodes as $node ) {
			if ( ! $node->name instanceof Node\Name ) {
				continue;
			}

			$name = $node->name->getLast();

			if ( $node instanceof Expr\ConstFetch ) {
				if ( in_array( $name, array( 'PHP_VERSION', 'PHP_VERSION_ID', 'PHP_MAJOR_VERSION', 'PHP_MINOR_VERSION' ), true ) ) {
					$guard['version'] = true;
				}

				continue;
			}

			$name  = strtolower( $name );
			$first = isset( $node->args[0] ) && $node->args[0] instanceof Node\Arg ? $node->args[0]->value : null;

			if ( 'version_compare' === $name || 'phpversion' === $name ) {
				$guard['version'] = true;
			} elseif ( 'is_resource' === $name ) {
				$guard['resource'] = true;
			} elseif ( $first instanceof Node\Scalar\String_ && ( 'function_exists' === $name || 'is_callable' === $name ) ) {
				$guard['functions'][ strtolower( ltrim( $first->value, '\\' ) ) ] = true;
			} elseif ( $first instanceof Node\Scalar\String_ && 'defined' === $name ) {
				$guard['constants'][ ltrim( $first->value, '\\' ) ] = true;
			}
		}

		return $guard;
	}

	/**
	 * What the rest of a block is protected by, through statements such as
	 * "if ( ! function_exists( 'x' ) ) { return; }".
	 *
	 * @param Node[] $statements Statements of a function body or a file.
	 * @return array Guard with the keys functions, constants and version.
	 */
	public static function from_early_exits( array $statements ) {
		$conditions = array();

		foreach ( $statements as $statement ) {
			if ( ! $statement instanceof Stmt\If_ || $statement->elseifs || null !== $statement->else || ! $statement->stmts ) {
				continue;
			}

			$last = $statement->stmts[ count( $statement->stmts ) - 1 ];
			$expr = $last instanceof Stmt\Expression ? $last->expr : null;

			if ( $last instanceof Stmt\Return_ || $last instanceof Stmt\Continue_ || $last instanceof Stmt\Break_ || $expr instanceof Expr\Exit_ || $expr instanceof Expr\Throw_ ) {
				$conditions[] = $statement->cond;
			}
		}

		return $conditions ? self::from_conditions( $conditions ) : self::none();
	}
}
