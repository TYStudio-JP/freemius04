/**
 * Step 2 of the catalog: turn the runtime dumps into the catalog the plugin ships.
 *
 *   node build-catalog.mjs
 *
 * A removal or deprecation enters the catalog only when two independent sources
 * agree:
 *
 *   1. a real PHP runtime shows it (the function is gone, or flagged deprecated), and
 *   2. the official UPGRADING notes of that version mention it.
 *
 * Whatever only one source shows goes to out/review-*.json for a person to look
 * at, and is left out of the catalog. Comparing runtimes alone is not enough:
 * two builds can differ because of how they were compiled.
 *
 * Changes in versions without a runtime here (PHP 8.6) come from
 * catalog-src/documented.json. Each entry there must quote the UPGRADING notes
 * word for word, and is marked "documented" instead of "verified".
 */
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { VERSIONS } from './lib/runtimes.mjs';
import { mentions, containsQuote } from './lib/upgrading.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const out = join(here, 'out');
const dataDir = join(here, '..', 'data', 'catalog');
const srcDir = join(here, 'catalog-src');

/** Versions older than the oldest runtime, known from the notes only. */
const OLDER = ['7.0', '7.1', '7.2', '7.3'];

const dumps = VERSIONS.map((version) => {
	const path = join(out, `runtime-${version}.json`);

	if (!existsSync(path)) {
		throw new Error(`Missing ${path}. Run "node dump-runtimes.mjs" first.`);
	}

	return JSON.parse(readFileSync(path, 'utf8'));
});

function readJson(name, fallback) {
	const path = join(srcDir, name);

	return existsSync(path) ? JSON.parse(readFileSync(path, 'utf8')) : fallback;
}

/**
 * Functions of extensions that left PHP before the oldest runtime here.
 *
 * The notes only name the extension ("- ext/mysql"). The function names are
 * read from the function table in the last PHP source tree that still had the
 * extension, never typed in. Each function must also be absent from every
 * runtime, which is the part a runtime can confirm.
 */
async function removedExtensionFunctions() {
	const found = {};

	for (const item of readJson('removed-extensions.json', [])) {
		if (!containsQuote(item.version, item.quote)) {
			throw new Error(`removed-extensions.json: "${item.quote}" is not in the PHP ${item.version} notes.`);
		}

		const cached = join(out, `source-${item.ext}.c`);

		if (!existsSync(cached)) {
			const url = `https://raw.githubusercontent.com/php/php-src/${item.source}`;
			const response = await fetch(url);

			if (!response.ok) {
				throw new Error(`${url}: ${response.status}`);
			}

			writeFileSync(cached, await response.text());
		}

		const source = readFileSync(cached, 'utf8');
		const names = new Set();

		for (const match of source.matchAll(/\bPHP_(?:DEP_)?(?:FE|FALIAS)\(\s*([a-z0-9_]+)/gi)) {
			names.add(match[1].toLowerCase());
		}

		if (names.size === 0) {
			throw new Error(`${item.source}: no function table entries found.`);
		}

		for (const name of names) {
			const survivor = dumps.find((dump) => name in dump.functions);

			if (survivor) {
				throw new Error(`${name} is listed for the removed ${item.ext} extension but exists on PHP ${survivor.version}.`);
			}

			found[name] = { deprecated: null, removed: item.version, ext: item.ext, evidence: 'verified', instead: item.instead };
		}

		console.log(`removed extension ${item.ext}: ${names.size} functions from ${item.source}`);
	}

	return found;
}

const fromRemovedExtensions = await removedExtensionFunctions();
const families = readJson('families.json', []);
const documented = readJson('documented.json', { functions: [], constants: [] });
const advice = readJson('advice.json', { functions: {}, constants: {} });

/** A hand-written note that covers a group of names the notes describe in prose. */
function familyNote(kind, name, version) {
	for (const family of families) {
		if (family.kind !== kind || family.version !== version) {
			continue;
		}

		if (!new RegExp(family.pattern, 'i').test(name)) {
			continue;
		}

		if (!containsQuote(version, family.quote)) {
			throw new Error(`families.json: the quote for /${family.pattern}/ is not in the PHP ${version} notes.`);
		}

		return family.quote;
	}

	return null;
}

function isDocumented(kind, name, version) {
	return mentions(version, name, kind === 'functions').length > 0 || familyNote(kind, name, version) !== null;
}

/**
 * The first version before the given one whose notes list the name under its
 * deprecations, or null. Some deprecations are raised from inside the function
 * and never show as a flag on the runtime, so the notes are the only record.
 */
function deprecationAnnouncedBefore(kind, name, version) {
	const all = [...OLDER, ...VERSIONS];
	const found = all
		.slice(0, all.indexOf(version))
		.find((earlier) => mentions(earlier, name, kind === 'functions').some((title) => /deprecat/i.test(title)));

	return found || null;
}

function build(kind) {
	const names = new Set();

	for (const dump of dumps) {
		for (const name of Object.keys(dump[kind])) {
			names.add(name);
		}
	}

	const entries = {};
	const review = [];

	for (const name of [...names].sort()) {
		const present = dumps.map((dump) => name in dump[kind]);
		const flagged = dumps.map((dump) => Boolean(dump[kind][name] && dump[kind][name].deprecated));
		const first = present.indexOf(true);
		const ext = dumps[first][kind][name].ext;
		const problems = [];
		let removed = null;
		let deprecated = null;

		for (let i = 1; i < dumps.length; i++) {
			if (!present[i - 1] || present[i]) {
				continue;
			}

			const owner = dumps[i - 1][kind][name].ext;

			if (!dumps[i].extensions.includes(owner)) {
				problems.push(`gone in the ${VERSIONS[i]} build, but so is its whole extension (${owner})`);
			} else if (isDocumented(kind, name, VERSIONS[i]) || deprecationAnnouncedBefore(kind, name, VERSIONS[i]) !== null) {
				// Either the notes of that version name the removal, or older notes
				// announced the deprecation and the runtime now shows the end of it.
				removed = VERSIONS[i];
			} else {
				problems.push(`gone in the ${VERSIONS[i]} runtime, not mentioned in the ${VERSIONS[i]} notes`);
			}

			break;
		}

		const firstFlag = flagged.indexOf(true);

		if (firstFlag > 0) {
			if (isDocumented(kind, name, VERSIONS[firstFlag])) {
				deprecated = VERSIONS[firstFlag];
			} else {
				problems.push(`flagged deprecated from ${VERSIONS[firstFlag]}, not mentioned in the ${VERSIONS[firstFlag]} notes`);
			}
		} else if (firstFlag === 0) {
			// Already deprecated on the oldest runtime. The notes say since when.
			const since = [...OLDER, VERSIONS[0]].find((version) => isDocumented(kind, name, version));

			if (since) {
				deprecated = since;
			} else {
				problems.push(`flagged deprecated on ${VERSIONS[0]} already, and no notes from 7.0 to ${VERSIONS[0]} mention it`);
			}
		}

		if (removed !== null && deprecated === null) {
			deprecated = deprecationAnnouncedBefore(kind, name, removed);
		}

		if (problems.length > 0) {
			review.push({ name, ext, problems });
		}

		if (removed === null && deprecated === null) {
			continue;
		}

		entries[name] = { deprecated, removed, ext, evidence: 'verified' };
	}

	if (kind === 'functions') {
		Object.assign(entries, fromRemovedExtensions);
	}

	const newest = dumps[dumps.length - 1];

	for (const item of documented[kind] || []) {
		const key = kind === 'functions' ? item.name.toLowerCase() : item.name;

		if (!containsQuote(item.version, item.quote)) {
			throw new Error(`documented.json: the quote for ${item.name} is not in the PHP ${item.version} notes.`);
		}

		if (!(key in newest[kind])) {
			throw new Error(`documented.json: ${item.name} does not exist on PHP ${newest.version}, so it cannot become deprecated later.`);
		}

		if (entries[key]) {
			throw new Error(`documented.json: ${item.name} is already in the catalog from the runtimes.`);
		}

		entries[key] = { deprecated: item.version, removed: null, ext: newest[kind][key].ext, evidence: 'documented' };
	}

	for (const [name, text] of Object.entries(advice[kind] || {})) {
		const key = kind === 'functions' ? name.toLowerCase() : name;

		if (!entries[key]) {
			throw new Error(`advice.json: ${name} is not in the catalog.`);
		}

		entries[key].instead = text;
	}

	return { entries, review };
}

function phpValue(value) {
	if (value === null) {
		return 'null';
	}

	return `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
}

function writePhp(file, title, entries) {
	const lines = [
		'<?php',
		'/**',
		` * ${title}`,
		' *',
		' * Generated by tools/build-catalog.mjs. Do not edit by hand.',
		' *',
		' * evidence "verified":   a real PHP runtime shows the change and the official',
		' *                        UPGRADING notes of that version mention it.',
		' * evidence "documented": taken from the official UPGRADING notes only, because',
		' *                        no runtime of that version was available to check it.',
		' *',
		' * @package UpgradeWarden',
		' */',
		'',
		"defined( 'ABSPATH' ) || exit;",
		'',
		'return array(',
	];

	for (const name of Object.keys(entries).sort()) {
		const entry = entries[name];
		const parts = [
			`'deprecated' => ${phpValue(entry.deprecated)}`,
			`'removed' => ${phpValue(entry.removed)}`,
			`'ext' => ${phpValue(entry.ext)}`,
			`'evidence' => ${phpValue(entry.evidence)}`,
		];

		if (entry.instead) {
			parts.push(`'instead' => ${phpValue(entry.instead)}`);
		}

		lines.push(`\t${phpValue(name)} => array( ${parts.join(', ')} ),`);
	}

	lines.push(');', '');
	writeFileSync(file, lines.join('\n'));
}

mkdirSync(dataDir, { recursive: true });

for (const kind of ['functions', 'constants']) {
	const { entries, review } = build(kind);
	const all = Object.values(entries);
	const count = (test) => all.filter(test).length;

	writePhp(
		join(dataDir, `${kind}.php`),
		kind === 'functions' ? 'Internal functions that newer PHP versions deprecate or remove.' : 'Internal constants that newer PHP versions deprecate or remove.',
		entries
	);
	writeFileSync(join(out, `review-${kind}.json`), JSON.stringify(review, null, '\t'));

	console.log(
		`${kind}: ${all.length} in the catalog ` +
			`(verified ${count((e) => e.evidence === 'verified')}, documented only ${count((e) => e.evidence === 'documented')}; ` +
			`removed ${count((e) => e.removed !== null)}, deprecated ${count((e) => e.deprecated !== null)}). ` +
			`Left out for review: ${review.length}.`
	);

	for (const item of review) {
		console.log(`   review  ${item.name} [${item.ext}]: ${item.problems.join('; ')}`);
	}
}
