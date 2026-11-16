<?php
/**
 * Uses of internal constants that a newer PHP deprecates or no longer has.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\ConstFetch;

defined( 'ABSPATH' ) || exit;

/**
 * Rule ids: constant.removed, constant.deprecated. Versions come from the
 * generated catalog.
 */
final class InternalConstants implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( ConstFetch::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ConstFetch $node    Constant fetch.
	 * @param Context    $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		$resolved = $node->name->getAttribute( 'resolvedName' );
		$name     = $resolved instanceof Node\Name ? $resolved : $node->name;

		if ( 1 !== count( $name->getParts() ) ) {
			return;
		}

		$constant = $name->getLast();
		$entry    = $context->catalog->get_constant( $constant );

		if ( null === $entry ) {
			return;
		}

		$finding = $context->report(
			null !== $entry['removed'] ? 'constant.removed' : 'constant.deprecated',
			$node,
			array(
				'deprecated' => $entry['deprecated'],
				'breaks'     => $entry['removed'],
			)
		);

		$finding->subject  = $constant;
		$finding->evidence = $entry['evidence'];
		$finding->instead  = isset( $entry['instead'] ) ? $entry['instead'] : '';
		$finding->guarded  = $context->constant_is_guarded( $constant );
	}
}
