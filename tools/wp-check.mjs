/**
 * Runs Upgrade Warden inside a real WordPress on real PHP runtimes.
 *
 *   node wp-check.mjs <wordpress-folder> <sqlite-integration-repo> [version ...]
 *
 * <wordpress-folder>  a WordPress release (for example a checkout of
 *                     https://github.com/WordPress/WordPress at a release tag)
 * <sqlite-integration-repo>  a checkout of
 *                     https://github.com/WordPress/sqlite-database-integration
 *
 * For each PHP version a fresh site is built under out/, WordPress is
 * installed on SQLite, and wp/driver.php activates the plugin, scans, reads
 * the results, renders the screens and applies and undoes fixes.
 *
 * What this is not: a browser. The JavaScript that drives the scan from the
 * screen and the look of the page are not exercised here.
 */
import { cpSync, existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';
import { createNodeFsMountHandler } from '@php-wasm/node';
import { VERSIONS, startPhp } from './lib/runtimes.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');
const [wordpress, sqliteRepo, ...wanted] = process.argv.slice(2);
const versions = wanted.length > 0 ? wanted : [VERSIONS[0], VERSIONS[VERSIONS.length - 1]];

if (!wordpress || !sqliteRepo) {
	console.error('Usage: node wp-check.mjs <wordpress-folder> <sqlite-integration-repo> [version ...]');
	process.exit(2);
}

function buildSite(version) {
	const site = join(here, 'out', `wp-site-${version}`);
	const plugins = join(site, 'wp-content', 'plugins');
	const sqlite = join(plugins, 'sqlite-database-integration');

	rmSync(site, { recursive: true, force: true });
	mkdirSync(site, { recursive: true });
	cpSync(resolve(wordpress), site, { recursive: true, filter: (source) => !source.includes('/.git') });

	// The plugin links one of its folders to a sibling package; copy what the link points at.
	cpSync(join(resolve(sqliteRepo), 'packages', 'plugin-sqlite-database-integration'), sqlite, {
		recursive: true,
		filter: (source) => !source.endsWith('/wp-includes/database'),
	});
	cpSync(join(resolve(sqliteRepo), 'packages', 'mysql-on-sqlite', 'src'), join(sqlite, 'wp-includes', 'database'), { recursive: true });
	writeFileSync(
		join(site, 'wp-content', 'db.php'),
		readFileSync(join(sqlite, 'db.copy'), 'utf8')
			.replaceAll('{SQLITE_IMPLEMENTATION_FOLDER_PATH}', '/wordpress/wp-content/plugins/sqlite-database-integration')
			.replaceAll('{SQLITE_PLUGIN}', 'sqlite-database-integration/load.php')
	);

	// Only what a release would ship.
	const own = join(plugins, 'upgrade-warden');

	for (const part of ['upgrade-warden.php', 'uninstall.php', 'src', 'premium', 'data', 'lib', 'assets']) {
		cpSync(join(root, part), join(own, part), { recursive: true });
	}

	cpSync(join(here, 'wp', 'legacy-sample'), join(plugins, 'legacy-sample'), { recursive: true });

	writeFileSync(
		join(site, 'wp-config.php'),
		[
			'<?php',
			"define( 'DB_NAME', 'wordpress' );",
			"define( 'DB_USER', '' );",
			"define( 'DB_PASSWORD', '' );",
			"define( 'DB_HOST', '' );",
			"define( 'DB_CHARSET', 'utf8mb4' );",
			"define( 'DB_COLLATE', '' );",
			"define( 'WP_DEBUG', true );",
			"define( 'WP_DEBUG_DISPLAY', false );",
			"define( 'UPGW_DEV_PRO', true );",
			"define( 'WP_HOME', 'http://localhost' );",
			"define( 'WP_SITEURL', 'http://localhost' );",
			'foreach ( array( "AUTH_KEY", "SECURE_AUTH_KEY", "LOGGED_IN_KEY", "NONCE_KEY", "AUTH_SALT", "SECURE_AUTH_SALT", "LOGGED_IN_SALT", "NONCE_SALT" ) as $upgw_key ) { define( $upgw_key, "test-" . $upgw_key ); }',
			"$table_prefix = 'wp_';",
			"if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }",
			"require_once ABSPATH . 'wp-settings.php';",
			'',
		].join('\n')
	);

	return site;
}

async function request(php, code) {
	try {
		const response = await php.runStream({ code });
		const text = await response.stdoutText;
		const errors = await response.stderrText;

		await response.exitCode;

		return text + (errors ? '\n[stderr] ' + errors : '');
	} catch (error) {
		return String(error && error.message ? error.message : error);
	}
}

let failed = 0;

for (const version of versions) {
	const site = buildSite(version);
	const php = await startPhp(version);

	php.mkdir('/wordpress');
	await php.mount('/wordpress', createNodeFsMountHandler(site));

	const installed = await request(
		php,
		`<?php define( 'WP_INSTALLING', true ); require '/wordpress/wp-load.php'; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$r = wp_install( 'Upgrade Warden test', 'admin', 'admin@example.test', true, '', 'not-a-real-password' ); echo 'installed user ', (int) $r['user_id'];`
	);

	if (!/installed user 1/.test(installed)) {
		failed++;
		const text = installed.replace(/<style[\s\S]*?<\/style>/g, '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');

		console.log(`PHP ${version}: WordPress did not install.\n${text.slice(0, 1500)}`);
		continue;
	}

	const output = await request(php, readFileSync(join(here, 'wp', 'driver.php'), 'utf8'));
	const at = output.lastIndexOf('@@UPGW-WP@@');

	if (at === -1) {
		failed++;
		console.log(`PHP ${version}: the driver did not finish.\n${output.slice(0, 3000)}`);
		continue;
	}

	const report = JSON.parse(output.slice(at + 11));
	const bad = report.checks.filter((check) => !check.ok);

	console.log(
		`PHP ${report.php}, WordPress ${report.wp}: ${report.checks.length - bad.length} of ${report.checks.length} checks passed. ` +
			`Scan: ${report.scan.scanned} files in ${report.scan.steps} steps over ${report.scan.total} plugins and themes.`
	);

	for (const check of bad) {
		failed++;
		console.log(`   FAILED  ${check.name}  ${check.detail.slice(0, 400)}`);
	}

	if (existsSync(join(site, 'wp-content', 'debug.log'))) {
		const own = readFileSync(join(site, 'wp-content', 'debug.log'), 'utf8')
			.split('\n')
			.filter((line) => line.includes('upgrade-warden'));

		if (own.length > 0) {
			failed++;
			console.log(`   debug.log mentions the plugin:\n   ${own.slice(0, 5).join('\n   ')}`);
		}
	}
}

process.exit(failed === 0 ? 0 : 1);
