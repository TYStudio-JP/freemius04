/**
 * Copies a folder, file by file.
 *
 * fs.cpSync() ended the Node process without a message on Windows (Node 24,
 * a path with non-ASCII characters) for some folders, so the tools copy by
 * hand.
 */
import { copyFileSync, mkdirSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Copies source to dest. filter( path ) decides per file and folder; a
 * folder that is filtered out is not entered.
 */
export function copyTree(source, dest, filter = () => true) {
	if (!filter(source)) {
		return;
	}

	if (!statSync(source).isDirectory()) {
		copyFileSync(source, dest);

		return;
	}

	mkdirSync(dest, { recursive: true });

	for (const name of readdirSync(source)) {
		copyTree(join(source, name), join(dest, name), filter);
	}
}
