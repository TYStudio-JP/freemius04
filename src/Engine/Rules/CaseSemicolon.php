<?php
/**
 * "case 1;" with a semicolon where a colon belongs.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Stmt\Case_;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: syntax.case_semicolon. Deprecated in 8.5.
 */
final class CaseSemicolon implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Case_::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Case_   $node    Case or default branch.
	 * @param Context $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( null !== $node->cond ) {
			$at = $context->skip_space( $node->cond->getEndFilePos() + 1 );

			// A condition in parentheses ends before its closing parenthesis.
			while ( -1 !== $at && ')' === $context->code[ $at ] ) {
				$at = $context->skip_space( $at + 1 );
			}
		} elseif ( 'default' === strtolower( substr( $context->code, $node->getStartFilePos(), 7 ) ) ) {
			$at = $context->skip_space( $node->getStartFilePos() + 7 );
		} else {
			return;
		}

		if ( -1 === $at || ';' !== $context->code[ $at ] ) {
			return;
		}

		$finding          = $context->report( 'syntax.case_semicolon', $node, array( 'deprecated' => '8.5' ) );
		$finding->instead = ':';
		$finding->fix     = array(
			'kind'  => 'replace',
			'start' => $at,
			'end'   => $at,
			'text'  => ':',
		);
	}
}
