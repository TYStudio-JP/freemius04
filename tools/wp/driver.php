<?php
/**
 * Drives Upgrade Warden inside a real WordPress and reports what it saw.
 * Run by tools/wp-check.mjs; prints one JSON document after a marker.
 */

$upgw_notices = array();

set_error_handler(
	static function ( $type, $message, $file, $line ) use ( &$upgw_notices ) {
		// Only what this plugin's own code raises is of interest here.
		if ( false !== strpos( $file, '/plugins/upgrade-warden/' ) ) {
			$upgw_notices[] = basename( $file ) . ':' . $line . ' ' . $message;
		}

		return true;
	}
);

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

use TYStudio\UpgradeWarden\Admin\Page;
use TYStudio\UpgradeWarden\Monitor\Monitor;
use TYStudio\UpgradeWarden\Plugin;
use TYStudio\UpgradeWarden\Pro\Fix\Service;
use TYStudio\UpgradeWarden\Scan\Components;
use TYStudio\UpgradeWarden\Scan\Job;
use TYStudio\UpgradeWarden\Scan\Report;
use TYStudio\UpgradeWarden\Store\Tables;

$out    = array( 'php' => PHP_VERSION, 'wp' => get_bloginfo( 'version' ), 'checks' => array() );
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

$activated = activate_plugin( 'upgrade-warden/upgrade-warden.php' );
$check( 'the plugin activates', null === $activated, is_wp_error( $activated ) ? $activated->get_error_message() : '' );

global $wpdb;
$tables = $wpdb->get_col( 'SHOW TABLES' );
$check( 'the three tables exist', in_array( Tables::files(), $tables, true ) && in_array( Tables::findings(), $tables, true ) && in_array( Tables::events(), $tables, true ), $tables );

$edition_is_free = ! is_dir( WP_PLUGIN_DIR . '/upgrade-warden/premium' );
$out['edition']  = $edition_is_free ? 'free' : 'pro';

$components = Components::all();
$check( 'the legacy sample plugin is listed', isset( $components['plugin:legacy-sample'] ), array_keys( $components ) );
$check( 'the plugin does not list itself', ! isset( $components['plugin:upgrade-warden'] ) );

// The scan, in the same short steps the browser would ask for.
Job::start();
$steps = 0;

do {
	$progress = Job::step( 1.0 );
	++$steps;
} while ( 'running' === $progress['status'] && $steps < 400 );

$out['scan'] = $progress + array( 'steps' => $steps );
$check( 'the scan finishes', 'done' === $progress['status'], $progress );
$check( 'the scan took more than one step', $steps > 1, $steps );

$summary = Report::summary( 'plugin:legacy-sample', '8.5' );
$out['legacy_summary'] = $summary;
$check( 'legacy sample: verdict "error" on 8.5', 'error' === $summary['verdict'], $summary );
$check( 'legacy sample: curly offset and each() break', 2 === $summary['error'], $summary );
$check( 'legacy sample: old constructor changes behaviour', 1 === $summary['changed'], $summary );
$check( 'legacy sample: three deprecations', 3 === $summary['deprecated'], $summary );
$check( 'legacy sample: the guarded call is counted as guarded', 1 === $summary['guarded'], $summary );

$on_83 = Report::summary( 'plugin:legacy-sample', '8.3' );
$check( 'legacy sample on 8.3: fewer deprecations, no new scan', 1 === $on_83['deprecated'], $on_83 );

$themes = array();

foreach ( $components as $id => $component ) {
	if ( 'plugin:legacy-sample' !== $id ) {
		$themes[ $id ] = Report::summary( $id, '8.5' );
	}
}

$out['others'] = $themes;
$broken        = array_filter(
	$themes,
	static function ( $s ) {
		return in_array( $s['verdict'], array( 'error', 'changed', 'unreadable' ), true );
	}
);
$check( 'bundled themes and plugins: nothing reported as breaking on 8.5', ! $broken, array_keys( $broken ) );

// A second scan must skip everything.
Job::start();

do {
	$again = Job::step( 1.0 );
} while ( 'running' === $again['status'] );

$check( 'a second scan re-scans nothing', 0 === $again['scanned'] && $again['skipped'] > 0, $again );

// The screen.
$overview = $render( array( 'page' => 'upgrade-warden' ) );
$check( 'overview lists the legacy sample', false !== strpos( $overview, 'Legacy Sample' ) && false !== strpos( $overview, 'upgw-error' ), strlen( $overview ) );
$detail = $render( array( 'page' => 'upgrade-warden', 'component' => 'plugin:legacy-sample' ) );
$check( 'detail shows the source line', false !== strpos( $detail, 'legacy-sample.php:' ) && false !== strpos( $detail, 'each( $items )' ), strlen( $detail ) );
$check( 'detail escapes code', false === strpos( $detail, '<?php' ) );
$out['html_bytes'] = array( 'overview' => strlen( $overview ), 'detail' => strlen( $detail ) );

$check( 'the detail says what each finding is, in a sentence', false === strpos( $detail, 'Rule <code>' ) && false !== strpos( $detail, 'curly braces' ) );
$check( 'the overview has the two tabs and the log column', false !== strpos( $overview, 'nav-tab' ) && false !== strpos( $overview, 'view=log' ) );

$log_view = $render( array( 'page' => 'upgrade-warden', 'view' => 'log' ) );
$check( 'the log view offers to start recording, and has no scan button', false !== strpos( $log_view, 'value="on"' ) && false === strpos( $log_view, 'id="upgw-scan"' ) );

// Pro: preview, apply, undo.
$check( $edition_is_free ? 'the free edition has no Pro' : 'Pro is on in this test', $edition_is_free ? ! Plugin::is_pro() : Plugin::is_pro() );

if ( $edition_is_free ) {
	$check( 'free: the overview names Pro in one line', false !== strpos( $overview, 'upgw-pro-note' ) );
	$check( 'free: the findings offer no fixing', false === strpos( $detail, 'upgw_fix' ) && false !== strpos( $detail, 'with Upgrade Warden Pro' ) );
	$check( 'free: no class of Pro can be loaded', ! class_exists( 'TYStudio\\UpgradeWarden\\Pro\\Bootstrap' ) );
	$check( 'free: no translation files are shipped', ! is_dir( WP_PLUGIN_DIR . '/upgrade-warden/languages' ) );
} else {
	$check( 'Pro: the overview has the automatic checks and no upsell line', false !== strpos( $overview, 'upgw-auto' ) && false === strpos( $overview, 'upgw-pro-note' ) );
}

if ( Plugin::is_pro() ) {
	$legacy   = $components['plugin:legacy-sample'];
	$file     = $legacy['path'] . '/legacy-sample.php';
	$original = file_get_contents( $file );
	$preview  = Service::preview( $legacy );

	$check( 'preview proposes changes and writes nothing', 1 === count( $preview ) && file_get_contents( $file ) === $original, array_keys( $preview ) );

	$applied = Service::apply( $legacy );
	$fixed   = file_get_contents( $file );
	$out['applied'] = $applied;

	$check( 'apply: no error', '' === $applied['error'], $applied );
	$check( 'apply: the file changed', $fixed !== $original );
	$check( 'apply: constructor renamed, old name kept', false !== strpos( $fixed, 'function __construct( $title' ) && false !== strpos( $fixed, 'self::__construct( $title );' ) );
	$check( 'apply: offsets, nullable, string and cast fixed', false !== strpos( $fixed, '$this->title[0]' ) && false !== strpos( $fixed, '?stdClass $item' ) && false !== strpos( $fixed, '"{$key}="' ) && false !== strpos( $fixed, '(int) $value' ) );
	$check( 'apply: each() is left alone (no verified fix)', false !== strpos( $fixed, 'each( $items )' ) );

	$after = Report::summary( 'plugin:legacy-sample', '8.5' );
	$out['after_fix'] = $after;
	$check( 'after the fix only each() still breaks', 1 === $after['error'] && 0 === $after['changed'] && 0 === $after['deprecated'], $after );

	$backups = Service::backups( 'plugin:legacy-sample' );
	$check( 'a backup exists and holds no .php file', 1 === count( $backups ) && ! glob( Service::backup_root() . '/' . $applied['backup'] . '/*.php' ), array_keys( $backups ) );

	$panel = $render( array( 'page' => 'upgrade-warden', 'component' => 'plugin:legacy-sample' ) );
	$check( 'the screen offers Undo', false !== strpos( $panel, 'value="undo"' ) );

	$undone = Service::undo( $legacy, $applied['backup'] );
	$check( 'undo restores the original byte for byte', file_get_contents( $file ) === $original && 1 === $undone['restored'], $undone );
	$check( 'after undo the findings are back', 2 === Report::summary( 'plugin:legacy-sample', '8.5' )['error'] );

	// A file changed after the fix must not be overwritten by undo.
	$again = Service::apply( $legacy );
	file_put_contents( $file, file_get_contents( $file ) . "\n// edited later\n" );
	$kept = Service::undo( $legacy, $again['backup'] );
	$check( 'undo leaves a file alone that changed after the fix', 0 === $kept['restored'] && array( 'legacy-sample.php' ) === $kept['kept'], $kept );
	file_put_contents( $file, $original );
}

// The run-time log: switch it on for the requests that follow.
$check( 'recording is off until it is switched on', ! Monitor::enabled() );
activate_plugin( 'runtime-sample/runtime-sample.php' );
Monitor::set( true );
$active = get_option( 'active_plugins' );
$check( 'recording on: the plugin loads first', Monitor::enabled() && 'upgrade-warden/upgrade-warden.php' === $active[0], $active );
$check( 'recording on: the clean-up is scheduled', false !== wp_next_scheduled( Monitor::CRON ) );

$check( 'the plugin raised no notice of its own', ! $upgw_notices, $upgw_notices );

echo '@@UPGW-WP@@', wp_json_encode( $out );
