/**
 * Reads the official UPGRADING notes and answers one question about them:
 * does the note for PHP x.y mention this name, and in which section?
 */
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const dir = join(here, '..', 'out', 'upgrading');
const cache = new Map();

/** Splits one UPGRADING file into its numbered sections. */
function load(version) {
	if (cache.has(version)) {
		return cache.get(version);
	}

	const path = join(dir, `UPGRADING-${version}.txt`);

	if (!existsSync(path)) {
		throw new Error(`Missing ${path}. Run "node fetch-upgrading.mjs" first.`);
	}

	const text = readFileSync(path, 'utf8').replace(/\r\n/g, '\n');
	const sections = [];
	const heading = /^={10,}\n(\d+)\. (.+)\n={10,}$/gm;
	let match;
	let previous = null;

	while ((match = heading.exec(text)) !== null) {
		if (previous) {
			previous.body = text.slice(previous.start, match.index);
		}

		previous = { number: Number(match[1]), title: match[2].trim(), start: heading.lastIndex, body: '' };
		sections.push(previous);
	}

	if (previous) {
		previous.body = text.slice(previous.start);
	}

	const parsed = { text, sections };

	cache.set(version, parsed);

	return parsed;
}

function escape(value) {
	return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Titles of the sections of the PHP x.y notes that mention the name as a whole
 * word. An empty list means the notes do not mention it.
 *
 * With asCall, only "name(" counts. Function names such as "each" or "split"
 * are ordinary English words, and the notes are written in English, so a bare
 * word match would confirm things the notes never said. A name with an
 * underscore or a digit in it cannot be mistaken for prose and may appear bare.
 */
export function mentions(version, name, asCall = false) {
	const { sections } = load(version);
	const before = '(?<![A-Za-z0-9_\\\\$>:])';
	const bare = new RegExp(`${before}${escape(name)}(?![A-Za-z0-9_])`, 'i');
	const call = new RegExp(`${before}${escape(name)}\\s*\\(`, 'i');
	const pattern = asCall && !/[_0-9]/.test(name) ? call : bare;

	return sections.filter((section) => pattern.test(section.body)).map((section) => section.title);
}

/** True when the PHP x.y notes contain this exact text, ignoring line breaks and runs of spaces. */
export function containsQuote(version, quote) {
	const squeeze = (value) => value.replace(/\s+/g, ' ').trim();

	return squeeze(load(version).text).includes(squeeze(quote));
}
