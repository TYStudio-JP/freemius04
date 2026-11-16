<?php
/**
 * new ReflectionMethod( "Class::method" ), deprecated in PHP 8.4.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: call.reflectionmethod_one_argument.
 *
 * With exactly one argument the constructor takes "Class::method" as one
 * string. ReflectionMethod::createFromMethodName() replaces it, but exists
 * from PHP 8.3 only, so no automatic fix is offered; passing the class and the
 * method as two arguments works on every version.
 */
final class Php84DepReflectionMethodOneArg implements Rule {

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

		if ( 1 !== count( $class->getParts() ) || 'reflectionmethod' !== strtolower( $class->getLast() ) ) {
			return;
		}

		if ( 1 !== count( $node->args ) || ! $node->args[0] instanceof Node\Arg || $node->args[0]->unpack ) {
			return;
		}

		$finding          = $context->report( 'call.reflectionmethod_one_argument', $node, array( 'deprecated' => '8.4' ) );
		$finding->subject = 'new ReflectionMethod( "Class::method" )';
		$finding->instead = 'new ReflectionMethod( $class, $method )';
		$finding->guarded = $context->function_is_guarded( 'reflectionmethod::__construct' );
	}
}
