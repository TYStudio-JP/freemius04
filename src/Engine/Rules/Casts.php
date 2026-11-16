<?php
/**
 * Type casts that a newer PHP removes or deprecates.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\Cast;

defined( 'ABSPATH' ) || exit;

/**
 * Rule ids:
 *
 * - syntax.real_cast          (real): deprecated in 7.4, a parse error from 8.0
 * - syntax.unset_cast         (unset): deprecated in 7.2, a compile error from 8.0
 * - syntax.noncanonical_cast  (integer), (boolean), (double), (binary): deprecated in 8.5
 */
final class Casts implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Cast\Double::class, Cast\Unset_::class, Cast\Int_::class, Cast\Bool_::class, Cast\String_::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Cast    $node    Cast expression.
	 * @param Context $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		$kind = $node->getAttribute( 'kind' );

		if ( $node instanceof Cast\Unset_ ) {
			$finding = $context->report(
				'syntax.unset_cast',
				$node,
				array(
					'deprecated' => '7.2',
					'breaks'     => '8.0',
				)
			);

			$finding->instead = 'null';

			return;
		}

		if ( $node instanceof Cast\Double && Cast\Double::KIND_REAL === $kind ) {
			$finding = $context->report(
				'syntax.real_cast',
				$node,
				array(
					'deprecated' => '7.4',
					'breaks'     => '8.0',
				)
			);

			$this->offer_fix( $finding, $node, $context, 'float' );

			return;
		}

		$canonical = null;

		if ( $node instanceof Cast\Int_ && Cast\Int_::KIND_INTEGER === $kind ) {
			$canonical = 'int';
		} elseif ( $node instanceof Cast\Bool_ && Cast\Bool_::KIND_BOOLEAN === $kind ) {
			$canonical = 'bool';
		} elseif ( $node instanceof Cast\Double && Cast\Double::KIND_DOUBLE === $kind ) {
			$canonical = 'float';
		} elseif ( $node instanceof Cast\String_ && Cast\String_::KIND_BINARY === $kind ) {
			$canonical = 'string';
		}

		if ( null === $canonical ) {
			return;
		}

		$finding = $context->report( 'syntax.noncanonical_cast', $node, array( 'deprecated' => '8.5' ) );

		$this->offer_fix( $finding, $node, $context, $canonical );
	}

	/**
	 * Notes where the cast is written and what to write instead.
	 *
	 * @param \TYStudio\UpgradeWarden\Engine\Finding $finding   Finding to complete.
	 * @param Cast                                   $node      Cast expression.
	 * @param Context                                $context   Scan state.
	 * @param string                                 $canonical Type name to use.
	 */
	private function offer_fix( $finding, Cast $node, Context $context, $canonical ) {
		$finding->instead = '(' . $canonical . ')';

		$start = $node->getStartFilePos();
		$head  = $context->text( $start, $node->expr->getStartFilePos() - 1 );

		if ( preg_match( '/^\(\s*[A-Za-z]+\s*\)/', $head, $match ) ) {
			$finding->fix = array(
				'kind'  => 'cast',
				'start' => $start,
				'end'   => $start + strlen( $match[0] ) - 1,
				'text'  => '(' . $canonical . ')',
			);
		}
	}
}
