<?php
/**
 * Calls to internal functions that a newer PHP deprecates or no longer has.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\FuncCall;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Name;

defined( 'ABSPATH' ) || exit;

/**
 * Rule ids: function.removed, function.deprecated. Versions come from the
 * generated catalog.
 */
final class InternalFunctions implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( FuncCall::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param FuncCall $node    Function call.
	 * @param Context  $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		$name = self::global_name( $node->name );

		if ( null === $name || isset( $context->declared_functions[ $name ] ) ) {
			return;
		}

		$entry = $context->catalog->get_function( $name );

		if ( null === $entry ) {
			return;
		}

		$finding = $context->report(
			null !== $entry['removed'] ? 'function.removed' : 'function.deprecated',
			$node,
			array(
				'deprecated' => $entry['deprecated'],
				'breaks'     => $entry['removed'],
			)
		);

		$finding->subject  = $name;
		$finding->evidence = $entry['evidence'];
		$finding->instead  = isset( $entry['instead'] ) ? $entry['instead'] : '';
		$finding->guarded  = $context->function_is_guarded( $name );

		if ( 'utf8_encode' === $name && 1 === count( $node->args ) && $node->args[0] instanceof Node\Arg && ! $node->args[0]->unpack && null === $node->args[0]->name ) {
			$finding->fix = array(
				'kind'       => 'utf8_encode',
				'name_start' => $node->name->getStartFilePos(),
				'name_end'   => $node->name->getEndFilePos(),
				'arg_end'    => $node->args[0]->getEndFilePos(),
				'qualified'  => $node->name->isFullyQualified(),
			);
		}
	}

	/**
	 * The lower-case name of the global function a call refers to, or null
	 * when the call is dynamic or names a function in a namespace.
	 *
	 * Relies on the name resolver having run: it marks a name that was imported
	 * or fully qualified with "resolvedName", and a bare name inside a namespace
	 * (which falls back to the global function) with "namespacedName".
	 *
	 * @param Node $name Name node or expression.
	 * @return string|null
	 */
	public static function global_name( $name ) {
		if ( ! $name instanceof Name ) {
			return null;
		}

		$resolved = $name->getAttribute( 'resolvedName' );

		if ( $resolved instanceof Name ) {
			return 1 === count( $resolved->getParts() ) ? strtolower( $resolved->getLast() ) : null;
		}

		return 1 === count( $name->getParts() ) ? strtolower( $name->getLast() ) : null;
	}
}
