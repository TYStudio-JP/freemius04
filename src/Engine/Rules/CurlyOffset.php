<?php
/**
 * Array and string offsets written with curly braces: $text{0}.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\ArrayDimFetch;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: syntax.curly_offset. Deprecated in 7.4, a compile error from 8.0.
 * Only the PHP 7.4 grammar still parses this, so the rule fires on files the
 * scanner had to read with that grammar.
 */
final class CurlyOffset implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( ArrayDimFetch::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ArrayDimFetch $node    Offset access.
	 * @param Context       $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( null === $node->dim || '}' !== $context->code[ $node->getEndFilePos() ] ) {
			return;
		}

		$open = $context->skip_space( $node->var->getEndFilePos() + 1 );

		if ( -1 === $open || '{' !== $context->code[ $open ] ) {
			return;
		}

		$finding = $context->report(
			'syntax.curly_offset',
			$node,
			array(
				'deprecated' => '7.4',
				'breaks'     => '8.0',
			)
		);

		$finding->instead = '[ ]';
		$finding->fix     = array(
			'kind'  => 'curly_offset',
			'open'  => $open,
			'close' => $node->getEndFilePos(),
		);
	}
}
