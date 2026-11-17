<?php
/**
 * null written out as an array offset or array key, deprecated in PHP 8.5.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: syntax.null_array_offset (tranche 8.5-dep).
 *
 * Reported for $array[null] when read, written, or tested with isset(),
 * empty() or ??, and for null => in array( ... ), [ ... ] and list( ... ).
 * PHP used the empty string as the key and still does; the fix is ''.
 *
 * Not reported:
 * - unset( $array[null] ): the runtimes raised nothing for it.
 * - $this[null]: an object that implements ArrayAccess receives the null
 *   in offsetGet() and PHP raises nothing. Other receivers are not known
 *   from the syntax tree, but [null] on an ArrayAccess object is rare.
 * - a null that comes from a variable or a constant: known at run time only
 *   (runtime.null_array_offset_variable shows it).
 */
final class Php85DepNullOffset implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Expr\ArrayDimFetch::class, Node\ArrayItem::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Expr\ArrayDimFetch|Node\ArrayItem $node    Offset access or array item.
	 * @param Context                                $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( $node instanceof Node\ArrayItem ) {
			if ( null === $node->key || ! Php85DepFunctionArguments::is_literal( $node->key, 'null' ) ) {
				return;
			}

			$finding          = $context->report( 'syntax.null_array_offset', $node->key, array( 'deprecated' => '8.5' ) );
			$finding->subject = 'null => ...';
			$finding->instead = "''";
			$finding->guarded = $context->function_is_guarded( Php85DepFunctionArguments::VERSION_GUARD );

			return;
		}

		if ( null === $node->dim || ! Php85DepFunctionArguments::is_literal( $node->dim, 'null' ) ) {
			return;
		}

		if ( $node->var instanceof Node\Expr\Variable && 'this' === $node->var->name ) {
			return;
		}

		// A string literal: "abc"[null] raised a warning on 8.5, not this notice.
		if ( $node->var instanceof Node\Scalar\String_ || $node->var instanceof Node\Scalar\InterpolatedString ) {
			return;
		}

		if ( $node->getAttribute( 'parent' ) instanceof Node\Stmt\Unset_ ) {
			return;
		}

		$finding          = $context->report( 'syntax.null_array_offset', $node, array( 'deprecated' => '8.5' ) );
		$finding->subject = '[null]';
		$finding->instead = "['']";
		$finding->guarded = $context->function_is_guarded( Php85DepFunctionArguments::VERSION_GUARD );
	}
}
