/**
 * Real PHP runtimes, one per version, through php-wasm.
 *
 * Everything the catalog claims about a PHP version is checked by running code
 * on that version here. Nothing is taken from memory or from documentation alone.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';

const here = dirname(fileURLToPath(import.meta.url));

/** The versions php-wasm ships and this project tests against, oldest first. */
export const VERSIONS = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];

const MARKER = '@@UPGW-REPORT@@';
const HARNESS = readFileSync(join(here, 'harness.php'), 'utf8');
const WORK_DIR = '/upgw-work';

let nextProcessId = 4000;

/** Starts a fresh runtime of the given version. */
export async function startPhp(version) {
	const php = new PHP(await loadNodeRuntime(version, { emscriptenOptions: { processId: nextProcessId++ } }));

	php.mkdir(WORK_DIR);
	php.writeFile(`${WORK_DIR}/harness.php`, HARNESS);

	return php;
}

/** Runs code as is and returns its raw output. Throws if PHP itself fails. */
export async function runPlain(php, code) {
	const response = await php.run({ code });

	return response.text;
}

/**
 * Runs a fixture and returns a report of what the runtime did with it:
 * diagnostics, an uncaught throwable, a fatal error and the captured output.
 */
export async function runFixture(php, code) {
	php.writeFile(`${WORK_DIR}/fixture.php`, code);

	const entry = `<?php $GLOBALS['__upgw_fixture'] = '${WORK_DIR}/fixture.php'; require '${WORK_DIR}/harness.php';`;
	let stdout = '';

	try {
		const response = await php.runStream({ code: entry });

		stdout = await response.stdoutText;
		await response.exitCode;
	} catch (error) {
		// A fatal error ends the request with a non-zero exit code. The report is
		// still written by the shutdown function, so look for it in the message.
		stdout = String(error && error.message ? error.message : error);
	}

	const at = stdout.lastIndexOf(MARKER);

	if (at === -1) {
		return { status: 'no-report', raw: stdout.slice(0, 500), diagnostics: [], thrown: null, fatal: null, output: '' };
	}

	const report = JSON.parse(stdout.slice(at + MARKER.length));

	report.status = classify(report);

	return report;
}

/** One word for what happened, most severe first. */
export function classify(report) {
	if (report.thrown && report.thrown.class === 'ParseError') {
		return 'parse';
	}

	if (report.fatal && report.fatal.type === 'E_PARSE') {
		return 'parse';
	}

	if (report.thrown || report.fatal) {
		return 'fatal';
	}

	const types = report.diagnostics.map((d) => d.type);

	if (types.includes('E_DEPRECATED') || types.includes('E_USER_DEPRECATED')) {
		return 'deprecated';
	}

	if (types.includes('E_WARNING') || types.includes('E_USER_WARNING') || types.includes('E_COMPILE_WARNING')) {
		return 'warning';
	}

	if (types.includes('E_NOTICE') || types.includes('E_USER_NOTICE') || types.includes('E_STRICT')) {
		return 'notice';
	}

	return 'ok';
}

/** True when the code cannot run at all on that version. */
export function breaks(status) {
	return status === 'parse' || status === 'fatal';
}
