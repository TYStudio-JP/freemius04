/**
 * Runs a PHP script of this repository on one of the real PHP runtimes, for
 * machines that have no PHP on the command line.
 *
 *   node tools/php.mjs [--php=8.3] <script> [arguments ...]
 *
 *   node tools/php.mjs tests/run.php
 *   node tools/php.mjs --php=7.4 bin/scan.php tools/out/corpus/wp-latest --target=8.5 --summary
 *   node tools/php.mjs bin/i18n.php ja
 *
 * The repository is mounted at /repo and is the working directory, so paths
 * are given relative to the repository root. Files the script writes land in
 * the repository.
 */
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { createNodeFsMountHandler } from '@php-wasm/node';
import { startPhp } from './lib/runtimes.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
let version = '8.3';

if (args.length > 0 && args[0].startsWith('--php=')) {
	version = args.shift().slice(6);
}

if (args.length === 0) {
	console.error('Usage: node tools/php.mjs [--php=8.3] <script> [arguments ...]');
	process.exit(2);
}

const php = await startPhp(version);

php.mkdir('/repo');
await php.mount('/repo', createNodeFsMountHandler(root));
php.chdir('/repo');

const script = args.shift().replace(/\\/g, '/');
let exitCode = 1;

try {
	const response = await php.cli(['php', '-d', 'memory_limit=1024M', script.startsWith('/') ? script : `/repo/${script}`, ...args]);

	response.stdout.pipeTo(new WritableStream({ write: (chunk) => void process.stdout.write(chunk) }));
	response.stderr.pipeTo(new WritableStream({ write: (chunk) => void process.stderr.write(chunk) }));
	exitCode = await response.exitCode;
} catch (error) {
	console.error(String(error && error.message ? error.message : error));
}

process.exit(exitCode);
