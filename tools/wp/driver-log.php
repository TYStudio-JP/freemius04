<?php
/**
 * Second half of the check inside a real WordPress: the run-time log after a
 * page load on which another plugin did what PHP 8 no longer accepts, then
 * the Pro automation, the report, and deleting the plugin.
 * Run by tools/wp-check.mjs; prints one JSON document after a marker.
 */

$upgw_notices = array();

set_error_handler(
	static function ( $type, $message, $file, $line ) use ( &$upgw_notices ) {
		if ( false !== strpos( $file, '/plugins/upgrade-warden/' ) ) {
			$upgw_notices[] = basename( $file ) . ':' . $line . ' ' . $message;
		}

		return true;
	}
);

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

use TYStudio\UpgradeWarden\Admin\LogView;
use TYStudio\UpgradeWarden\Admin\Page;
use TYStudio\UpgradeWarden\Monitor\Capture;
use TYStudio\UpgradeWarden\Monitor\Log;
use TYStudio\UpgradeWarden\Monitor\Monitor;
use TYStudio\UpgradeWarden\Plugin;
use TYStudio\UpgradeWarden\Scan\Job;
use TYStudio\UpgradeWarden\Store\Tables;

$out    = array( 'php' => PHP_VERSION, 'checks' => array() );
$check  = static function ( $name, $ok, $detail = '' ) use ( &$out ) {
	$out['checks'][] = array( 'name' => $name, 'ok' => (bool) $ok, 'detail' => is_scalar( $detail ) ? (string) $detail : wp_json_encode( $detail ) );
};
$render = static function ( array $get ) {
	$_GET = $get;
	ob_start();
	Page::render();

	return ob_get_clean();
};

wp_set_current_user( 1 );

$php8 = PHP_VERSION_ID >= 80000;
$rows = Log::rows();
$by   = array();

foreach ( $rows as $row ) {
	$by[ $row->type ] = $row;
}

$out['log'] = array_map(
	static function ( $row ) {
		return $row->type . ': ' . $row->message . ' @ ' . $row->file . ':' . $row->line . ' [' . $row->component . '] ' . $row->rule . ' ' . $row->breaks . ' ' . $row->evidence;
	},
	$rows
);

if ( $php8 ) {
	$check( 'log on PHP 8: the TypeError that stopped the page is there', isset( $by['TypeError'] ) && 0 === strpos( $by['TypeError']->message, 'count(): Argument #1' ), $out['log'] );

	if ( isset( $by['TypeError'] ) ) {
		$check( 'log on PHP 8: explained as an error since 8.0, verified', '8.0' === $by['TypeError']->breaks && 'verified' === $by['TypeError']->evidence && 'runtime.count_non_countable' === $by['TypeError']->rule, $by['TypeError'] );
	}

	$check( 'log on PHP 8: nothing but PHP\'s own messages', count( $rows ) <= 2, $out['log'] );
} else {
	$check( 'log on PHP 7: the warning about count() is there', isset( $by['E_WARNING'] ) && 0 === strpos( $by['E_WARNING']->message, 'count(): Parameter must be an array' ), $out['log'] );

	if ( isset( $by['E_WARNING'] ) ) {
		$check( 'log on PHP 7: explained as an error from 8.0, verified', '8.0' === $by['E_WARNING']->breaks && 'verified' === $by['E_WARNING']->evidence, $by['E_WARNING'] );

		$meaning = LogView::meaning( $by['E_WARNING'], '8.5' );
		$check( 'log on PHP 7: on 8.5 it reads "will break"', 'error' === $meaning['level'] && false !== strpos( $meaning['text'], '8.0' ), $meaning );
		$meaning = LogView::meaning( $by['E_WARNING'], '8.0' );
		$check( 'log on PHP 7: the same on 8.0', 'error' === $meaning['level'], $meaning );
	}

	$check( 'log on PHP 7: exactly one message', 1 === count( $rows ), $out['log'] );
}

if ( PHP_VERSION_ID >= 80100 ) {
	$check( 'log on PHP 8.1+: the silenced deprecation of strlen( null ) is there', isset( $by['E_DEPRECATED'] ) && false !== strpos( $by['E_DEPRECATED']->message, 'Passing null to parameter' ) && '8.1' === $by['E_DEPRECATED']->deprecated, $out['log'] );
}

if ( $rows ) {
	$first = $rows[0];
	$check( 'log: the message belongs to the sample plugin', 'plugin:runtime-sample' === $first->component, $first->component );
	$check( 'log: the path is relative to the site', 'wp-content/plugins/runtime-sample/runtime-sample.php' === $first->file, $first->file );
	$check( 'log: the PHP version is noted', PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION === $first->php, $first->php );
}

// The same page again: nothing is written twice within the hour.
$before = count( $rows );
Log::record(
	array(
		array(
			'type'    => $rows ? $rows[0]->type : 'E_DEPRECATED',
			'message' => $rows ? $rows[0]->message : 'x',
			'file'    => WP_PLUGIN_DIR . '/runtime-sample/runtime-sample.php',
			'line'    => $rows ? (int) $rows[0]->line : 1,
			'match'   => null,
		),
	)
);
$check( 'log: a message seen again is not written again', count( Log::rows() ) === $before, count( Log::rows() ) );

$view = $render( array( 'page' => 'upgrade-warden', 'view' => 'log' ) );
$check( 'the log view lists the message and the plugin', false !== strpos( $view, 'count():' ) && false !== strpos( $view, 'Runtime Sample' ) && false !== strpos( $view, 'value="off"' ), strlen( $view ) );
$check( 'the log view escapes', false === strpos( $view, '<?php' ) );
$overview = $render( array( 'page' => 'upgrade-warden' ) );
$check( 'the overview counts the log entries of the sample plugin', 1 === preg_match( '/view=log[^"]*component=plugin(?:%3A|:)runtime-sample[^>]*>(\d+)</', $overview, $found ) && (int) $found[1] === count( $rows ), isset( $found[1] ) ? $found[1] : 'no link' );

$counts = Log::counts();
$check( 'counts per component', isset( $counts['plugin:runtime-sample'] ) && count( $rows ) === $counts['plugin:runtime-sample'], $counts );

// Pro: e-mails, report.
$mails = array();

add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) use ( &$mails ) {
		$mails[] = $atts;

		return true;
	},
	10,
	2
);

if ( Plugin::is_pro() ) {
	$auto   = 'TYStudio\\UpgradeWarden\\Pro\\Automation';
	$export = 'TYStudio\\UpgradeWarden\\Pro\\ReportExport';

	$check( 'Pro: the log is kept for 90 days', 90 === Log::days(), Log::days() );

	update_option( $auto::OPTION, array( 'weekly' => true, 'after_update' => true, 'notify_log' => true, 'email' => 'owner@example.test' ), false );
	$auto::schedule();
	$check( 'Pro: weekly scan and daily digest are scheduled', false !== wp_next_scheduled( $auto::WEEKLY ) && false !== wp_next_scheduled( $auto::DAILY ) );

	$auto::start_scan();
	$guard = 0;

	while ( 'running' === Job::progress()['status'] && $guard++ < 200 ) {
		$auto::continue_scan();
	}

	$check( 'Pro: the scheduled scan finishes', 'done' === Job::progress()['status'], Job::progress() );
	$check( 'Pro: the first scheduled scan e-mails what will break', 1 === count( $mails ) && array( 'owner@example.test' ) === (array) $mails[0]['to'] && false !== strpos( $mails[0]['message'], 'Legacy Sample' ), $mails );

	$mails = array();
	$check( 'Pro: a scan with nothing new sends no e-mail', array() === $auto::report_changes() && ! $mails, $mails );

	$mails = array();
	update_option( $auto::STATE, array_merge( (array) get_option( $auto::STATE ), array( 'log_notified' => time() - DAY_IN_SECONDS ) ), false );
	$lines = $auto::daily();

	if ( $php8 ) {
		$check( 'Pro on PHP 8: the daily e-mail reports the fatal error', 1 === count( $mails ) && false !== strpos( $mails[0]['message'], 'count(): Argument #1' ), $lines );
	} else {
		$check( 'Pro on PHP 7: the daily e-mail reports the warning that will break', 1 === count( $mails ) && false !== strpos( $mails[0]['message'], 'count(): Parameter must be' ), $lines );
	}

	$mails = array();
	$auto::daily();
	$check( 'Pro: the next day, nothing new, no e-mail', ! $mails, $mails );

	// A day with more new messages than one e-mail looks at.
	$many = array();

	for ( $i = 0; $i < $auto::DIGEST_ROWS + 5; $i++ ) {
		$many[] = array( 'type' => 'E_DEPRECATED', 'message' => 'Digest filler ' . $i, 'file' => ABSPATH . 'wp-content/plugins/runtime-sample/runtime-sample.php', 'line' => 1000 + $i, 'match' => null );
	}

	$wpdb_rows = count( Log::rows( '', 1000 ) );
	foreach ( array_chunk( $many, 40 ) as $chunk ) {
		Log::record( $chunk );
	}
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET first_seen = %s WHERE message LIKE %s', Tables::events(), gmdate( 'Y-m-d H:i:s', time() - 60 ), 'Digest filler%' ) );
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET first_seen = %s WHERE message = %s', Tables::events(), gmdate( 'Y-m-d H:i:s', time() - 30 ), 'Digest filler ' . ( $auto::DIGEST_ROWS + 4 ) ) );
	update_option( $auto::STATE, array_merge( (array) get_option( $auto::STATE ), array( 'log_notified' => time() - 120 ) ), false );
	$auto::daily();
	$state = (array) get_option( $auto::STATE );
	$check( 'Pro: a full digest goes on from its last row next time', $state['log_notified'] < time() - 50, $state['log_notified'] - time() );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE message LIKE %s', Tables::events(), 'Digest filler%' ) );
	$check( 'Pro: the filler is gone again', count( Log::rows( '', 1000 ) ) === $wpdb_rows, count( Log::rows( '', 1000 ) ) );

	// "Scan after updates" alone: the one-off scan is not undone by the weekly setting.
	update_option( $auto::OPTION, array( 'after_update' => true ), false );
	$auto::schedule();
	$auto::after_update( null, array( 'type' => 'plugin', 'action' => 'update' ) );
	$auto::schedule();
	$check( 'Pro: the scan after an update stays scheduled when the weekly scan is off', false !== wp_next_scheduled( $auto::UPDATED ) && false === wp_next_scheduled( $auto::WEEKLY ) );
	wp_clear_scheduled_hook( $auto::UPDATED );

	update_option( $auto::OPTION, array(), false );
	$auto::schedule();
	$check( 'Pro: switching the checks off removes the schedule', false === wp_next_scheduled( $auto::WEEKLY ) && false === wp_next_scheduled( $auto::DAILY ) );

	$html = $export::html( '8.5' );
	$check( 'Pro: the report is one self-contained document', 0 === strpos( $html, '<!DOCTYPE html>' ) && false === strpos( $html, '<script' ) && false === strpos( $html, 'http://localhost/wp-' ) && false === strpos( $html, '<link' ), strlen( $html ) );
	$check( 'Pro: the report holds the findings and the log', false !== strpos( $html, 'legacy-sample.php:' ) && false !== strpos( $html, 'curly braces' ) && false !== strpos( $html, 'count():' ), strlen( $html ) );
	$check( 'Pro: the report escapes code', false === strpos( $html, '<?php' ) );
} else {
	$check( 'free: the log is kept for 7 days', 7 === Log::days(), Log::days() );
	$check( 'free: nothing is scheduled besides the clean-up of the log', false === wp_next_scheduled( 'upgw_auto_weekly' ) && false === wp_next_scheduled( 'upgw_auto_daily' ) );
}

// Clean-up of the log.
global $wpdb;
$wpdb->query( $wpdb->prepare( 'UPDATE ' . Tables::events() . ' SET last_seen = %s', gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ) ) );
Log::prune();
$check( 'messages past their time are dropped', 0 === count( Log::rows() ), count( Log::rows() ) );

Log::record(
	array(
		array( 'type' => 'E_DEPRECATED', 'message' => 'One more', 'file' => ABSPATH . 'wp-includes/post.php', 'line' => 3, 'match' => null ),
	)
);
$core = Log::rows();
$check( 'a message from WordPress itself is filed under core', 1 === count( $core ) && 'core' === $core[0]->component && 'wp-includes/post.php' === $core[0]->file, $core );
Log::clear();

// The server's folders do not reach the log, and a message the database refuses is tried again.
Log::record(
	array(
		array( 'type' => 'E_DEPRECATED', 'message' => 'Called in ' . ABSPATH . 'wp-content/plugins/x/y.php on line 4', 'file' => ABSPATH . 'wp-includes/post.php', 'line' => 9, 'match' => null ),
	)
);
$paths = Log::rows();
$check( 'the folders of the server are taken out of a message', 1 === count( $paths ) && 'Called in wp-content/plugins/x/y.php on line 4' === $paths[0]->message, $paths );
Log::clear();

$long = str_repeat( 'x', 1 ) . str_repeat( 'あ', 400 );
Capture::reset();
Capture::on_error( 8192, $long, ABSPATH . 'wp-includes/post.php', 11 );
Log::record( Capture::events() );
Capture::reset();
$cut = Log::rows();
$check( 'a long Japanese message is stored', 1 === count( $cut ) && 1 === preg_match( '//u', $cut[0]->message ), count( $cut ) );
Log::clear();
$check( 'emptying the log empties it', 0 === count( Log::rows() ) );

// Switching off, deactivating, deleting.
// Deactivating clears the clean-up; activating again with recording on brings it back.
Monitor::set( true );
wp_clear_scheduled_hook( Monitor::CRON );
Monitor::keep_pruning();
$check( 'a missing clean-up is scheduled again while recording is on', false !== wp_next_scheduled( Monitor::CRON ) );

Monitor::set( false );
$check( 'recording off: nothing scheduled, and the order of plugins is left to WordPress', ! Monitor::enabled() && false === wp_next_scheduled( Monitor::CRON ) );

deactivate_plugins( 'upgrade-warden/upgrade-warden.php' );
$check( 'deactivating leaves no scheduled event', false === wp_next_scheduled( Monitor::CRON ) && false === wp_next_scheduled( 'upgw_auto_weekly' ) && false === wp_next_scheduled( 'upgw_auto_after_update' ) && false === wp_next_scheduled( 'upgw_auto_continue' ) && false === wp_next_scheduled( 'upgw_auto_daily' ) );

Tables::uninstall();
$tables = $wpdb->get_col( 'SHOW TABLES' );
$left   = array_filter(
	$tables,
	static function ( $table ) {
		return false !== strpos( $table, 'upgw_' );
	}
);
$options = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'upgw\\_%'" );
$check( 'deleting removes the tables', ! $left, $left );
$check( 'deleting removes the options', ! $options, $options );

$check( 'the plugin raised no notice of its own (second half)', ! $upgw_notices, $upgw_notices );

echo '@@UPGW-WP@@', wp_json_encode( $out );
