<?php
/**
 * new DatePeriod( $iso_string ), deprecated in PHP 8.4.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: call.dateperiod_iso_constructor.
 *
 * DatePeriod has two constructors. The one that takes a start, an interval
 * and an end or a count needs at least three arguments; with one or two
 * arguments it can only be the ISO 8601 string form, which is the deprecated
 * one. DatePeriod::createFromISO8601String() replaces it, but exists from
 * PHP 8.3 only, so no automatic fix is offered.
 */
final class Php84DepDatePeriodIso implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Expr\New_::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Expr\New_ $node    Object creation.
	 * @param Context        $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( ! $node->class instanceof Node\Name ) {
			return;
		}

		$resolved = $node->class->getAttribute( 'resolvedName' );
		$class    = $resolved instanceof Node\Name ? $resolved : $node->class;

		if ( 1 !== count( $class->getParts() ) || 'dateperiod' !== strtolower( $class->getLast() ) ) {
			return;
		}

		$count = count( $node->args );

		if ( $count < 1 || $count > 2 ) {
			return;
		}

		foreach ( $node->args as $arg ) {
			if ( ! $arg instanceof Node\Arg || $arg->unpack ) {
				return;
			}
		}

		$finding          = $context->report( 'call.dateperiod_iso_constructor', $node, array( 'deprecated' => '8.4' ) );
		$finding->subject = 'new DatePeriod( $isostr )';
		$finding->instead = 'DatePeriod::createFromISO8601String() (PHP 8.3 and later)';
		$finding->guarded = $context->function_is_guarded( 'dateperiod::__construct' );
	}
}
