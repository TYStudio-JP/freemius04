/**
 * Step 3: check every rule's claim against real PHP runtimes.
 *
 *   node verify-fixtures.mjs [filter]
 *
 * A fixture (fixtures/*.fixture) holds a claim, code that exercises the feature
 * and, optionally, the code as the fixer should leave it:
 *
 *   --RULE--    rule id
 *   --CLAIM--   JSON: { "deprecated_in": "7.4", "changed_in": null, "breaks_in": "8.0" }
 *   --CODE--    PHP that uses the feature and prints something
 *   --FIXED--   the same program after the fix (optional)
 *   --SCAN--    JSON read by the PHP tests only, for example { "guarded": true }
 *               when the scanner must mark its findings as guarded (optional)
 *
 * The code runs on every runtime. The claim holds when:
 *
 *   breaks_in      from that version on the code cannot run; before it, it can
 *   deprecated_in  from that version, until it breaks or changes, PHP raises a
 *                  deprecation; before it, PHP raises none
 *   changed_in     from that version on the output differs from before, silently
 *
 * The fixed code must run on every runtime without a deprecation and print
 * exactly what the original printed wherever the original still ran. That is
 * the evidence that a fix does not change what the program does.
 *
 * Results go to fixtures/observed.json, which is committed: it is the record of
 * what was actually seen. A claim about a version with no runtime here is
 * listed under "not_checked" instead of being passed off as verified.
 */
import { readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { VERSIONS, startPhp, runFixture, breaks } from './lib/runtimes.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const dir = join(here, 'fixtures');
const filter = process.argv[2] || '';

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

const number = (version) => {
	const [major, minor] = version.split('.').map(Number);

	return major * 100 + minor;
};
const atLeast = (version, since) => number(version) >= number(since);
const tested = (version) => VERSIONS.includes(version);

function check(fixture, runs, fixedRuns) {
	const { claim } = fixture;
	const problems = [];
	const notChecked = [];
	const status = (version) => runs[version].status;

	for (const key of ['deprecated_in', 'changed_in', 'breaks_in']) {
		const since = claim[key];

		if (!since) {
			continue;
		}

		if (number(since) > number(VERSIONS[VERSIONS.length - 1])) {
			notChecked.push(`${key} ${since}: no runtime of that version here`);
		} else if (number(since) < number(VERSIONS[0])) {
			notChecked.push(`${key} ${since}: seen from ${VERSIONS[0]} on; the exact first version comes from the notes`);
		}
	}

	for (const version of VERSIONS) {
		const broken = breaks(status(version));
		const shouldBreak = Boolean(claim.breaks_in) && atLeast(version, claim.breaks_in);

		if (broken !== shouldBreak) {
			problems.push(`${version}: ${broken ? 'does not run' : 'runs'} (${status(version)}), claim says it ${shouldBreak ? 'breaks' : 'runs'}`);
			continue;
		}

		if (broken) {
			continue;
		}

		// Once the behaviour has changed, PHP no longer warns about the old one.
		const deprecated = status(version) === 'deprecated';
		const shouldDeprecate =
			Boolean(claim.deprecated_in) &&
			atLeast(version, claim.deprecated_in) &&
			!(claim.changed_in && atLeast(version, claim.changed_in));

		if (deprecated !== shouldDeprecate) {
			problems.push(`${version}: ${deprecated ? 'raises a deprecation' : 'raises no deprecation'} (${status(version)}), claim says ${shouldDeprecate ? 'deprecated' : 'not deprecated'}`);
		}
	}

	if (claim.changed_in && tested(claim.changed_in)) {
		const before = VERSIONS.filter((v) => !atLeast(v, claim.changed_in) && !breaks(status(v)));
		const after = VERSIONS.filter((v) => atLeast(v, claim.changed_in) && !breaks(status(v)));

		if (before.length === 0 || after.length === 0) {
			problems.push(`changed_in ${claim.changed_in}: needs a runtime on both sides of the change`);
		} else {
			const old = runs[before[0]].output;

			for (const version of before) {
				if (runs[version].output !== old) {
					problems.push(`${version}: output already differs before ${claim.changed_in}`);
				}
			}

			for (const version of after) {
				if (runs[version].output === old) {
					problems.push(`${version}: output is the same as before ${claim.changed_in}, claim says it changes`);
				}
			}
		}
	} else if (!claim.changed_in) {
		const running = VERSIONS.filter((v) => !breaks(status(v)));

		for (const version of running) {
			if (runs[version].output !== runs[running[0]].output) {
				problems.push(`${version}: output differs from ${running[0]}, but the claim names no behaviour change`);
			}
		}
	}

	if (fixedRuns) {
		// What the program is meant to print: its output on the oldest runtime,
		// where the old way of writing it still worked.
		const reference = runs[VERSIONS[0]];

		if (breaks(reference.status)) {
			problems.push(`fixed: the original does not run on ${VERSIONS[0]}, so there is nothing to compare the fix with`);
		}

		for (const version of VERSIONS) {
			const fixed = fixedRuns[version];

			if (fixed.status !== 'ok') {
				const detail = fixed.thrown ? fixed.thrown.message : fixed.fatal ? fixed.fatal.message : fixed.diagnostics.map((d) => d.message).join(' | ');

				problems.push(`fixed on ${version}: ${fixed.status} (${detail})`);
			} else if (fixed.output !== reference.output) {
				problems.push(`fixed on ${version}: prints ${JSON.stringify(fixed.output)}, the original printed ${JSON.stringify(reference.output)} on ${VERSIONS[0]}`);
			}
		}
	}

	return { problems, notChecked };
}

function firstMessage(run) {
	if (run.thrown) {
		return `${run.thrown.class}: ${run.thrown.message}`;
	}

	if (run.fatal) {
		return `${run.fatal.type}: ${run.fatal.message}`;
	}

	return run.diagnostics.length > 0 ? `${run.diagnostics[0].type}: ${run.diagnostics[0].message}` : '';
}

const files = readdirSync(dir)
	.filter((name) => name.endsWith('.fixture') && name.includes(filter))
	.sort();
const fixtures = files.map((name) => ({ name, ...parseFixture(readFileSync(join(dir, name), 'utf8')) }));
const results = {};

for (const fixture of fixtures) {
	results[fixture.name] = { runs: {}, fixedRuns: fixture.fixed === null ? null : {} };
}

// One runtime per version, replaced after any run that did not end cleanly.
for (const version of VERSIONS) {
	let php = await startPhp(version);

	const run = async (code) => {
		const report = await runFixture(php, code);

		if (breaks(report.status) || report.status === 'no-report') {
			php = await startPhp(version);
		}

		return report;
	};

	for (const fixture of fixtures) {
		results[fixture.name].runs[version] = await run(fixture.code);

		if (fixture.fixed !== null) {
			results[fixture.name].fixedRuns[version] = await run(fixture.fixed);
		}
	}
}

const observed = {};
let failed = 0;

for (const fixture of fixtures) {
	const { runs, fixedRuns } = results[fixture.name];
	const { problems, notChecked } = check(fixture, runs, fixedRuns);
	const statuses = Object.fromEntries(VERSIONS.map((v) => [v, runs[v].status]));
	const line = VERSIONS.map((v) => `${v}:${runs[v].status}`).join('  ');

	observed[fixture.name] = {
		rule: fixture.rule,
		claim: fixture.claim,
		verdict: problems.length === 0 ? 'verified' : 'mismatch',
		observed: statuses,
		messages: Object.fromEntries(VERSIONS.map((v) => [v, firstMessage(runs[v])]).filter(([, message]) => message !== '')),
		fix: fixedRuns ? (problems.some((p) => p.startsWith('fixed')) ? 'mismatch' : 'same output on every version') : 'none',
		not_checked: notChecked,
		problems,
	};

	console.log(`${problems.length === 0 ? 'ok      ' : 'MISMATCH'} ${fixture.name}`);
	console.log(`         ${line}${fixedRuns ? '   | fix: ' + observed[fixture.name].fix : ''}`);

	for (const note of notChecked) {
		console.log(`         not checked: ${note}`);
	}

	for (const problem of problems) {
		console.log(`         ! ${problem}`);
		failed++;
	}
}

// One record per fixture, next to it, so that people working on different
// rules at the same time never write to the same file.
for (const fixture of fixtures) {
	const runtimes = Object.fromEntries(VERSIONS.map((v) => [v, results[fixture.name].runs[v].version]));

	writeFileSync(
		join(dir, fixture.name.replace(/\.fixture$/, '.observed.json')),
		JSON.stringify({ runtimes, ...observed[fixture.name] }, null, '\t') + '\n'
	);
}

console.log(`\n${fixtures.length} fixtures on ${VERSIONS.length} PHP versions, ${failed} problems.`);
process.exit(failed === 0 ? 0 : 1);
