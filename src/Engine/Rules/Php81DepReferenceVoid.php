<?php
/**
 * Functions declared to return by reference and to return nothing.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: signature.reference_return_void. Deprecated in 8.1.
 *
 * function &name(): void. The notice is raised when the file is compiled,
 * also for abstract and interface methods, so no check around the
 * declaration keeps it away.
 */
final class Php81DepReferenceVoid implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array(
			Node\Stmt\Function_::class,
			Node\Stmt\ClassMethod::class,
			Node\Expr\Closure::class,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\FunctionLike $node    Function, method or closure.
	 * @param Context           $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		$type = $node->getReturnType();

		if ( ! $node->returnsByRef() || ! $type instanceof Node\Identifier || 'void' !== strtolower( $type->toString() ) ) {
			return;
		}

		$finding = $context->report( 'signature.reference_return_void', $type, array( 'deprecated' => '8.1' ) );

		if ( isset( $node->name ) && $node->name instanceof Node\Identifier ) {
			$finding->subject = $node->name->toString();
		}
	}
}
