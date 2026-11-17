<?php
/**
 * class_alias() with "array" or "callable" as the alias name.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id (tranche 8.5-bic): call.class_alias_reserved_name. Breaks in 8.5.
 *
 *     class_alias( 'My_List', 'array' );
 *
 * PHP 7.4 to 8.4 accepted "array" and "callable" as alias names; 8.5 stops
 * the script with 'Cannot use "array" as a class alias as it is reserved'
 * (fixtures call.class_alias_reserved_name~8.5-bic-*). The runtimes showed the
 * check ignores case and a leading backslash. Other reserved words such as
 * "iterable" were refused on every version, so they are no part of this change.
 *
 * Only a string literal alias is reported. The error is raised when the call
 * runs, so a PHP version check around it counts as a guard.
 */
final class Php85BicClassAlias implements Rule {

	const VERSION_GUARD = '8.5-bic: version check only';

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
		if ( 'class_alias' !== InternalFunctions::global_name( $node->name ) || isset( $context->declared_functions['class_alias'] ) ) {
			return;
		}

		$alias    = null;
		$position = 0;

		foreach ( $node->args as $arg ) {
			if ( ! $arg instanceof Node\Arg || $arg->unpack ) {
				return;
			}

			if ( null !== $arg->name ) {
				if ( 'alias' === strtolower( $arg->name->toString() ) ) {
					$alias = $arg->value;
				}
				continue;
			}

			if ( 1 === $position ) {
				$alias = $arg->value;
			}

			++$position;
		}

		if ( ! $alias instanceof Node\Scalar\String_ ) {
			return;
		}

		$name = strtolower( ltrim( $alias->value, '\\' ) );

		if ( 'array' !== $name && 'callable' !== $name ) {
			return;
		}

		$finding          = $context->report( 'call.class_alias_reserved_name', $node, array( 'breaks' => '8.5' ) );
		$finding->subject = "class_alias( ..., '" . $name . "' )";
		$finding->instead = 'an alias name that is not a reserved type name';
		$finding->guarded = $context->function_is_guarded( self::VERSION_GUARD );
	}
}
