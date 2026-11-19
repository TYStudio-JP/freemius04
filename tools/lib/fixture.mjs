/** Reads the sections of a fixture file. Shared by the verify scripts. */
export function parseFixture(text) {
	const sections = {};
	let current = null;

	for (const line of text.replace(/\r\n/g, '\n').split('\n')) {
		const heading = /^--([A-Z]+)--$/.exec(line);

		if (heading) {
			current = heading[1];
			sections[current] = [];
		} else if (current) {
			sections[current].push(line);
		}
	}

	const get = (name) => (sections[name] ? sections[name].join('\n').trim() : null);

	return {
		rule: get('RULE'),
		claim: JSON.parse(get('CLAIM') || '{}'),
		code: get('CODE') + '\n',
		fixed: get('FIXED') === null ? null : get('FIXED') + '\n',
	};
}
