/**
 * Compiles every PHP file under a folder on real PHP runtimes, without running
 * it, and reports what each version makes of it.
 *
 *   node lint-tree.mjs <folder> [version ...]
 *
 * Used to measure the fixer on real code: the same tree is compiled before and
 * after the fixes.
 *
 * Each file goes through the whole compiler and nothing in it is executed
 * (see lib/compile-only.php). That matters: PHP 8.0 to 8.3 reject "$text{0}"
 * in the compiler, not in the parser, so a parser-only check would call such
 * a file fine.
 *
 * Compiling says nothing about calls to functions that no longer exist. Those
 * fail when they are reached, and only then.
 */
import { readdirSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';
import { createNodeFsMountHandler } from '@php-wasm/node';
import { VERSIONS, startPhp, compileOnly, breaks } from './lib/runtimes.mjs';

const folder = process.argv[2];
const versions = process.argv.length > 3 ? process.argv.slice(3) : VERSIONS;

if (!folder) {
	console.error('Usage: node lint-tree.mjs <folder> [version ...]');
	process.exit(2);
}

const root = resolve(folder);
const files = [];

(function walk(dir) {
	for (const entry of readdirSync(dir, { withFileTypes: true })) {
		if (entry.isDirectory()) {
			if (entry.name !== '.git' && entry.name !== 'node_modules') {
				walk(join(dir, entry.name));
			}
		} else if (entry.name.toLowerCase().endsWith('.php')) {
			files.push(relative(root, join(dir, entry.name)));
		}
	}
})(root);

files.sort();

const start = async (version) => {
	const php = await startPhp(version);

	php.mkdir('/tree');
	await php.mount('/tree', createNodeFsMountHandler(root));

	return php;
};

for (const version of versions) {
	let php = await start(version);
	const refused = [];
	const messages = [];
	let deprecations = 0;
	let filesWithDeprecations = 0;
	let reported = null;

	for (const file of files) {
		const path = '/tree/' + file.split('\\').join('/');
		const report = await compileOnly(php, path);

		reported = reported || report.version;

		if (breaks(report.status) || report.status === 'no-report') {
			const message = report.thrown ? report.thrown.message : report.fatal ? report.fatal.message : report.raw || 'no report';

			refused.push(`${file}: ${message}`);
			php = await start(version);
			continue;
		}

		// Nothing in the file may have run: the only output is the harness's own.
		if (report.output !== 'compiled') {
			refused.push(`${file}: the file was not compiled cleanly (output: ${JSON.stringify(report.output.slice(0, 60))})`);
			continue;
		}

		const raised = report.diagnostics.filter((d) => d.type === 'E_DEPRECATED');

		if (raised.length > 0) {
			deprecations += raised.length;
			filesWithDeprecations++;

			for (const notice of raised) {
				messages.push(`${file}:${notice.line}: ${notice.message}`);
			}
		}
	}

	console.log(
		`PHP ${reported}: ${files.length} files, ${refused.length} refused by the compiler, ` +
			`${deprecations} compile-time deprecations in ${filesWithDeprecations} files`
	);

	for (const line of refused.slice(0, 15)) {
		console.log(`   ${line}`);
	}

	if (refused.length > 15) {
		console.log(`   … and ${refused.length - 15} more`);
	}

	if (process.env.UPGW_SHOW_NOTICES) {
		for (const line of messages.slice(0, 25)) {
			console.log(`   notice  ${line}`);
		}

		if (messages.length > 25) {
			console.log(`   … and ${messages.length - 25} more notices`);
		}
	}
}

process.exit(0);
