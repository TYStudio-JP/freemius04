<?php
/**
 * Shows the changed places of a file, not the whole file.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Pro\Fix;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress's own diff table, with a heading in front of every changed place
 * that says where in the file it is. Load wp-includes/wp-diff.php before
 * this class is used.
 */
final class HunkRenderer extends \WP_Text_Diff_Renderer_Table {

	/**
	 * The line of the original file a changed place starts at.
	 *
	 * @param int $xbeg First line in the original.
	 * @param int $xlen Number of lines in the original.
	 * @param int $ybeg First line in the changed file.
	 * @param int $ylen Number of lines in the changed file.
	 * @return string
	 */
	public function _blockHeader( $xbeg, $xlen, $ybeg, $ylen ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Name given by the parent class.
		return (string) max( 1, (int) $xbeg );
	}

	/**
	 * A row that separates the changed places.
	 *
	 * @param string $header Result of _blockHeader().
	 * @return string
	 */
	public function _startBlock( $header ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Name given by the parent class.
		/* translators: %d: line number. */
		return '<tr><td colspan="2" class="upgw-hunk">' . esc_html( sprintf( __( 'From line %d', 'upgrade-warden' ), (int) $header ) ) . "</td></tr>\n";
	}
}
