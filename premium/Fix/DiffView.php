<?php
/**
 * The changes of a fix, side by side.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Pro\Fix;

defined( 'ABSPATH' ) || exit;

/**
 * wp_text_diff() prints every line of a file. A plugin file can have
 * thousands, with three of them changed, so this prints the changed places
 * with a few lines around each.
 */
final class DiffView {

	/**
	 * Lines shown before and after a changed place.
	 */
	const CONTEXT = 3;

	/**
	 * The changes between two versions of a file as an HTML table.
	 *
	 * @param string $before Original contents.
	 * @param string $after  Contents after the fix.
	 * @return string HTML, escaped.
	 */
	public static function render( $before, $after ) {
		if ( ! class_exists( 'WP_Text_Diff_Renderer_Table', false ) ) {
			require_once ABSPATH . WPINC . '/wp-diff.php';
		}

		$lines = static function ( $text ) {
			return explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", (string) $text ) );
		};

		$renderer = new HunkRenderer(
			array(
				'show_split_view'        => true,
				'leading_context_lines'  => self::CONTEXT,
				'trailing_context_lines' => self::CONTEXT,
			)
		);
		$rows     = $renderer->render( new \Text_Diff( 'auto', array( $lines( $before ), $lines( $after ) ) ) );

		if ( '' === trim( (string) $rows ) ) {
			return '';
		}

		// A row of the split view has two cells. The widths are given here: the
		// first row of the table is a heading across both, which says nothing
		// about how wide they are.
		return "<table class='diff upgw-diff'>\n<col class='content diffsplit left' style='width:50%' /><col class='content diffsplit right' style='width:50%' />\n<tbody>\n" . $rows . "\n</tbody>\n</table>";
	}
}
