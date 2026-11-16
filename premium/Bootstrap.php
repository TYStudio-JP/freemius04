<?php
/**
 * Pro features.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Pro;

use TYStudio\UpgradeWarden\Plugin;
use TYStudio\UpgradeWarden\Pro\Fix\Service;
use TYStudio\UpgradeWarden\Scan\Components;

defined( 'ABSPATH' ) || exit;

/**
 * Adds fixing to the screen of a plugin or theme: preview, apply, undo.
 */
final class Bootstrap {

	/**
	 * Registers the hooks.
	 */
	public static function boot() {
		add_action( 'upgw_detail_before', array( __CLASS__, 'panel' ), 10, 3 );
		add_action( 'admin_post_upgw_fix', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Handles "apply" and "undo", then returns to the screen.
	 */
	public static function handle() {
		check_admin_referer( 'upgw_fix' );

		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'upgrade-warden' ), 403 );
		}

		$id         = isset( $_POST['component'] ) ? sanitize_text_field( wp_unslash( $_POST['component'] ) ) : '';
		$do         = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		$components = Components::all();
		$notice     = array();

		if ( isset( $components[ $id ] ) ) {
			if ( 'apply' === $do ) {
				$notice = array( 'apply', Service::apply( $components[ $id ] ) );
			} elseif ( 'undo' === $do ) {
				$backup = isset( $_POST['backup'] ) ? sanitize_text_field( wp_unslash( $_POST['backup'] ) ) : '';
				$notice = array( 'undo', Service::undo( $components[ $id ], $backup ) );
			}
		}

		set_transient( 'upgw_fix_notice_' . get_current_user_id(), $notice, 60 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => Plugin::SLUG,
					'component' => $id,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Outputs the fix panel above the findings.
	 *
	 * @param array  $component Component.
	 * @param string $target    PHP version.
	 * @param array  $report    Report::component() result.
	 */
	public static function panel( array $component, $target, array $report ) {
		$notice = get_transient( 'upgw_fix_notice_' . get_current_user_id() );

		if ( is_array( $notice ) && $notice ) {
			delete_transient( 'upgw_fix_notice_' . get_current_user_id() );
			self::notice( $notice[0], $notice[1] );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only chooses what to display.
		$previewing = isset( $_GET['preview'] );
		$changes    = $previewing ? Service::preview( $component ) : array();
		$fixable    = 0;

		foreach ( $report['findings'] as $finding ) {
			$fixable += $finding['fixable'] ? 1 : 0;
		}

		echo '<div class="upgw-bar">';
		echo '<h3>' . esc_html__( 'Automatic fixes', 'upgrade-warden' ) . '</h3>';

		if ( ! $previewing ) {
			/* translators: %d: number of findings. */
			echo '<p>' . esc_html( sprintf( _n( '%d finding listed below can be fixed automatically.', '%d findings listed below can be fixed automatically.', $fixable, 'upgrade-warden' ), $fixable ) ) . '</p>';

			$url = add_query_arg(
				array(
					'page'      => Plugin::SLUG,
					'component' => $component['id'],
					'preview'   => 1,
				),
				admin_url( 'tools.php' )
			);

			printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( $url ), esc_html__( 'Show what would change', 'upgrade-warden' ) );
		} elseif ( ! $changes ) {
			echo '<p>' . esc_html__( 'There is nothing to fix automatically.', 'upgrade-warden' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'These are the exact changes. Only the marked characters change; everything else in the files stays as it is. The fixed code also runs on the PHP version this site uses now, so you can fix first and switch PHP afterwards. The originals are kept, and the change can be undone below.', 'upgrade-warden' ) . '</p>';

			foreach ( $changes as $relative => $change ) {
				echo '<h4><code>' . esc_html( '' === $relative ? basename( $component['path'] ) : $relative ) . '</code></h4>';
				echo wp_text_diff( $change['before'], $change['after'], array( 'show_split_view' => true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_text_diff() escapes.
			}

			self::form( $component['id'], 'apply', '', __( 'Apply these fixes', 'upgrade-warden' ), true );
		}

		foreach ( Service::backups( $component['id'] ) as $id => $manifest ) {
			echo '<p>';
			/* translators: 1: date and time, 2: number of files. */
			echo esc_html( sprintf( __( 'Fixed on %1$s: %2$d files.', 'upgrade-warden' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $manifest['created'] ), count( $manifest['files'] ) ) ) . ' ';
			self::form( $component['id'], 'undo', $id, __( 'Undo', 'upgrade-warden' ), false );
			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * Outputs a one-button form.
	 *
	 * @param string $component Component id.
	 * @param string $action    "apply" or "undo".
	 * @param string $backup    Backup id for undo.
	 * @param string $label     Button text.
	 * @param bool   $primary   Whether the button is the main action.
	 */
	private static function form( $component, $action, $backup, $label, $primary ) {
		printf( '<form method="post" action="%s" style="display:inline">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'upgw_fix' );
		printf( '<input type="hidden" name="action" value="upgw_fix"><input type="hidden" name="component" value="%s"><input type="hidden" name="do" value="%s"><input type="hidden" name="backup" value="%s">', esc_attr( $component ), esc_attr( $action ), esc_attr( $backup ) );
		printf( '<button type="submit" class="button%s">%s</button></form>', $primary ? ' button-primary' : '', esc_html( $label ) );
	}

	/**
	 * Says what an action did.
	 *
	 * @param string $action  "apply" or "undo".
	 * @param array  $outcome Result of the action.
	 */
	private static function notice( $action, array $outcome ) {
		if ( '' !== $outcome['error'] ) {
			printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( $outcome['error'] ) );

			return;
		}

		if ( 'apply' === $action ) {
			/* translators: 1: number of fixes, 2: number of files. */
			$text = sprintf( __( '%1$d fixes made in %2$d files. The originals are kept; use Undo to put them back.', 'upgrade-warden' ), $outcome['fixes'], $outcome['files'] );
		} else {
			/* translators: %d: number of files. */
			$text = sprintf( __( '%d files put back as they were.', 'upgrade-warden' ), $outcome['restored'] );

			if ( $outcome['kept'] ) {
				/* translators: %s: list of file paths. */
				$text .= ' ' . sprintf( __( 'Left alone because they changed after the fix: %s', 'upgrade-warden' ), implode( ', ', $outcome['kept'] ) );
			}
		}

		printf( '<div class="notice notice-success inline"><p>%s</p></div>', esc_html( $text ) );
	}
}
