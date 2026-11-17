<?php
/**
 * A global function called __autoload().
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: function.autoload_declared. Deprecated in 7.2 (the notice is raised
 * when the file is compiled), a compile error from 8.0, whether or not the
 * function is ever used. A function of that name in a namespace, and a method
 * of that name, are ordinary and are not reported.
 */
final class Php80BicCoreAutoloadFunction implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Stmt\Function_::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Stmt\Function_ $node    Function declaration.
	 * @param Context             $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( '__autoload' !== $node->name->toLowerString() ) {
			return;
		}

		if ( $node->namespacedName instanceof Node\Name && count( $node->namespacedName->getParts() ) > 1 ) {
			return;
		}

		$finding          = $context->report(
			'function.autoload_declared',
			$node->name,
			array(
				'deprecated' => '7.2',
				'breaks'     => '8.0',
			)
		);
		$finding->subject = '__autoload()';
		$finding->instead = 'spl_autoload_register()';
	}
}
