<?php
/**
 * A class, interface or trait named "_", deprecated in PHP 8.4.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: syntax.underscore_class_name.
 *
 * The notice is raised when the file is compiled, in a namespace as well, so
 * no check around the declaration keeps it away. An enum named "_" raises it
 * too according to the notes, but enums need PHP 8.1 and no fixture can show
 * that on every runtime, so enums are left out.
 */
final class Php84DepUnderscoreClass implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array(
			Node\Stmt\Class_::class,
			Node\Stmt\Interface_::class,
			Node\Stmt\Trait_::class,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Stmt\ClassLike $node    Declaration.
	 * @param Context             $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( null === $node->name || '_' !== $node->name->toString() ) {
			return;
		}

		$finding          = $context->report( 'syntax.underscore_class_name', $node->name, array( 'deprecated' => '8.4' ) );
		$finding->subject = '_';
	}
}
