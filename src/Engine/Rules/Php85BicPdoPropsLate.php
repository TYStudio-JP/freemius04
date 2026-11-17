<?php
/**
 * PDO::FETCH_PROPS_LATE combined with a fetch mode other than PDO::FETCH_CLASS.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\BinaryOp\BitwiseOr;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id (tranche 8.5-bic): constant.pdo_props_late_without_class. Breaks in 8.5.
 *
 *     $rows = $statement->fetchAll( PDO::FETCH_ASSOC | PDO::FETCH_PROPS_LATE );
 *
 * PHP 7.4 to 8.4 ignored the flag; 8.5 throws a ValueError ("cannot use
 * PDO::FETCH_CLASSTYPE, PDO::FETCH_PROPS_LATE, or PDO::FETCH_SERIALIZE fetch
 * flags with a fetch mode other than PDO::FETCH_CLASS"). The runtimes showed
 * it for fetch(), fetchAll(), setFetchMode(), PDO::query() and, at the next
 * fetch, for PDO::ATTR_DEFAULT_FETCH_MODE, so the combined value itself is
 * reported wherever it is written (fixtures constant.pdo_props_late_without_class~8.5-bic-*).
 *
 * Reported only when every operand of the "|" is a PDO::FETCH_* constant, one
 * of them is a fetch mode, none is PDO::FETCH_CLASS, and one is
 * PDO::FETCH_PROPS_LATE. PDO::FETCH_PROPS_LATE alone, without a mode, still
 * works on 8.5. The error is raised when the value is used, so a PHP version
 * check counts as a guard.
 */
final class Php85BicPdoPropsLate implements Rule {

	const VERSION_GUARD = '8.5-bic: version check only';

	/**
	 * Fetch modes other than PDO::FETCH_CLASS, lower case.
	 */
	const MODES = array( 'fetch_lazy', 'fetch_assoc', 'fetch_num', 'fetch_both', 'fetch_obj', 'fetch_bound', 'fetch_column', 'fetch_into', 'fetch_func', 'fetch_named', 'fetch_key_pair' );

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( BitwiseOr::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param BitwiseOr $node    "|" expression.
	 * @param Context   $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		// Only the outermost "|" of a chain.
		if ( $node->getAttribute( 'parent' ) instanceof BitwiseOr ) {
			return;
		}

		$names = array();

		if ( ! self::collect( $node, $names ) ) {
			return;
		}

		if ( ! in_array( 'fetch_props_late', $names, true ) || in_array( 'fetch_class', $names, true ) || 0 === count( array_intersect( $names, self::MODES ) ) ) {
			return;
		}

		$finding          = $context->report( 'constant.pdo_props_late_without_class', $node, array( 'breaks' => '8.5' ) );
		$finding->subject = 'PDO::FETCH_PROPS_LATE without PDO::FETCH_CLASS';
		$finding->instead = 'the same mode without PDO::FETCH_PROPS_LATE';
		$finding->guarded = $context->function_is_guarded( self::VERSION_GUARD );
	}

	/**
	 * Collects the lower-case constant names of a "|" chain of PDO constants.
	 *
	 * @param Node\Expr $expr  Expression.
	 * @param string[]  $names Names found so far.
	 * @return bool False when an operand is anything but a PDO class constant.
	 */
	private static function collect( Node\Expr $expr, array &$names ) {
		if ( $expr instanceof BitwiseOr ) {
			return self::collect( $expr->left, $names ) && self::collect( $expr->right, $names );
		}

		if ( ! $expr instanceof Node\Expr\ClassConstFetch || ! $expr->class instanceof Node\Name || ! $expr->name instanceof Node\Identifier ) {
			return false;
		}

		$resolved = $expr->class->getAttribute( 'resolvedName' );
		$resolved = $resolved instanceof Node\Name ? $resolved : $expr->class;

		if ( 'pdo' !== strtolower( ltrim( $resolved->toString(), '\\' ) ) ) {
			return false;
		}

		$names[] = strtolower( $expr->name->toString() );

		return true;
	}
}
