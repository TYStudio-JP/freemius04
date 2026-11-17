export const meta = {
  name: 'cover-php-upgrade-notes',
  description: 'Give every item of the PHP 8.0-8.6 upgrade notes a verdict, write runtime-verified rules for the detectable ones, then review each tranche adversarially',
  phases: [
    { title: 'Cover', detail: 'one agent per tranche of the upgrade notes: ledger, fixtures, rules' },
    { title: 'Review', detail: 'a sceptic per tranche: false positives, unverified claims, skipped items' },
  ],
}

const RESUMED = ['8.2', '8.3']
// Absolute path of the repository checkout. Change it when the checkout lives elsewhere.
const ROOT = '/home/user/freemius04'
const NOTES = ROOT + '/tools/out/upgrading'

const TRANCHES = [
  { key: '8.0-bic-core', version: '8.0', scope: 'section "1. Backward Incompatible Changes", the "- Core:" area only' },
  { key: '8.0-bic-ext', version: '8.0', scope: 'section "1. Backward Incompatible Changes", every area EXCEPT "- Core:" (all the extension areas, from the one after Core to the end of the section)' },
  { key: '8.0-dep', version: '8.0', scope: 'section "4. Deprecated Functionality" (all areas)' },
  { key: '8.1-bic', version: '8.1', scope: 'section "1. Backward Incompatible Changes" (all areas)' },
  { key: '8.1-dep', version: '8.1', scope: 'section "4. Deprecated Functionality" (all areas)' },
  { key: '8.2', version: '8.2', scope: 'sections "1. Backward Incompatible Changes" and "4. Deprecated Functionality" (all areas)' },
  { key: '8.3', version: '8.3', scope: 'sections "1. Backward Incompatible Changes" and "4. Deprecated Functionality" (all areas)' },
  { key: '8.4-bic-core', version: '8.4', scope: 'section "1. Backward Incompatible Changes", from the start of the section through the "- Core:" area and every area up to and including "- GMP:" (alphabetical areas Core..GMP)' },
  { key: '8.4-bic-rest', version: '8.4', scope: 'section "1. Backward Incompatible Changes", every area AFTER "- GMP:" to the end of the section' },
  { key: '8.4-dep', version: '8.4', scope: 'section "4. Deprecated Functionality" (all areas)' },
  { key: '8.5-bic', version: '8.5', scope: 'section "1. Backward Incompatible Changes" (all areas)' },
  { key: '8.5-dep', version: '8.5', scope: 'section "4. Deprecated Functionality" (all areas)' },
  { key: '8.6-bic', version: '8.6', scope: 'section "1. Backward Incompatible Changes" (all areas)' },
  { key: '8.6-dep', version: '8.6', scope: 'section "4. Deprecated Functionality" (all areas)' },
]

const REPORT = {
  type: 'object',
  properties: {
    ledger_file: { type: 'string' },
    bullets_in_scope: { type: 'integer', description: 'how many bullets ("  . ...") the scope contains, counted from the notes' },
    ledger_entries: { type: 'integer' },
    rules_written: { type: 'array', items: { type: 'string' }, description: 'rule ids' },
    files_created: { type: 'array', items: { type: 'string' } },
    fixtures_verified: { type: 'integer' },
    claims_corrected_by_runtime: { type: 'array', items: { type: 'string' }, description: 'cases where the real runtime contradicted the notes or your expectation' },
    shared_file_requests: { type: 'array', items: { type: 'string' } },
    unfinished: { type: 'array', items: { type: 'string' } },
  },
  required: ['ledger_file', 'bullets_in_scope', 'ledger_entries', 'rules_written', 'files_created', 'fixtures_verified', 'claims_corrected_by_runtime', 'shared_file_requests', 'unfinished'],
}

const REVIEW = {
  type: 'object',
  properties: {
    ledger_complete: { type: 'boolean' },
    bullets_counted: { type: 'integer' },
    ledger_entries: { type: 'integer' },
    problems_found: { type: 'array', items: { type: 'string' } },
    problems_fixed: { type: 'array', items: { type: 'string' } },
    problems_left: { type: 'array', items: { type: 'string' } },
    tests_pass: { type: 'boolean' },
  },
  required: ['ledger_complete', 'bullets_counted', 'ledger_entries', 'problems_found', 'problems_fixed', 'problems_left', 'tests_pass'],
}

function coverPrompt(t) {
  const noRuntime = t.version === '8.6'
  const resumeNote = RESUMED.includes(t.key) ? `
A PREVIOUS ATTEMPT at this tranche was interrupted (container restart / usage limit). It left uncommitted files in the tree: run "git status --short" in ${ROOT}; the untracked files whose names contain your tranche key or the class prefix Php${t.version.replace('.', '')} are yours. Older leftovers of the same tranche are also in ${ROOT}/tools/out/partial (same relative paths). You may edit, reuse or delete these files - they belong to your tranche - but trust none of them: re-verify every fixture, re-read every rule, and delete what you cannot verify. Write the ledger first if it is missing, so that an interruption loses less.
` : ''
  return `You are extending a PHP compatibility scanner. Work in ${ROOT}.

FIRST read ${ROOT}/docs/adding-rules.md in full. It is the working agreement and it overrides your habits. Then read the three example rules it names and one or two fixtures in tools/fixtures/.

Your tranche: the official PHP ${t.version} upgrade notes, ${NOTES}/UPGRADING-${t.version}.txt — ${t.scope}.
Tranche key: ${t.key}. Use it in every file name you create: the ledger is tools/ledger/${t.key}.json; prefix new rule class names and test files so they cannot collide with other people's (several people are working on other tranches in this same tree right now).

What to deliver:
1. A ledger entry for EVERY bullet in your scope (a bullet starts with "  . "), in the order of the notes. Count the bullets first and make the ledger have exactly that many entries.
2. For every bullet that describes something visible in how PHP source code is written (syntax, declarations, calls to named functions/methods with literal arguments, class structure), and that plugin or theme code could plausibly contain: a fixture that demonstrates it, verified with "cd tools && node verify-fixtures.mjs <name>", and a rule in src/Engine/Rules/ that detects it. Check first whether data/catalog/ or an existing rule already covers it; if so the disposition is "catalog" or "rule" with the existing id and you write nothing new.
3. For bullets that are real but depend on run-time values: a fixture with rule id "runtime.<name>" demonstrating the behaviour (no rule class).
4. A test file with code that looks similar and must NOT be reported, for each rule you write.
${noRuntime ? `
IMPORTANT for 8.6: there is NO PHP 8.6 runtime. A fixture claiming "deprecated_in": "8.6" will run on 7.4-8.5 and must simply show nothing happens there; the verifier lists the 8.6 part as "not checked". Every finding you report for 8.6 must set $finding->evidence = 'documented'. Do not claim "breaks_in": "8.6" or "changed_in": "8.6" for anything unless the notes state it in so many words, and quote the notes in the rule's docblock. Function and constant deprecations of 8.6 are already in data/catalog/ (26 functions, 1 constant) - check before writing.
` : ''}
Hard requirements:
- The runtime decides. Run every fixture. If PHP does not do what the notes or you expected, change the claim and record it in claims_corrected_by_runtime.
- Precision over recall: when the syntax tree cannot tell the bad code from good code, do not report it; give the bullet disposition "not-detectable" or "runtime-only".
- Run your rules against WordPress 7.1.4 as the guide describes. Any "error" or "changed" finding there must be examined at the source line; fix the rule if it is a false positive.
- "php tests/run.php" may fail in a file that is not yours while someone else is mid-edit. Judge your own work with a filter (php tests/run.php <part of your fixture or test name>), and re-run the whole suite once at the end.
- Create files only; do not edit existing files; no git commands that change state. Write each PHP file complete in one go (a half-written rule file breaks everyone's test run).
- Do not stop early because the tranche is large. If you truly cannot finish, list exactly which bullets are not done in "unfinished" - every one of them must still have a ledger entry.

${resumeNote}Return the structured report.`
}

function reviewPrompt(t, report) {
  return `You are reviewing someone else's work on a PHP compatibility scanner in ${ROOT}. Assume it contains mistakes; your job is to find them, and to fix what you safely can.

Read ${ROOT}/docs/adding-rules.md first.

The work: tranche "${t.key}" = PHP ${t.version} upgrade notes (${NOTES}/UPGRADING-${t.version}.txt), ${t.scope}.
Its author reports: ${JSON.stringify(report)}

Check, in this order:
1. Completeness. Count the bullets ("  . ") in the scope yourself. The ledger tools/ledger/${t.key}.json must have one entry per bullet with an exact quote. Add entries for skipped bullets (you may edit this ledger file).
2. Dispositions. For entries marked "not-detectable" or "out-of-scope", ask whether a plugin or theme could contain it and whether the syntax tree could in fact show it. For entries marked "rule" or "catalog", confirm the rule or catalog entry really exists and really covers that bullet.
3. Evidence. Every fixture named in the ledger must have a .observed.json next to it with "verdict": "verified". Re-run "cd tools && node verify-fixtures.mjs <name>" for a sample of at least five (or all, if fewer). A rule whose versions differ from its fixture is a defect.
4. False positives. For each rule of this tranche, write three pieces of valid, modern, commonly written PHP that resemble the target, and scan them (php bin/scan.php <file> --target=8.5). Run the rules over ${ROOT}/tools/out/corpus/wp-latest with --target=8.5 and --target=8.6 and inspect every finding from this tranche's rules at the source line. WordPress 7.1.4 supports PHP 8.5, so "error" and "changed" findings there are suspect.
5. False negatives. Does each rule miss obvious variants (methods vs functions, closures, namespaced code, case differences)?

You may edit the files this tranche created (its rules, fixtures, tests, ledger) to fix defects, and add fixtures and tests. Do not edit other files; no git commands that change state. If a rule cannot be made precise, delete that rule file, its test and move its ledger entries to "not-detectable" with the reason. After your changes run "php tests/run.php" (other people may be mid-edit in unrelated files; what matters is that nothing of this tranche fails).

Return the structured report. List under problems_left anything you found and did not fix.`
}

// Two tranches at a time, the ones with the freshest evidence of user pain
// first. Fourteen at once exhausted the usage limit before any finished.
const ORDER = ['8.4-dep', '8.1-dep', '8.2', '8.3', '8.5-dep', '8.0-bic-core', '8.5-bic', '8.4-bic-core', '8.0-dep', '8.1-bic', '8.6-dep', '8.6-bic', '8.4-bic-rest', '8.0-bic-ext']
const wanted = Array.isArray(args) && args.length ? args : ORDER
const queue = wanted.map((key) => TRANCHES.find((t) => t.key === key)).filter(Boolean)
const results = []

for (let i = 0; i < queue.length; i += 2) {
  const batch = queue.slice(i, i + 2)
  log(`Starting ${batch.map((t) => t.key).join(' and ')} (${i + batch.length} of ${queue.length})`)
  const done = await pipeline(
    batch,
    (t) => agent(coverPrompt(t), { label: `cover:${t.key}`, phase: 'Cover', schema: REPORT }),
    (report, t) => report
      ? agent(reviewPrompt(t, report), { label: `review:${t.key}`, phase: 'Review', schema: REVIEW }).then((review) => ({ key: t.key, report, review }))
      : { key: t.key, report: null, review: null },
  )
  batch.forEach((t, n) => results.push(done[n] || { key: t.key, report: null, review: null }))
  if (done.every((r) => !r || !r.report)) {
    log('Both tranches of this batch failed; stopping so the rest is not burned on the same cause.')
    break
  }
}

return results