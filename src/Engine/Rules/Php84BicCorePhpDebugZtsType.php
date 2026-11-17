<?php
/**
 * PHP_DEBUG and PHP_ZTS compared strictly with 0 or false: their type became bool in PHP 8.4.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\BinaryOp\Identical;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\BinaryOp\NotIdentical;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\ConstFetch;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Scalar\Int_;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: constant.php_debug_zts_type. Changed in 8.4.
 *
 *     if ( PHP_ZTS === 0 ) { ... }
 *     if ( PHP_DEBUG !== false ) { ... }
 *
 * Up to 8.3 both constants are the int 0 or 1; from 8.4 they are false or
 * true, so a strict comparison with 0 gives the opposite answer on the usual
 * (release, non thread-safe) build. The same holds, the other way round, for
 * a strict comparison with false (never true up to 8.3, true from 8.4).
 * Only comparisons with the literal 0 or false are reported: that is what the
 * runtimes here (release, non-ZTS) show. A comparison with 1 or true changes
 * only on a debug or thread-safe build, and loose comparisons (==, !=) and
 * truth tests keep working.
 */
final class Php84BicCorePhpDebugZtsType implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Identical::class, NotIdentical::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Identical|NotIdentical $node    Strict comparison.
	 * @param Context                $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		$name = self::constant_name( $node->left );
		$int  = $node->right;

		if ( null === $name ) {
			$name = self::constant_name( $node->right );
			$int  = $node->left;
		}

		if ( null === $name || ! ( ( $int instanceof Int_ && 0 === $int->value ) || self::is_false( $int ) ) ) {
			return;
		}

		$finding          = $context->report( 'constant.php_debug_zts_type', $node, array( 'changed' => '8.4' ) );
		$finding->subject = $name;
		$finding->instead = '(bool) ' . $name . ' or a loose comparison, which works before and after 8.4';
		$finding->guarded = $context->function_is_guarded( '8.4-bic-core: version check only' );
	}

	/**
	 * Whether an expression is the literal false (any case, global).
	 *
	 * @param Node $expr Expression.
	 * @return bool
	 */
	private static function is_false( Node $expr ) {
		return $expr instanceof ConstFetch && 1 === count( $expr->name->getParts() ) && 'false' === strtolower( $expr->name->getLast() );
	}

	/**
	 * PHP_DEBUG or PHP_ZTS when the expression is that global constant.
	 *
	 * @param Node $expr Expression.
	 * @return string|null
	 */
	private static function constant_name( Node $expr ) {
		if ( ! $expr instanceof ConstFetch ) {
			return null;
		}

		$name = $expr->name;

		if ( 1 !== count( $name->getParts() ) ) {
			return null;
		}

		// A qualified or imported name resolves elsewhere; "use const" of the
		// global constant resolves to the same name.
		$resolved = $name->getAttribute( 'resolvedName' );

		if ( $resolved instanceof Node\Name && 1 !== count( $resolved->getParts() ) ) {
			return null;
		}

		$last = $name->getLast();

		return in_array( $last, array( 'PHP_DEBUG', 'PHP_ZTS' ), true ) ? $last : null;
	}
}
