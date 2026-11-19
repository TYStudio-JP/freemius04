/**
 * Builds the archives of the plugin.
 *
 *   node tools/build-zip.mjs
 *
 *   build/upgrade-warden.zip               the Pro edition: upload this to Freemius
 *   build/upgrade-warden-free-preview.zip  what the free edition will contain
 *
 * Freemius makes the real free edition from the uploaded ZIP: it removes the
 * folders named in "@fs_premium_only", every block guarded by
 * "if ( upgw_fs()->is__premium_only() ) {" and every method whose name ends
 * in "__premium_only". The preview does the same here, so the free edition
 * can be checked (tools/wp-check.mjs --free, Plugin Check) before anything is
 * uploaded. The archive to submit to WordPress.org is the one Freemius
 * generates, not the preview.
 *
 * Files and folders listed in .distignore are left out.
 */
import { existsSync, mkdirSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { dirname, join, relative, sep } from 'node:path';
import { deflateRawSync } from 'node:zlib';
import { copyTree } from './lib/copy.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const SLUG = 'upgrade-warden';

/** Copies what a release ships into dest. */
export function stagePro(dest) {
	const ignored = readFileSync(join(root, '.distignore'), 'utf8')
		.split('\n')
		.map((line) => line.trim().replace(/^\//, ''))
		.filter(Boolean);

	rmSync(dest, { recursive: true, force: true });
	mkdirSync(dest, { recursive: true });

	const kept = (source) => {
		const path = relative(root, source).split(sep).join('/');

		return !ignored.some((entry) => path === entry || path.startsWith(entry + '/'));
	};

	// Entry by entry: the destination may lie inside the repository (build/).
	for (const name of readdirSync(root)) {
		if (kept(join(root, name))) {
			copyTree(join(root, name), join(dest, name), kept);
		}
	}
}

/** Offset just after the string or comment that starts at i, or i when none starts there. */
function skipQuiet(code, i) {
	const two = code.slice(i, i + 2);

	if (two === '//' || code[i] === '#') {
		const end = code.indexOf('\n', i);

		return end === -1 ? code.length : end;
	}

	if (two === '/*') {
		const end = code.indexOf('*/', i + 2);

		return end === -1 ? code.length : end + 2;
	}

	if (code[i] === "'" || code[i] === '"') {
		let j = i + 1;

		while (j < code.length && code[j] !== code[i]) {
			j += code[j] === '\\' ? 2 : 1;
		}

		return j + 1;
	}

	return i;
}

/** Offset of the brace that closes the one at open. */
function closing(code, open) {
	let depth = 0;

	for (let i = open; i < code.length; i++) {
		const after = skipQuiet(code, i);

		if (after !== i) {
			i = after - 1;
			continue;
		}

		if (code[i] === '{') {
			depth++;
		} else if (code[i] === '}' && --depth === 0) {
			return i;
		}
	}

	throw new Error('Unbalanced braces.');
}

/** Removes the premium-only blocks and methods from PHP source. */
export function stripPremium(code) {
	const guard = /[ \t]*if \( upgw_fs\(\)->is__premium_only\(\) \) \{/g;
	let match;

	while ((match = guard.exec(code)) !== null) {
		const end = closing(code, match.index + match[0].length - 1);
		const lineEnd = code[end + 1] === '\n' ? end + 2 : end + 1;

		code = code.slice(0, match.index) + code.slice(lineEnd);
		guard.lastIndex = match.index;
	}

	const method = /((?:[ \t]*\/\*\*(?:(?!\*\/)[\s\S])*\*\/\n)?)[ \t]*(?:(?:public|private|protected|static|final) )*function \w+__premium_only\([^)]*\)[^{;]*\{/g;

	while ((match = method.exec(code)) !== null) {
		const end = closing(code, match.index + match[0].length - 1);
		let lineEnd = code[end + 1] === '\n' ? end + 2 : end + 1;

		// One blank line goes with the method.
		if (code[lineEnd] === '\n') {
			lineEnd++;
		}

		code = code.slice(0, match.index) + code.slice(lineEnd);
		method.lastIndex = match.index;
	}

	return code;
}

function phpFiles(dir, out = []) {
	for (const name of readdirSync(dir)) {
		const path = join(dir, name);

		if (statSync(path).isDirectory()) {
			phpFiles(path, out);
		} else if (name.endsWith('.php')) {
			out.push(path);
		}
	}

	return out;
}

/** Copies what the free edition ships into dest. Returns what still mentions Pro code. */
export function stageFree(dest) {
	stagePro(dest);
	rmSync(join(dest, 'premium'), { recursive: true, force: true });
	rmSync(join(dest, 'languages'), { recursive: true, force: true });

	const leftovers = [];

	for (const file of [join(dest, `${SLUG}.php`), ...phpFiles(join(dest, 'src'))]) {
		let code = stripPremium(readFileSync(file, 'utf8'));

		if (file.endsWith(`${SLUG}.php`)) {
			code = code.replace(" *\n * @fs_premium_only /premium/, /languages/\n", '').replace("'is_premium'          => true,", "'is_premium'          => false,");
		}

		writeFileSync(file, code);

		const path = relative(dest, file).split(sep).join('/');

		code.split('\n').forEach((line, index) => {
			if (/__premium_only|UpgradeWarden\\+Pro\\|['"/]premium\/|\bPro\\\w/.test(line)) {
				leftovers.push(`${path}:${index + 1}: ${line.trim()}`);
			}
		});
	}

	return leftovers;
}

const CRC_TABLE = Array.from({ length: 256 }, (_, n) => {
	let c = n;

	for (let k = 0; k < 8; k++) {
		c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
	}

	return c >>> 0;
});

function crc32(buffer) {
	let crc = 0xffffffff;

	for (const byte of buffer) {
		crc = CRC_TABLE[(crc ^ byte) & 0xff] ^ (crc >>> 8);
	}

	return (crc ^ 0xffffffff) >>> 0;
}

function allFiles(dir, out = []) {
	for (const name of readdirSync(dir).sort()) {
		const path = join(dir, name);

		if (statSync(path).isDirectory()) {
			allFiles(path, out);
		} else {
			out.push(path);
		}
	}

	return out;
}

/** Writes a ZIP of dir, with every path under folder/. Returns the number of files. */
export function zip(dir, folder, target) {
	const local = [];
	const central = [];
	let offset = 0;
	const files = allFiles(dir);

	for (const file of files) {
		const name = Buffer.from(`${folder}/${relative(dir, file).split(sep).join('/')}`, 'utf8');
		const data = readFileSync(file);
		const packed = deflateRawSync(data, { level: 9 });
		const head = Buffer.alloc(30);

		head.writeUInt32LE(0x04034b50, 0);
		head.writeUInt16LE(20, 4);
		head.writeUInt16LE(0x0800, 6);
		head.writeUInt16LE(8, 8);
		head.writeUInt16LE(0, 10);
		head.writeUInt16LE(0x21, 12);
		head.writeUInt32LE(crc32(data), 14);
		head.writeUInt32LE(packed.length, 18);
		head.writeUInt32LE(data.length, 22);
		head.writeUInt16LE(name.length, 26);

		const entry = Buffer.alloc(46);

		entry.writeUInt32LE(0x02014b50, 0);
		entry.writeUInt16LE(20, 4);
		head.copy(entry, 6, 4, 30);
		entry.writeUInt32LE(0o100644 * 0x10000, 38);
		entry.writeUInt32LE(offset, 42);

		local.push(head, name, packed);
		central.push(entry, name);
		offset += 30 + name.length + packed.length;
	}

	const directory = Buffer.concat(central);
	const end = Buffer.alloc(22);

	end.writeUInt32LE(0x06054b50, 0);
	end.writeUInt16LE(files.length, 8);
	end.writeUInt16LE(files.length, 10);
	end.writeUInt32LE(directory.length, 12);
	end.writeUInt32LE(offset, 16);

	writeFileSync(target, Buffer.concat([...local, directory, end]));

	return files.length;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
	const build = join(root, 'build');
	const main = readFileSync(join(root, `${SLUG}.php`), 'utf8');

	if (!existsSync(join(root, 'vendor', 'freemius', 'start.php')) && !existsSync(join(root, 'vendor', 'freemius', 'wordpress-sdk', 'start.php')) && !existsSync(join(root, 'freemius', 'start.php'))) {
		console.log('Warning: the Freemius SDK is not bundled (vendor/freemius). The build runs in development mode.');
	}

	if (main.includes("define( 'UPGW_FS_PRODUCT_ID', '' )")) {
		console.log('Warning: UPGW_FS_PRODUCT_ID is empty. The build runs in development mode, without licensing.');
	}

	rmSync(build, { recursive: true, force: true });
	mkdirSync(build, { recursive: true });

	stagePro(join(build, 'pro', SLUG));
	console.log(`build/${SLUG}.zip: ${zip(join(build, 'pro', SLUG), SLUG, join(build, `${SLUG}.zip`))} files (Pro; upload this to Freemius)`);

	const leftovers = stageFree(join(build, 'free', SLUG));

	console.log(`build/${SLUG}-free-preview.zip: ${zip(join(build, 'free', SLUG), SLUG, join(build, `${SLUG}-free-preview.zip`))} files (what the free edition will contain)`);

	if (leftovers.length > 0) {
		console.log('\nThe free edition still mentions Pro code. WordPress.org does not accept that:');
		console.log(leftovers.join('\n'));
		process.exit(1);
	}
}
