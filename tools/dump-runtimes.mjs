/**
 * Step 1 of the catalog: ask every real PHP runtime what it contains.
 *
 *   node dump-runtimes.mjs
 *
 * Writes out/runtime-<version>.json for each version. The catalog builder
 * compares these files; nothing in them is typed in by hand.
 */
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { VERSIONS, startPhp, runPlain } from './lib/runtimes.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const out = join(here, 'out');
const code = readFileSync(join(here, 'lib', 'dump.php'), 'utf8');
const MARKER = '@@UPGW-DUMP@@';

mkdirSync(out, { recursive: true });

for (const version of VERSIONS) {
	const php = await startPhp(version);
	const text = await runPlain(php, code);
	const at = text.lastIndexOf(MARKER);

	if (at === -1) {
		console.error(`PHP ${version}: no dump in the output. First 300 characters: ${text.slice(0, 300)}`);
		process.exit(1);
	}

	const dump = JSON.parse(text.slice(at + MARKER.length));

	writeFileSync(join(out, `runtime-${version}.json`), JSON.stringify(dump));

	const deprecatedFunctions = Object.values(dump.functions).filter((f) => f.deprecated).length;
	const deprecatedConstants = Object.values(dump.constants).filter((c) => c.deprecated).length;

	console.log(
		`PHP ${dump.version.padEnd(7)} extensions ${String(dump.extensions.length).padStart(3)}  ` +
			`functions ${String(Object.keys(dump.functions).length).padStart(5)} (deprecated ${deprecatedFunctions})  ` +
			`classes ${String(Object.keys(dump.classes).length).padStart(4)}  ` +
			`constants ${String(Object.keys(dump.constants).length).padStart(5)} (deprecated ${deprecatedConstants})  ` +
			`ini ${dump.ini.length}`
	);
}

process.exit(0);
