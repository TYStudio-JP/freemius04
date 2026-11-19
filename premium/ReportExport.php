<?php
/**
 * Pro: the scan result and the run-time log as one file to hand over.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Pro;

use TYStudio\UpgradeWarden\Admin\LogView;
use TYStudio\UpgradeWarden\Admin\Messages;
use TYStudio\UpgradeWarden\Engine\Finding;
use TYStudio\UpgradeWarden\Engine\Versions;
use TYStudio\UpgradeWarden\Monitor\Log;
use TYStudio\UpgradeWarden\Plugin;
use TYStudio\UpgradeWarden\Scan\Components;
use TYStudio\UpgradeWarden\Scan\Report;

defined( 'ABSPATH' ) || exit;

/**
 * A single HTML file with no outside resources: it opens in any browser,
 * prints to PDF from there, and can be sent to a client or a developer.
 */
final class ReportExport {

	/**
	 * Registers the hook.
	 */
	public static function boot() {
		add_action( 'admin_post_upgw_report', array( __CLASS__, 'download' ) );
	}

	/**
	 * Sends the report as a download.
	 */
	public static function download() {
		check_admin_referer( 'upgw_report' );

		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'upgrade-warden' ), 403 );
		}

		$target = Plugin::target();

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="php-' . $target . '-report-' . gmdate( 'Y-m-d' ) . '.html"' );

		echo self::html( $target ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where it is built.
		exit;
	}

	/**
	 * Builds the report.
	 *
	 * @param string $target PHP version.
	 * @return string HTML document.
	 */
	public static function html( $target ) {
		$components = Components::all();
		$format     = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$last       = (int) get_option( 'upgw_last_scan', 0 );
		$summaries  = array();

		foreach ( $components as $id => $component ) {
			$summaries[ $id ] = Report::summary( $id, $target );
		}

		/* translators: %s: PHP version. */
		$title = sprintf( __( 'PHP %s compatibility report', 'upgrade-warden' ), $target );
		$out   = '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><title>' . esc_html( $title ) . '</title><style>'
			. 'body{font:14px/1.5 -apple-system,"Segoe UI",sans-serif;color:#1d2327;max-width:1000px;margin:24px auto;padding:0 16px}'
			. 'h1{font-size:24px}h2{font-size:18px;margin-top:32px;border-bottom:1px solid #c3c4c7;padding-bottom:4px}'
			. 'table{border-collapse:collapse;width:100%;margin:8px 0 16px}th,td{border:1px solid #c3c4c7;padding:6px 8px;text-align:left;vertical-align:top}th{background:#f6f7f7}'
			. 'code,pre{font:12px/1.4 Consolas,Menlo,monospace}pre{background:#f6f7f7;padding:6px 8px;margin:4px 0;white-space:pre-wrap;word-break:break-all}'
			. '.v{display:inline-block;padding:1px 8px;border-radius:3px;font-weight:600;background:#f0f0f1}.v-error{background:#fcf0f1;color:#8a2424}.v-changed,.v-unreadable,.v-likely{background:#fcf9e8;color:#6e4e00}.v-deprecated,.v-unknown{background:#f0f6fc;color:#0a4b78}.v-clean{background:#edfaef;color:#005c12}'
			. '.m{color:#646970}@media print{body{margin:0;max-width:none}h2{break-after:avoid}tr{break-inside:avoid}}'
			. '</style></head><body>';

		$out .= '<h1>' . esc_html( $title ) . '</h1><p>';
		$out .= esc_html( get_bloginfo( 'name' ) ) . ' · ' . esc_html( home_url() ) . '<br>';
		/* translators: 1: PHP version the site runs, 2: date and time. */
		$out .= esc_html( sprintf( __( 'The site runs PHP %1$s. Report made on %2$s.', 'upgrade-warden' ), PHP_VERSION, wp_date( $format ) ) );

		if ( $last > 0 ) {
			/* translators: %s: date and time. */
			$out .= ' ' . esc_html( sprintf( __( 'Last scan: %s.', 'upgrade-warden' ), wp_date( $format, $last ) ) );
		}

		$out .= '</p><p class="m">';
		$out .= esc_html__( 'How to read this: "Will break" means PHP no longer runs the code. "Will behave differently" means it runs and does something else, without a warning. "Deprecation notices" means it still works and PHP announces that it will stop. Each statement about a PHP version was checked by running code on real PHP 7.4 to 8.5; what could only be taken from the official upgrade notes is marked.', 'upgrade-warden' );
		$out .= '</p>';

		if ( ! Versions::runtime_checked( $target ) ) {
			/* translators: %s: PHP version. */
			$out .= '<p><em>' . esc_html( sprintf( __( 'What is new in PHP %s comes from the official upgrade notes. It was not run on a real PHP of that version yet, and is marked as such below.', 'upgrade-warden' ), $target ) ) . '</em></p>';
		}

		$out .= '<h2>' . esc_html__( 'Summary', 'upgrade-warden' ) . '</h2><table><thead><tr>';

		foreach ( array( __( 'Plugin or theme', 'upgrade-warden' ), sprintf( /* translators: %s: PHP version. */ __( 'On PHP %s', 'upgrade-warden' ), $target ), __( 'Will break', 'upgrade-warden' ), __( 'Behaves differently', 'upgrade-warden' ), __( 'Deprecated', 'upgrade-warden' ), __( 'Can be fixed automatically', 'upgrade-warden' ) ) as $heading ) {
			$out .= '<th>' . esc_html( $heading ) . '</th>';
		}

		$out .= '</tr></thead><tbody>';

		foreach ( $components as $id => $component ) {
			$summary = $summaries[ $id ];
			$out    .= '<tr><td><strong>' . esc_html( $component['name'] ) . '</strong> <span class="m">' . esc_html( $component['version'] ) . ( $component['active'] ? '' : ' · ' . esc_html__( 'inactive', 'upgrade-warden' ) ) . '</span></td>';
			$out    .= '<td><span class="v v-' . esc_attr( $summary['verdict'] ) . '">' . esc_html( Messages::label( $summary['verdict'] ) ) . '</span></td>';
			$out    .= sprintf( '<td>%d</td><td>%d</td><td>%d</td><td>%d</td></tr>', (int) $summary[ Finding::LEVEL_ERROR ], (int) $summary[ Finding::LEVEL_CHANGED ], (int) $summary[ Finding::LEVEL_DEPRECATED ], (int) $summary['fixable'] );
		}

		$out .= '</tbody></table>';

		foreach ( $components as $id => $component ) {
			$report = Report::component( $id, $target );
			$shown  = array();

			foreach ( $report['findings'] as $finding ) {
				if ( Report::GUARDED !== $finding['level'] ) {
					$shown[] = $finding;
				}
			}

			if ( ! $shown && ! $report['unreadable'] ) {
				continue;
			}

			$out .= '<h2>' . esc_html( $component['name'] ) . '</h2>';

			foreach ( $report['unreadable'] as $file ) {
				/* translators: 1: file path, 2: parser message. */
				$out .= '<p>' . esc_html( sprintf( __( '%1$s could not be read as PHP, so it was not checked: %2$s', 'upgrade-warden' ), $file['path'], $file['error'] ) ) . '</p>';
			}

			if ( ! $shown ) {
				continue;
			}

			$out .= '<table><thead><tr><th>' . esc_html__( 'Where', 'upgrade-warden' ) . '</th><th>' . esc_html__( 'What', 'upgrade-warden' ) . '</th><th>' . esc_html__( 'What happens', 'upgrade-warden' ) . '</th></tr></thead><tbody>';

			foreach ( $shown as $finding ) {
				$out .= '<tr><td><code>' . esc_html( ( '' === $finding['path'] ? basename( $component['path'] ) : $finding['path'] ) . ':' . $finding['line'] ) . '</code></td>';
				$out .= '<td>' . wp_kses( Messages::describe( $finding['rule'], $finding['subject'] ), array( 'code' => array() ) );

				if ( '' !== $finding['subject'] && ! Messages::names_subject( $finding['rule'] ) ) {
					$out .= ' <code>' . esc_html( $finding['subject'] ) . '</code>';
				}

				if ( '' !== $finding['instead'] ) {
					/* translators: %s: code to use instead. */
					$out .= '<br>' . wp_kses( sprintf( __( 'Use %s instead.', 'upgrade-warden' ), '<code>' . esc_html( $finding['instead'] ) . '</code>' ), array( 'code' => array() ) );
				}

				$out .= '</td><td><span class="v v-' . esc_attr( $finding['level'] ) . '">' . esc_html( Messages::label( $finding['level'] ) ) . '</span><br>' . esc_html( Messages::consequence( $finding['level'], $finding['since'] ) );

				if ( ! $finding['checked'] ) {
					$out .= '<br><em>' . esc_html__( 'From the official upgrade notes; not yet run on a real PHP of this version.', 'upgrade-warden' ) . '</em>';
				}

				if ( $finding['fixable'] ) {
					$out .= '<br>' . esc_html__( 'Can be fixed automatically.', 'upgrade-warden' );
				}

				$out .= '</td></tr>';
			}

			$out .= '</tbody></table>';
		}

		$rows = Log::rows( '', 500 );

		if ( $rows ) {
			$out .= '<h2>' . esc_html__( 'What PHP said while the site ran', 'upgrade-warden' ) . '</h2>';
			$out .= '<table><thead><tr><th>' . esc_html__( 'Last seen', 'upgrade-warden' ) . '</th><th>' . esc_html__( 'Where', 'upgrade-warden' ) . '</th><th>' . esc_html__( 'What PHP said', 'upgrade-warden' ) . '</th><th>' . esc_html( sprintf( /* translators: %s: PHP version. */ __( 'What it means on PHP %s', 'upgrade-warden' ), $target ) ) . '</th></tr></thead><tbody>';

			foreach ( $rows as $row ) {
				$meaning = LogView::meaning( $row, $target );
				$name    = isset( $components[ $row->component ] ) ? $components[ $row->component ]['name'] : ( 'core' === $row->component ? __( 'WordPress itself', 'upgrade-warden' ) : '' );

				$out .= '<tr><td>' . esc_html( wp_date( $format, strtotime( $row->last_seen . ' UTC' ) ) ) . '</td>';
				$out .= '<td>' . ( '' !== $name ? '<strong>' . esc_html( $name ) . '</strong><br>' : '' ) . '<code>' . esc_html( $row->file . ':' . (int) $row->line ) . '</code></td>';
				$out .= '<td><span class="m">' . esc_html( $row->type ) . '</span><pre>' . esc_html( $row->message ) . '</pre></td>';
				$out .= '<td><span class="v v-' . esc_attr( $meaning['level'] ) . '">' . esc_html( $meaning['label'] ) . '</span><br>' . esc_html( $meaning['text'] ) . ( '' !== $meaning['note'] ? '<br><em>' . esc_html( $meaning['note'] ) . '</em>' : '' ) . '</td></tr>';
			}

			$out .= '</tbody></table>';
		}

		return $out . '</body></html>';
	}
}
