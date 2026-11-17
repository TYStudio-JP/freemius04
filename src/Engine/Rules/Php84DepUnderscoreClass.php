<?php
/**
 * A class, interface, trait or enum named "_", deprecated in PHP 8.4.
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
 * too. Enums do not parse before PHP 8.1, so that case is shown by the fixture
 * runtime.underscore_enum_name~8.4-dep, which declares the enum through eval()
 * on the runtimes that have enums.
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
			Node\Stmt\Enum_::class,
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
