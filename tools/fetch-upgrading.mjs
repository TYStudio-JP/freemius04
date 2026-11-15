/**
 * Downloads the official UPGRADING notes of every PHP release branch from the
 * php-src repository into out/upgrading/. These are the documentation half of
 * the double check: a change only enters the catalog when a real runtime shows
 * it and these notes mention it.
 *
 *   node fetch-upgrading.mjs
 *
 * The files are not committed. They belong to the PHP project.
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const out = join(here, 'out', 'upgrading');

export const DOCUMENTED_VERSIONS = ['7.0', '7.1', '7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5', '8.6'];

mkdirSync(out, { recursive: true });

for (const version of DOCUMENTED_VERSIONS) {
	const url = `https://raw.githubusercontent.com/php/php-src/PHP-${version}/UPGRADING`;
	const response = await fetch(url);

	if (!response.ok) {
		console.error(`PHP ${version}: ${response.status} ${url}`);
		process.exit(1);
	}

	const text = await response.text();

	writeFileSync(join(out, `UPGRADING-${version}.txt`), text);
	console.log(`PHP ${version}: ${text.split('\n').length} lines`);
}
