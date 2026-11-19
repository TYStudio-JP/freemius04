<?php
/**
 * Pro: scans without being asked, and says when something new will break.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Pro;

use TYStudio\UpgradeWarden\Admin\LogView;
use TYStudio\UpgradeWarden\Engine\Finding;
use TYStudio\UpgradeWarden\Monitor\Log;
use TYStudio\UpgradeWarden\Plugin;
use TYStudio\UpgradeWarden\Scan\Components;
use TYStudio\UpgradeWarden\Scan\Job;
use TYStudio\UpgradeWarden\Scan\Report;

defined( 'ABSPATH' ) || exit;

/**
 * A weekly scan, a scan after every plugin or theme update, an e-mail when
 * the result got worse, an e-mail when the run-time log holds something new
 * that breaks, and a longer run-time log.
 *
 * Everything is off until it is switched on, and no e-mail is sent when
 * nothing changed.
 */
final class Automation {

	const OPTION   = 'upgw_auto';
	const STATE    = 'upgw_auto_state';
	const WEEKLY   = 'upgw_auto_weekly';
	const CONTINUE = 'upgw_auto_continue';
	const UPDATED  = 'upgw_auto_after_update';
	const DAILY    = 'upgw_auto_daily';

	/**
	 * Days the run-time log keeps a message in Pro.
	 */
	const LOG_DAYS = 90;

	/**
	 * Most messages the run-time log keeps in Pro.
	 */
	const LOG_ROWS = 5000;

	/**
	 * Most new messages one daily e-mail looks at.
	 */
	const DIGEST_ROWS = 200;

	/**
	 * Registers the hooks.
	 */
	public static function boot() {
		add_filter( 'upgw_monitor_days', array( __CLASS__, 'log_days' ) );
		add_filter( 'upgw_monitor_rows', array( __CLASS__, 'log_rows' ) );
		add_action( 'upgw_overview_after', array( __CLASS__, 'panel' ), 10, 2 );
		add_action( 'admin_post_upgw_auto', array( __CLASS__, 'save' ) );
		add_action( 'admin_init', array( __CLASS__, 'schedule' ) );
		add_action( self::WEEKLY, array( __CLASS__, 'start_scan' ) );
		add_action( self::UPDATED, array( __CLASS__, 'start_scan' ) );
		add_action( self::CONTINUE, array( __CLASS__, 'continue_scan' ) );
		add_action( self::DAILY, array( __CLASS__, 'daily' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_update' ), 20, 2 );
	}

	/**
	 * Days the run-time log keeps a message.
	 *
	 * @return int
	 */
	public static function log_days() {
		return self::LOG_DAYS;
	}

	/**
	 * Most messages the run-time log keeps.
	 *
	 * @return int
	 */
	public static function log_rows() {
		return self::LOG_ROWS;
	}

	/**
	 * The settings.
	 *
	 * @return array{weekly:bool,after_update:bool,notify_log:bool,email:string}
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array(
			'weekly'       => ! empty( $saved['weekly'] ),
			'after_update' => ! empty( $saved['after_update'] ),
			'notify_log'   => ! empty( $saved['notify_log'] ),
			'email'        => isset( $saved['email'] ) && is_email( $saved['email'] ) ? $saved['email'] : '',
		);
	}

	/**
	 * Where e-mails go.
	 *
	 * @return string
	 */
	public static function recipient() {
		$settings = self::settings();

		return '' !== $settings['email'] ? $settings['email'] : (string) get_option( 'admin_email' );
	}

	/**
	 * Makes the scheduled events match the settings.
	 */
	public static function schedule() {
		$settings = self::settings();
		$wanted   = array(
			self::WEEKLY => $settings['weekly'] ? 'weekly' : '',
			self::DAILY  => $settings['notify_log'] ? 'daily' : '',
		);

		foreach ( $wanted as $hook => $recurrence ) {
			$next = wp_next_scheduled( $hook );

			if ( '' === $recurrence && $next ) {
				wp_clear_scheduled_hook( $hook );
			} elseif ( '' !== $recurrence && ! $next ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, $recurrence, $hook );
			}
		}
	}

	/**
	 * Saves the settings form.
	 */
	public static function save() {
		check_admin_referer( 'upgw_auto' );

		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'upgrade-warden' ), 403 );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		update_option(
			self::OPTION,
			array(
				'weekly'       => isset( $_POST['weekly'] ),
				'after_update' => isset( $_POST['after_update'] ),
				'notify_log'   => isset( $_POST['notify_log'] ),
				'email'        => is_email( $email ) ? $email : '',
			),
			false
		);

		self::schedule();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => Plugin::SLUG,
					'saved' => 1,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Starts a scan from the schedule.
	 */
	public static function start_scan() {
		Job::start();
		self::continue_scan();
	}

	/**
	 * Works on the scan for a while and comes back until it is done.
	 */
	public static function continue_scan() {
		$progress = Job::step( 12.0 );

		if ( 'running' === $progress['status'] ) {
			if ( ! wp_next_scheduled( self::CONTINUE ) ) {
				wp_schedule_single_event( time() + 30, self::CONTINUE );
			}

			return;
		}

		if ( 'done' === $progress['status'] ) {
			self::report_changes();
		}
	}

	/**
	 * Scans again shortly after a plugin or theme was installed or updated.
	 *
	 * @param object $upgrader Upgrader.
	 * @param array  $extra    What was done.
	 */
	public static function after_update( $upgrader, $extra ) {
		$settings = self::settings();

		if ( ! $settings['after_update'] || ! is_array( $extra ) || empty( $extra['type'] ) || ! in_array( $extra['type'], array( 'plugin', 'theme' ), true ) ) {
			return;
		}

		// A hook of its own: schedule() keeps WEEKLY in line with the weekly
		// setting and would remove this one-off scan when that is off.
		if ( ! wp_next_scheduled( self::UPDATED ) ) {
			wp_schedule_single_event( time() + 60, self::UPDATED );
		}
	}

	/**
	 * What will break or behave differently, per component, for the chosen version.
	 *
	 * @return array<string,array{name:string,error:int,changed:int}>
	 */
	public static function snapshot() {
		$target = Plugin::target();
		$found  = array();

		foreach ( Components::all() as $id => $component ) {
			$summary = Report::summary( $id, $target );

			if ( $summary[ Finding::LEVEL_ERROR ] > 0 || $summary[ Finding::LEVEL_CHANGED ] > 0 ) {
				$found[ $id ] = array(
					'name'    => $component['name'],
					'error'   => (int) $summary[ Finding::LEVEL_ERROR ],
					'changed' => (int) $summary[ Finding::LEVEL_CHANGED ],
				);
			}
		}

		return $found;
	}

	/**
	 * Compares the scan with the one before and e-mails what got worse.
	 *
	 * @return string[] The lines that were e-mailed; empty when nothing got worse.
	 */
	public static function report_changes() {
		$state  = get_option( self::STATE, array() );
		$state  = is_array( $state ) ? $state : array();
		$before = isset( $state['snapshot'] ) && is_array( $state['snapshot'] ) ? $state['snapshot'] : array();
		$now    = self::snapshot();
		$target = Plugin::target();
		$lines  = array();

		foreach ( $now as $id => $counts ) {
			$old = isset( $before[ $id ] ) ? $before[ $id ] : array(
				'error'   => 0,
				'changed' => 0,
			);

			if ( $counts['error'] > $old['error'] || $counts['changed'] > $old['changed'] ) {
				/* translators: 1: plugin or theme name, 2: number of places that will break, 3: number of places that behave differently. */
				$lines[] = sprintf( __( '%1$s: %2$d will break, %3$d will behave differently', 'upgrade-warden' ), $counts['name'], $counts['error'], $counts['changed'] );
			}
		}

		$state['snapshot'] = $now;
		$state['target']   = $target;

		update_option( self::STATE, $state, false );

		if ( $lines ) {
			/* translators: 1: site name, 2: PHP version. */
			$subject = sprintf( __( '[%1$s] New PHP %2$s problems found', 'upgrade-warden' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $target );
			/* translators: %s: PHP version. */
			$body  = sprintf( __( 'Upgrade Warden scanned the plugins and themes of this site. Since the scan before, these have more code that PHP %s will not run as before:', 'upgrade-warden' ), $target ) . "\n\n";
			$body .= implode( "\n", $lines ) . "\n\n";
			$body .= __( 'File, line and what can be fixed automatically:', 'upgrade-warden' ) . "\n";
			$body .= add_query_arg( array( 'page' => Plugin::SLUG ), admin_url( 'tools.php' ) ) . "\n";

			wp_mail( self::recipient(), $subject, $body );
		}

		return $lines;
	}

	/**
	 * E-mails what the run-time log recorded since the last e-mail, when it
	 * holds something that stops a page now or on the chosen PHP version.
	 *
	 * @return string[] The lines that were e-mailed.
	 */
	public static function daily() {
		$state  = get_option( self::STATE, array() );
		$state  = is_array( $state ) ? $state : array();
		$since  = isset( $state['log_notified'] ) ? (int) $state['log_notified'] : time() - DAY_IN_SECONDS;
		$target = Plugin::target();
		$lines  = array();
		$rows   = Log::rows_since( $since, self::DIGEST_ROWS );

		foreach ( $rows as $row ) {
			$meaning = LogView::meaning( $row, $target );

			if ( Finding::LEVEL_ERROR !== $meaning['level'] && 'likely' !== $meaning['level'] ) {
				continue;
			}

			$lines[] = $row->file . ':' . (int) $row->line . "\n  " . $row->type . ': ' . $row->message . "\n  " . $meaning['label'] . '. ' . $meaning['text'];
		}

		// When the batch was full, the next e-mail goes on from its last row
		// instead of skipping what did not fit.
		$state['log_notified'] = count( $rows ) < self::DIGEST_ROWS ? time() : (int) strtotime( end( $rows )->first_seen . ' UTC' );

		update_option( self::STATE, $state, false );

		if ( $lines ) {
			/* translators: 1: site name, 2: number of messages. */
			$subject = sprintf( _n( '[%1$s] PHP recorded %2$d new problem', '[%1$s] PHP recorded %2$d new problems', count( $lines ), 'upgrade-warden' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), count( $lines ) );
			/* translators: %s: PHP version. */
			$body  = sprintf( __( 'While the site ran, PHP raised messages that stop a page now or will on PHP %s. Each is listed once, where it was first seen:', 'upgrade-warden' ), $target ) . "\n\n";
			$body .= implode( "\n\n", $lines ) . "\n\n";
			$body .= add_query_arg(
				array(
					'page' => Plugin::SLUG,
					'view' => 'log',
				),
				admin_url( 'tools.php' )
			) . "\n";

			wp_mail( self::recipient(), $subject, $body );
		}

		return $lines;
	}

	/**
	 * Outputs the settings below the overview.
	 */
	public static function panel() {
		$settings = self::settings();

		echo '<div class="upgw-bar upgw-auto">';
		echo '<h3>' . esc_html__( 'Automatic checks', 'upgrade-warden' ) . '</h3>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only chooses what to display.
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Saved.', 'upgrade-warden' ) . '</p></div>';
		}

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'upgw_auto' );
		echo '<input type="hidden" name="action" value="upgw_auto">';

		$boxes = array(
			'weekly'       => __( 'Scan every week, and e-mail me when a plugin or theme has more code that will break than the week before.', 'upgrade-warden' ),
			'after_update' => __( 'Scan again a minute after a plugin or theme is installed or updated.', 'upgrade-warden' ),
			'notify_log'   => __( 'E-mail me once a day when the run-time log recorded something new that stops a page.', 'upgrade-warden' ),
		);

		foreach ( $boxes as $name => $label ) {
			printf( '<p><label><input type="checkbox" name="%s" value="1"%s> %s</label></p>', esc_attr( $name ), checked( $settings[ $name ], true, false ), esc_html( $label ) );
		}

		printf(
			'<p><label>%s <input type="email" name="email" value="%s" class="regular-text" placeholder="%s"></label></p>',
			esc_html__( 'Send e-mails to:', 'upgrade-warden' ),
			esc_attr( $settings['email'] ),
			esc_attr( (string) get_option( 'admin_email' ) )
		);
		echo '<p class="description">' . esc_html__( 'No e-mail is sent when nothing got worse. E-mails are sent by this site, the way WordPress sends its own.', 'upgrade-warden' ) . '</p>';
		printf( '<p><button type="submit" class="button button-primary">%s</button> ', esc_html__( 'Save', 'upgrade-warden' ) );
		printf(
			'<a class="button" href="%s">%s</a></p>',
			esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'upgw_report' ), admin_url( 'admin-post.php' ) ), 'upgw_report' ) ),
			esc_html__( 'Download the report', 'upgrade-warden' )
		);
		echo '</form></div>';
	}
}
