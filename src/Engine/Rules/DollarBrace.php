<?php
/**
 * Variables written as ${name} inside strings.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: string.dollar_brace. Deprecated in 8.2. "{$name}" means the same
 * and is what the fix writes.
 */
final class DollarBrace implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Scalar\InterpolatedString::class, Node\Expr\ShellExec::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node    $node    String with variables in it.
	 * @param Context $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		foreach ( $node->parts as $part ) {
			if ( $part instanceof Node\InterpolatedStringPart ) {
				continue;
			}

			$start = $part->getStartFilePos();

			if ( '${' !== substr( $context->code, $start, 2 ) ) {
				continue;
			}

			$finding          = $context->report( 'string.dollar_brace', $part, array( 'deprecated' => '8.2' ) );
			$finding->instead = '{$name}';

			// "${name}" and "${name[...]}" become "{$name}" and "{$name[...]}".
			// "${expression}" names a variable through an expression and needs
			// a different rewrite, which is not offered yet.
			if ( preg_match( '/^[A-Za-z_\x80-\xff]/', substr( $context->code, $start + 2, 1 ) ) ) {
				$finding->fix = array(
					'kind'  => 'replace',
					'start' => $start,
					'end'   => $start + 1,
					'text'  => '{$',
				);
			}
		}
	}
}
