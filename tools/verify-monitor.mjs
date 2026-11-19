/**
 * Checks the run-time monitor against the real PHP runtimes.
 *
 *   node verify-monitor.mjs [filter]
 *
 * The scanner is tied to the fixtures by the PHP tests. This does the same
 * for the monitor: every fixture is run on every runtime with the monitor
 * listening (src/Monitor/Capture.php, not a copy), and for every message that
 * tools/verify-fixtures.mjs recorded and that is in data/catalog/messages.php
 * the monitor must
 *
 *   - have caught that message on that version, and
 *   - explain it with the claim of the fixture.
 *
 * It also must not explain a message with a claim the fixture does not make.
 *
 * Unlike the fixture harness, an error thrown by the fixture is left
 * uncaught here, so the path through the end of the request is the one a
 * site would take.
 */
import { readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { createNodeFsMountHandler } from '@php-wasm/node';
import { VERSIONS, startPhp } from './lib/runtimes.mjs';
import { parseFixture } from './lib/fixture.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');
const dir = join(here, 'fixtures');
const filter = process.argv[2] || '';
const MARKER = '@@UPGW-MONITOR@@';

const HARNESS = `<?php
error_reporting( -1 );
ini_set( 'display_errors', '0' );
ini_set( 'log_errors', '0' );
define( 'ABSPATH', '/repo/' );
require '/repo/src/Monitor/Signatures.php';
require '/repo/src/Monitor/Capture.php';

use TYStudio\\UpgradeWarden\\Monitor\\Capture;
use TYStudio\\UpgradeWarden\\Monitor\\Signatures;

function upgw_monitor_sink( $events ) {
	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}

	foreach ( $events as $index => $event ) {
		$events[ $index ]['key'] = Signatures::normalize( $event['type'], $event['message'] );
	}

	echo "\\n${MARKER}", json_encode( $events, JSON_PARTIAL_OUTPUT_ON_ERROR );
}

Capture::install( new Signatures( '/repo/data/catalog' ), 'upgw_monitor_sink' );
ob_start();
include '/upgw-work/fixture.php';
`;

// The catalog, read through PHP so that the keys are exactly what PHP sees.
const reader = await startPhp('8.3');

reader.mkdir('/repo');
await reader.mount('/repo', createNodeFsMountHandler(root));

const catalog = JSON.parse(
	(await reader.run({ code: `<?php define( 'ABSPATH', '/' ); echo json_encode( include '/repo/data/catalog/messages.php' );` })).text
);
const normalize = async (pairs) => {
	reader.writeFile('/upgw-work/pairs.json', JSON.stringify(pairs));

	return JSON.parse(
		(
			await reader.run({
				code: `<?php define( 'ABSPATH', '/' ); require_once '/repo/src/Monitor/Signatures.php';
				$out = array();
				foreach ( json_decode( file_get_contents( '/upgw-work/pairs.json' ), true ) as $pair ) {
					$out[] = \\TYStudio\\UpgradeWarden\\Monitor\\Signatures::normalize( $pair[0], $pair[1] );
				}
				echo json_encode( $out );`,
			})
		).text
	);
};

const files = readdirSync(dir)
	.filter((name) => name.endsWith('.fixture') && name.includes(filter))
	.sort();
const cases = [];

for (const name of files) {
	const fixture = parseFixture(readFileSync(join(dir, name), 'utf8'));
	const record = JSON.parse(readFileSync(join(dir, name.replace(/\.fixture$/, '.observed.json')), 'utf8'));
	const versions = Object.keys(record.messages || {});
	const pairs = versions.map((version) => {
		const message = record.messages[version];
		const at = message.indexOf(': ');

		return [message.slice(0, at), message.slice(at + 2)];
	});
	const keys = await normalize(pairs);
	const expected = {};

	versions.forEach((version, index) => {
		const entry = catalog[keys[index]];

		// Only where the catalog entry is this fixture's own claim: the same
		// message can be an incidental one in another fixture.
		if (
			entry &&
			entry[1] === (record.claim.deprecated_in ?? null) &&
			entry[2] === (record.claim.changed_in ?? null) &&
			entry[3] === (record.claim.breaks_in ?? null)
		) {
			expected[version] = { key: keys[index], entry };
		}
	});

	if (Object.keys(expected).length > 0) {
		cases.push({ name, fixture, record, expected });
	}
}

console.log(`${cases.length} of ${files.length} fixtures have a message the monitor should explain.`);

let failed = 0;
let checked = 0;

for (const version of VERSIONS) {
	let php = null;
	const fresh = async () => {
		php = await startPhp(version);
		php.mkdir('/repo');
		await php.mount('/repo', createNodeFsMountHandler(root));
		php.writeFile('/upgw-work/monitor-harness.php', HARNESS);
	};

	await fresh();

	for (const { name, fixture, record, expected } of cases) {
		if (!expected[version]) {
			continue;
		}

		php.writeFile('/upgw-work/fixture.php', fixture.code);

		let stdout = '';
		let clean = true;

		try {
			const response = await php.runStream({ scriptPath: '/upgw-work/monitor-harness.php' });

			stdout = await response.stdoutText;
			clean = (await response.exitCode) === 0;
		} catch (error) {
			stdout = String(error && error.message ? error.message : error);
			clean = false;
		}

		if (!clean) {
			await fresh();
		}

		const at = stdout.lastIndexOf(MARKER);
		const events = at === -1 ? [] : JSON.parse(stdout.slice(at + MARKER.length));
		const { key, entry } = expected[version];
		const hit = events.find((event) => event.key === key);

		checked++;

		if (!hit) {
			failed++;
			console.log(`MISSED   ${name} on ${version}: ${record.messages[version].slice(0, 120)}`);
			console.log(`         caught: ${events.map((event) => event.key.slice(0, 80)).join(' | ') || 'nothing'}`);
		} else if (
			!hit.match ||
			hit.match.rule !== entry[0] ||
			hit.match.deprecated !== entry[1] ||
			hit.match.changed !== entry[2] ||
			hit.match.breaks !== entry[3] ||
			hit.match.evidence !== 'verified'
		) {
			failed++;
			console.log(`WRONG    ${name} on ${version}: explained as ${JSON.stringify(hit.match)}, the record says ${JSON.stringify(entry)}`);
		}
	}

	console.log(`PHP ${version} done.`);
}

console.log(`\n${checked} messages on ${VERSIONS.length} PHP versions, ${failed} problems.`);
process.exit(failed === 0 ? 0 : 1);
