<?php
/**
 * The plugin's screen under Tools.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Admin;

use TYStudio\UpgradeWarden\Engine\Finding;
use TYStudio\UpgradeWarden\Engine\Versions;
use TYStudio\UpgradeWarden\Monitor\Log;
use TYStudio\UpgradeWarden\Monitor\Monitor;
use TYStudio\UpgradeWarden\Plugin;
use TYStudio\UpgradeWarden\Scan\Components;
use TYStudio\UpgradeWarden\Scan\Job;
use TYStudio\UpgradeWarden\Scan\Report;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the overview, the findings of one plugin or theme, and the
 * run-time log.
 */
final class Page {

	/**
	 * Outputs the screen.
	 */
	public static function render() {
		if ( ! current_user_can( Plugin::capability() ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen parameters.
		if ( isset( $_GET['target'] ) ) {
			$wanted = sanitize_text_field( wp_unslash( $_GET['target'] ) );

			if ( in_array( $wanted, Versions::TARGETS, true ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'upgw_target' ) ) {
				update_option( 'upgw_target', $wanted, false );
			}
		}

		$component = isset( $_GET['component'] ) ? sanitize_text_field( wp_unslash( $_GET['component'] ) ) : '';
		$view      = isset( $_GET['view'] ) && 'log' === $_GET['view'] ? 'log' : 'scan';
		// phpcs:enable

		$target     = Plugin::target();
		$components = Components::all();
		$logged     = Log::counts();

		echo '<div class="wrap upgw">';
		echo '<h1>' . esc_html__( 'Upgrade Warden', 'upgrade-warden' ) . '</h1>';

		self::tabs( $view, array_sum( $logged ) );
		self::header( $target, $view );

		if ( 'log' === $view ) {
			LogView::render( $components, $target, $component );
		} elseif ( '' !== $component && isset( $components[ $component ] ) ) {
			self::detail( $components[ $component ], $target );
		} else {
			self::overview( $components, $target, $logged );

			do_action( 'upgw_overview_after', $components, $target );

			if ( ! Plugin::is_pro() ) {
				self::pro_note();
			}
		}

		echo '</div>';
	}

	/**
	 * The two views of the screen.
	 *
	 * @param string $view   "scan" or "log".
	 * @param int    $logged Number of messages in the run-time log.
	 */
	private static function tabs( $view, $logged ) {
		$base = add_query_arg( array( 'page' => Plugin::SLUG ), admin_url( 'tools.php' ) );
		$log  = __( 'Run-time log', 'upgrade-warden' );

		if ( $logged > 0 ) {
			$log .= ' (' . number_format_i18n( $logged ) . ')';
		} elseif ( Monitor::enabled() ) {
			$log .= ' · ' . __( 'recording', 'upgrade-warden' );
		}

		echo '<h2 class="nav-tab-wrapper">';
		printf( '<a class="nav-tab%s" href="%s">%s</a>', 'scan' === $view ? ' nav-tab-active' : '', esc_url( $base ), esc_html__( 'Scan', 'upgrade-warden' ) );
		printf( '<a class="nav-tab%s" href="%s">%s</a>', 'log' === $view ? ' nav-tab-active' : '', esc_url( add_query_arg( 'view', 'log', $base ) ), esc_html( $log ) );
		echo '</h2>';
	}

	/**
	 * One line about the paid edition, shown in the free one.
	 */
	private static function pro_note() {
		$url = (string) upgw_fs()->get_upgrade_url();

		echo '<p class="upgw-pro-note">';
		echo esc_html__( 'Upgrade Warden Pro fixes what can be fixed automatically, with a preview, a backup and an undo. It also scans every week and after updates, e-mails you when something new will break, keeps the run-time log for 90 days and exports a report.', 'upgrade-warden' );

		if ( '' !== $url ) {
			printf( ' <a href="%s">%s</a>', esc_url( $url ), esc_html__( 'See Upgrade Warden Pro', 'upgrade-warden' ) );
		}

		echo '</p>';
	}

	/**
	 * Current PHP, target version and the scan button.
	 *
	 * @param string $target PHP version results are shown for.
	 * @param string $view   "scan" or "log".
	 */
	private static function header( $target, $view ) {
		$progress = Job::progress();
		$last     = (int) get_option( 'upgw_last_scan', 0 );

		echo '<div class="upgw-bar">';
		echo '<p>';
		/* translators: %s: PHP version. */
		echo esc_html( sprintf( __( 'This site runs PHP %s.', 'upgrade-warden' ), PHP_VERSION ) ) . ' ';
		echo esc_html__( 'Show what happens on:', 'upgrade-warden' ) . ' ';

		foreach ( Versions::TARGETS as $version ) {
			$args = array(
				'page'   => Plugin::SLUG,
				'target' => $version,
			);

			if ( 'log' === $view ) {
				$args['view'] = 'log';
			}

			printf(
				'<a class="button%s" href="%s">PHP %s</a> ',
				$version === $target ? ' button-primary' : '',
				esc_url( wp_nonce_url( add_query_arg( $args, admin_url( 'tools.php' ) ), 'upgw_target' ) ),
				esc_html( $version )
			);
		}

		echo '</p>';

		if ( ! Versions::runtime_checked( $target ) ) {
			echo '<p class="upgw-note">';
			/* translators: %s: PHP version. */
			echo esc_html( sprintf( __( 'What is new in PHP %s comes from the official upgrade notes. It was not run on a real PHP of that version yet, and is marked as such below.', 'upgrade-warden' ), $target ) );
			echo '</p>';
		}

		if ( 'log' === $view ) {
			echo '</div>';

			return;
		}

		echo '<p>';
		printf(
			'<button type="button" class="button button-primary" id="upgw-scan" data-running="%s">%s</button> ',
			'running' === $progress['status'] ? '1' : '0',
			esc_html__( 'Scan plugins and themes', 'upgrade-warden' )
		);
		echo '<span id="upgw-progress" aria-live="polite"></span>';

		if ( $last > 0 ) {
			echo '<span class="upgw-last">';
			/* translators: %s: date and time. */
			echo esc_html( sprintf( __( 'Last scan: %s.', 'upgrade-warden' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last ) ) );
			echo '</span>';
		}

		echo '</p>';
		echo '<p class="description">' . esc_html__( 'A scan reads the PHP files of your plugins and themes. It changes nothing, and nothing leaves your site. It cannot see problems that depend on the data a page handles; those only show when the code runs, and the run-time log is there for them.', 'upgrade-warden' ) . '</p>';
		echo '</div>';
	}

	/**
	 * One row per plugin and theme.
	 *
	 * @param array[] $components Components by id.
	 * @param string  $target     PHP version.
	 * @param int[]   $logged     Messages in the run-time log by component id.
	 */
	private static function overview( array $components, $target, array $logged ) {
		$order = array(
			Finding::LEVEL_ERROR      => 0,
			Finding::LEVEL_CHANGED    => 1,
			'unreadable'              => 2,
			Finding::LEVEL_DEPRECATED => 3,
			'clean'                   => 4,
			'unscanned'               => 5,
		);
		$rows  = array();

		foreach ( $components as $component ) {
			$rows[] = array( $component, Report::summary( $component['id'], $target ) );
		}

		usort(
			$rows,
			static function ( $a, $b ) use ( $order ) {
				$by_verdict = $order[ $a[1]['verdict'] ] - $order[ $b[1]['verdict'] ];

				return 0 !== $by_verdict ? $by_verdict : strcasecmp( $a[0]['name'], $b[0]['name'] );
			}
		);

		$headings = array(
			__( 'Plugin or theme', 'upgrade-warden' ),
			/* translators: %s: PHP version. */
			sprintf( __( 'On PHP %s', 'upgrade-warden' ), $target ),
			__( 'Will break', 'upgrade-warden' ),
			__( 'Behaves differently', 'upgrade-warden' ),
			__( 'Deprecated', 'upgrade-warden' ),
			__( 'Behind a check', 'upgrade-warden' ),
			__( 'Files', 'upgrade-warden' ),
			__( 'Run-time log', 'upgrade-warden' ),
		);

		echo '<table class="widefat striped upgw-table"><thead><tr>';

		foreach ( $headings as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			list( $component, $summary ) = $row;

			$url  = add_query_arg(
				array(
					'page'      => Plugin::SLUG,
					'component' => $component['id'],
				),
				admin_url( 'tools.php' )
			);
			$type = 'theme' === $component['type'] ? __( 'Theme', 'upgrade-warden' ) : __( 'Plugin', 'upgrade-warden' );

			echo '<tr>';
			printf(
				'<td><a href="%s"><strong>%s</strong></a> <span class="upgw-meta">%s%s%s</span></td>',
				esc_url( $url ),
				esc_html( $component['name'] ),
				esc_html( $type ),
				'' !== $component['version'] ? ' ' . esc_html( $component['version'] ) : '',
				$component['active'] ? '' : ' · ' . esc_html__( 'inactive', 'upgrade-warden' )
			);
			printf( '<td><span class="upgw-verdict upgw-%s">%s</span></td>', esc_attr( $summary['verdict'] ), esc_html( Messages::label( $summary['verdict'] ) ) );
			printf( '<td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td>', (int) $summary[ Finding::LEVEL_ERROR ], (int) $summary[ Finding::LEVEL_CHANGED ], (int) $summary[ Finding::LEVEL_DEPRECATED ], (int) $summary[ Report::GUARDED ], (int) $summary['files'] );

			if ( ! empty( $logged[ $component['id'] ] ) ) {
				$log = add_query_arg(
					array(
						'page'      => Plugin::SLUG,
						'view'      => 'log',
						'component' => $component['id'],
					),
					admin_url( 'tools.php' )
				);

				printf( '<td><a href="%s">%d</a></td>', esc_url( $log ), (int) $logged[ $component['id'] ] );
			} else {
				echo '<td>0</td>';
			}

			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The findings of one plugin or theme.
	 *
	 * @param array  $component Component.
	 * @param string $target    PHP version.
	 */
	private static function detail( array $component, $target ) {
		$report = Report::component( $component['id'], $target );
		$back   = add_query_arg( array( 'page' => Plugin::SLUG ), admin_url( 'tools.php' ) );

		printf( '<p><a href="%s">&larr; %s</a></p>', esc_url( $back ), esc_html__( 'All plugins and themes', 'upgrade-warden' ) );
		/* translators: 1: plugin or theme name, 2: PHP version. */
		echo '<h2>' . esc_html( sprintf( __( '%1$s on PHP %2$s', 'upgrade-warden' ), $component['name'], $target ) ) . '</h2>';

		do_action( 'upgw_detail_before', $component, $target, $report );

		foreach ( $report['unreadable'] as $file ) {
			echo '<div class="notice notice-warning inline"><p>';
			/* translators: 1: file path, 2: parser message. */
			echo esc_html( sprintf( __( '%1$s could not be read as PHP, so it was not checked: %2$s', 'upgrade-warden' ), $file['path'], $file['error'] ) );
			echo '</p></div>';
		}

		if ( ! $report['findings'] ) {
			echo '<p>' . esc_html( 0 === $report['files'] ? __( 'Not scanned yet.', 'upgrade-warden' ) : __( 'Nothing found for this PHP version.', 'upgrade-warden' ) ) . '</p>';

			return;
		}

		$sources = array();

		echo '<table class="widefat upgw-table upgw-findings"><thead><tr>';

		foreach ( array( __( 'Where', 'upgrade-warden' ), __( 'What', 'upgrade-warden' ), __( 'What happens', 'upgrade-warden' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $report['findings'] as $finding ) {
			if ( ! isset( $sources[ $finding['path'] ] ) ) {
				$absolute                    = Components::absolute( $component['path'], $finding['path'] );
				$sources[ $finding['path'] ] = is_readable( $absolute ) ? file( $absolute, FILE_IGNORE_NEW_LINES ) : array();
			}

			$line = isset( $sources[ $finding['path'] ][ $finding['line'] - 1 ] ) ? $sources[ $finding['path'] ][ $finding['line'] - 1 ] : '';

			printf( '<tr class="upgw-row-%s">', esc_attr( $finding['level'] ) );
			printf( '<td><code>%s:%d</code></td>', esc_html( '' === $finding['path'] ? basename( $component['path'] ) : $finding['path'] ), (int) $finding['line'] );

			echo '<td>' . wp_kses( Messages::describe( $finding['rule'], $finding['subject'] ), array( 'code' => array() ) );

			if ( '' !== $finding['subject'] && ! Messages::names_subject( $finding['rule'] ) ) {
				echo ' <code class="upgw-subject">' . esc_html( $finding['subject'] ) . '</code>';
			}

			if ( '' !== trim( $line ) ) {
				echo '<pre class="upgw-code">' . esc_html( mb_strimwidth( trim( $line ), 0, 200, '…' ) ) . '</pre>';
			}

			if ( '' !== $finding['instead'] ) {
				/* translators: %s: code to use instead. */
				echo '<span class="upgw-instead">' . wp_kses( sprintf( __( 'Use %s instead.', 'upgrade-warden' ), '<code>' . esc_html( $finding['instead'] ) . '</code>' ), array( 'code' => array() ) ) . '</span>';
			}

			echo '</td><td>';
			printf( '<span class="upgw-verdict upgw-%s">%s</span><br>', esc_attr( $finding['level'] ), esc_html( Messages::label( $finding['level'] ) ) );
			echo esc_html( Messages::consequence( $finding['level'], $finding['since'] ) );

			if ( ! $finding['checked'] && Report::GUARDED !== $finding['level'] ) {
				echo '<br><em>' . esc_html__( 'From the official upgrade notes; not yet run on a real PHP of this version.', 'upgrade-warden' ) . '</em>';
			}

			if ( $finding['fixable'] && Report::GUARDED !== $finding['level'] ) {
				echo '<br><span class="upgw-fixable">' . esc_html( Plugin::is_pro() ? __( 'Can be fixed automatically.', 'upgrade-warden' ) : __( 'Can be fixed automatically with Upgrade Warden Pro.', 'upgrade-warden' ) ) . '</span>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}
}
