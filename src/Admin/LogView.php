<?php
/**
 * The run-time log on the plugin's screen.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Admin;

use TYStudio\UpgradeWarden\Engine\Finding;
use TYStudio\UpgradeWarden\Monitor\Log;
use TYStudio\UpgradeWarden\Monitor\Monitor;
use TYStudio\UpgradeWarden\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the switch and the recorded messages.
 */
final class LogView {

	/**
	 * Outputs the view.
	 *
	 * @param array[] $components Components by id.
	 * @param string  $target     PHP version results are shown for.
	 * @param string  $component  Only this component; empty for all.
	 */
	public static function render( array $components, $target, $component ) {
		$on   = Monitor::enabled();
		$rows = Log::rows( isset( $components[ $component ] ) || 'core' === $component ? $component : '' );

		echo '<div class="upgw-bar">';
		echo '<h2>' . esc_html__( 'What PHP says while the site runs', 'upgrade-warden' ) . '</h2>';
		echo '<p>' . esc_html__( 'A scan reads code. It cannot see a problem that depends on the data a page handles, such as counting something that turns out to be empty. PHP 7 answers many of those with a warning and carries on; PHP 8 stops the page. While recording is on, Upgrade Warden notes these messages as they happen, so you can see them before you switch PHP, and see what stopped a page after you did.', 'upgrade-warden' ) . '</p>';
		echo '<p class="description">';
		/* translators: %d: number of days. */
		echo esc_html( sprintf( _n( 'Recorded: deprecation notices and fatal errors of PHP itself, and the warnings known to become errors on a newer PHP. Kept: the message, the file and the line, for %d day after it was last seen. Not recorded: visitors, addresses, form data or page content. Nothing leaves your site.', 'Recorded: deprecation notices and fatal errors of PHP itself, and the warnings known to become errors on a newer PHP. Kept: the message, the file and the line, for %d days after it was last seen. Not recorded: visitors, addresses, form data or page content. Nothing leaves your site.', Log::days(), 'upgrade-warden' ), Log::days() ) );
		echo '</p><p>';

		if ( $on ) {
			echo '<span class="upgw-verdict upgw-clean">' . esc_html__( 'Recording is on', 'upgrade-warden' ) . '</span> ';
			self::button( 'off', __( 'Stop recording', 'upgrade-warden' ), false );
		} else {
			echo '<span class="upgw-verdict">' . esc_html__( 'Recording is off', 'upgrade-warden' ) . '</span> ';
			self::button( 'on', __( 'Start recording', 'upgrade-warden' ), true );
		}

		if ( $rows ) {
			echo ' ';
			self::button( 'clear', __( 'Empty the log', 'upgrade-warden' ), false );
		}

		echo '</p></div>';

		do_action( 'upgw_log_before', $components, $target );

		if ( ! $rows ) {
			echo '<p>' . esc_html( $on ? __( 'Nothing recorded yet. Use the site as usual; messages appear here as PHP raises them.', 'upgrade-warden' ) : __( 'Nothing recorded.', 'upgrade-warden' ) ) . '</p>';

			return;
		}

		echo '<table class="widefat upgw-table upgw-findings"><thead><tr>';

		foreach ( array( __( 'Last seen', 'upgrade-warden' ), __( 'Where', 'upgrade-warden' ), __( 'What PHP said', 'upgrade-warden' ), sprintf( /* translators: %s: PHP version. */ __( 'What it means on PHP %s', 'upgrade-warden' ), $target ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		foreach ( $rows as $row ) {
			$meaning = self::meaning( $row, $target );

			printf( '<tr class="upgw-row-%s">', esc_attr( $meaning['level'] ) );
			echo '<td>' . esc_html( wp_date( $format, strtotime( $row->last_seen . ' UTC' ) ) );

			if ( $row->first_seen !== $row->last_seen ) {
				/* translators: %s: date and time. */
				echo '<br><span class="upgw-meta upgw-first">' . esc_html( sprintf( __( 'first: %s', 'upgrade-warden' ), wp_date( $format, strtotime( $row->first_seen . ' UTC' ) ) ) ) . '</span>';
			}

			echo '</td><td>';

			if ( isset( $components[ $row->component ] ) ) {
				$url = add_query_arg(
					array(
						'page'      => Plugin::SLUG,
						'view'      => 'log',
						'component' => $row->component,
					),
					admin_url( 'tools.php' )
				);

				printf( '<a href="%s"><strong>%s</strong></a><br>', esc_url( $url ), esc_html( $components[ $row->component ]['name'] ) );
			} elseif ( 'core' === $row->component ) {
				echo '<strong>' . esc_html__( 'WordPress itself', 'upgrade-warden' ) . '</strong><br>';
			}

			printf( '<code>%s:%d</code></td>', esc_html( $row->file ), (int) $row->line );
			printf( '<td><span class="upgw-meta upgw-type">%s</span><pre class="upgw-code">%s</pre></td>', esc_html( $row->type ), esc_html( $row->message ) );
			printf( '<td><span class="upgw-verdict upgw-%s">%s</span><br>%s', esc_attr( $meaning['level'] ), esc_html( $meaning['label'] ), esc_html( $meaning['text'] ) );

			if ( '' !== $meaning['note'] ) {
				echo '<br><em>' . esc_html( $meaning['note'] ) . '</em>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * What a recorded message means for a target version.
	 *
	 * @param object $row    Row of the log.
	 * @param string $target PHP version.
	 * @return array{level:string,label:string,text:string,note:string} level
	 *         is a Finding::LEVEL_ constant, "likely", "unknown" or "clean".
	 */
	public static function meaning( $row, $target ) {
		$fatal = 0 !== strpos( $row->type, 'E_DEPRECATED' ) && 0 !== strpos( $row->type, 'E_WARNING' ) && 0 !== strpos( $row->type, 'E_NOTICE' );
		$note  = '';

		if ( $fatal ) {
			/* translators: %s: PHP version. */
			$text = sprintf( __( 'This stopped the page on PHP %s.', 'upgrade-warden' ), $row->php );

			if ( '' !== $row->rule && null !== $row->breaks ) {
				/* translators: %s: PHP version. */
				$text .= ' ' . sprintf( __( 'PHP made it an error in version %s; older versions let it pass.', 'upgrade-warden' ), $row->breaks );
				$note  = __( 'Checked on real PHP versions.', 'upgrade-warden' );
			} else {
				$note = __( 'Not one of the PHP changes on record here; it may have another cause.', 'upgrade-warden' );
			}

			return array(
				'level' => Finding::LEVEL_ERROR,
				'label' => __( 'Fatal error', 'upgrade-warden' ),
				'text'  => $text,
				'note'  => $note,
			);
		}

		if ( 'likely' === $row->evidence ) {
			return array(
				'level' => 'likely',
				'label' => __( 'Likely to break', 'upgrade-warden' ),
				/* translators: %s: PHP version. */
				'text'  => sprintf( __( 'PHP %s turns warnings of this kind into errors that stop the page, for almost every function.', 'upgrade-warden' ), $row->breaks ),
				'note'  => __( 'This particular function was not run on real PHP versions for this plugin, so this is not certain.', 'upgrade-warden' ),
			);
		}

		if ( '' === $row->rule ) {
			return array(
				'level' => 'unknown',
				'label' => __( 'Deprecation notice', 'upgrade-warden' ),
				/* translators: %s: PHP version. */
				'text'  => sprintf( __( 'PHP %s marks this as deprecated. It still works; a later PHP version will stop accepting it.', 'upgrade-warden' ), $row->php ),
				'note'  => __( 'Not among the messages on record here, so the versions are not known.', 'upgrade-warden' ),
			);
		}

		$finding             = new Finding();
		$finding->rule       = $row->rule;
		$finding->deprecated = $row->deprecated;
		$finding->changed    = $row->changed;
		$finding->breaks     = $row->breaks;
		$level               = $finding->level_for( $target );
		$facts               = array();

		foreach ( array( Finding::LEVEL_ERROR => $row->breaks, Finding::LEVEL_CHANGED => $row->changed, Finding::LEVEL_DEPRECATED => $row->deprecated ) as $kind => $since ) {
			// What already holds on an older level is said by the worse one.
			if ( null !== $since && ( Finding::LEVEL_DEPRECATED !== $kind || null === $row->breaks || $row->breaks !== $since ) ) {
				$facts[] = Messages::consequence( $kind, $since );
			}
		}

		return array(
			'level' => null === $level ? 'clean' : $level,
			/* translators: %s: PHP version. */
			'label' => null === $level ? sprintf( __( 'No change on PHP %s', 'upgrade-warden' ), $target ) : Messages::label( $level ),
			'text'  => implode( ' ', $facts ),
			'note'  => __( 'Checked on real PHP versions.', 'upgrade-warden' ),
		);
	}

	/**
	 * Outputs a one-button form.
	 *
	 * @param string $action  "on", "off" or "clear".
	 * @param string $label   Button text.
	 * @param bool   $primary Whether the button is the main action.
	 */
	private static function button( $action, $label, $primary ) {
		printf( '<form method="post" action="%s" style="display:inline">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'upgw_monitor' );
		printf( '<input type="hidden" name="action" value="upgw_monitor"><input type="hidden" name="do" value="%s">', esc_attr( $action ) );
		printf( '<button type="submit" class="button%s">%s</button></form>', $primary ? ' button-primary' : '', esc_html( $label ) );
	}
}
