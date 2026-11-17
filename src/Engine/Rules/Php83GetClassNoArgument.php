<?php
/**
 * get_class() and get_parent_class() without an argument, deprecated in PHP 8.3.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: call.get_class_without_argument.
 *
 * Without an argument both functions look at the class the code is written
 * in. Only calls somewhere inside a class, trait or enum are reported: outside
 * of one the call already fails on 8.0, whatever 8.3 says about it.
 *
 * get_class() written directly in a method of a class is fixed to self::class,
 * which names the same class and works on every version. get_parent_class()
 * and calls inside closures are reported without a fix.
 *
 * function_exists() is true on every version and guards nothing (seen on the
 * 8.3 runtime): only a PHP version check marks the call as guarded.
 */
final class Php83GetClassNoArgument implements Rule {

	/**
	 * A name no function has, so that only a version check counts as a guard.
	 */
	const VERSION_GUARD = '8.3: version check only';

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Expr\FuncCall::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Expr\FuncCall $node    Function call.
	 * @param Context            $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		$name = InternalFunctions::global_name( $node->name );

		if ( 'get_class' !== $name && 'get_parent_class' !== $name ) {
			return;
		}

		if ( isset( $context->declared_functions[ $name ] ) || array() !== $node->args ) {
			return;
		}

		$function = null;
		$inside   = false;

		for ( $parent = $node->getAttribute( 'parent' ); null !== $parent; $parent = $parent->getAttribute( 'parent' ) ) {
			if ( null === $function && $parent instanceof Node\FunctionLike ) {
				$function = $parent;
			}

			// A named function declared inside a method has no class scope:
			// the call is an Error there already on 8.0 (seen on the runtimes).
			if ( $parent instanceof Node\Stmt\Function_ ) {
				return;
			}

			if ( $parent instanceof Node\Stmt\ClassLike && ! $parent instanceof Node\Stmt\Interface_ ) {
				$inside = true;
				break;
			}
		}

		if ( ! $inside ) {
			return;
		}

		$finding          = $context->report( 'call.get_class_without_argument', $node, array( 'deprecated' => '8.3' ) );
		$finding->subject = $name . '()';
		$finding->instead = 'get_class' === $name ? 'self::class, or get_class( $this )' : 'get_parent_class( $this ), or get_parent_class( self::class )';
		$finding->guarded = $context->function_is_guarded( self::VERSION_GUARD );

		if ( 'get_class' === $name && $function instanceof Node\Stmt\ClassMethod && $function->getAttribute( 'parent' ) instanceof Node\Stmt\Class_ && null !== $function->getAttribute( 'parent' )->name ) {
			$finding->fix = array(
				'kind'  => 'replace',
				'start' => $node->getStartFilePos(),
				'end'   => $node->getEndFilePos(),
				'text'  => 'self::class',
			);
		}
	}
}
