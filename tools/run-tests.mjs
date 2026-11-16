/**
 * Runs the PHP test suite (tests/run.php) on every real PHP runtime.
 *
 *   node run-tests.mjs
 *
 * The plugin has to work on the oldest PHP a stuck site may still run, not
 * only on the PHP of the machine it was written on.
 */
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { createNodeFsMountHandler } from '@php-wasm/node';
import { VERSIONS, startPhp } from './lib/runtimes.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
let failed = 0;

for (const version of VERSIONS) {
	const php = await startPhp(version);

	php.mkdir('/repo');
	await php.mount('/repo', createNodeFsMountHandler(root));

	let output = '';
	let exitCode = 0;

	try {
		const response = await php.runStream({ scriptPath: '/repo/tests/run.php' });

		output = await response.stdoutText;
		exitCode = await response.exitCode;
	} catch (error) {
		output = String(error && error.message ? error.message : error);
		exitCode = 1;
	}

	const lines = output.trim().split('\n');
	const summary = lines[lines.length - 1];

	if (exitCode !== 0 || !/ 0 failed /.test(summary)) {
		failed++;
		console.log(`PHP ${version}: FAILED (exit code ${exitCode})`);
		console.log(lines.slice(0, 40).join('\n'));
	} else {
		console.log(`PHP ${version}: ${summary}`);
	}
}

process.exit(failed === 0 ? 0 : 1);
