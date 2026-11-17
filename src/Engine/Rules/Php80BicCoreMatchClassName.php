<?php
/**
 * A class, interface or trait named "match", a keyword from PHP 8.0.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: syntax.match_class_name. A parse error from 8.0, in a namespace as
 * well. Functions called match are syntax.reserved_name (ReservedNames);
 * methods and constants called match are still allowed and are not reported.
 *
 * Only the declaration is reported: a reference such as new Match() to a
 * class declared elsewhere fails too, but the scanner cannot tell it from a
 * reference that is about to be renamed together with the class.
 */
final class Php80BicCoreMatchClassName implements Rule {

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
		if ( null === $node->name || 'match' !== $node->name->toLowerString() ) {
			return;
		}

		$finding          = $context->report( 'syntax.match_class_name', $node->name, array( 'breaks' => '8.0' ) );
		$finding->subject = $node->name->toString();
	}
}
