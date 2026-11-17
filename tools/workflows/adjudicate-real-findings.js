export const meta = {
  name: 'adjudicate-real-findings',
  description: 'For each rule that fired on real plugins and WordPress, have independent sceptics decide on real PHP whether the findings are true',
  phases: [
    { title: 'Judge', detail: 'two independent sceptics per rule, each reproducing on the real runtimes' },
  ],
}

// Absolute path of the repository checkout. Change it when the checkout lives elsewhere.
const ROOT = '/home/user/freemius04'
const CASES = args

const VERDICT = {
  type: 'object',
  properties: {
    rule: { type: 'string' },
    places: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          file: { type: 'string' },
          line: { type: 'integer' },
          verdict: { type: 'string', enum: ['true-positive', 'false-positive', 'wrong-level', 'unsure'] },
          evidence: { type: 'string', description: 'what you ran on which PHP versions and what it printed; or why you could not' },
        },
        required: ['file', 'line', 'verdict', 'evidence'],
      },
    },
    rule_defect: { type: 'string', description: 'empty if the rule is right; otherwise the defect and a minimal reproduction' },
  },
  required: ['rule', 'places', 'rule_defect'],
}

function prompt(c, lens) {
  return `You are checking a PHP compatibility scanner for false positives. Work read-only in ${ROOT}: do not edit or create files inside the repository, and run no git command that changes state. Scratch files go under ${ROOT}/tools/out/scratch/judge-${c.rule.replace(/[^a-z0-9]+/gi, '-')}-${lens}/.

The rule "${c.rule}" fired ${c.count} times on the current sources of well-known WordPress plugins and WordPress 7.1.4, all of which claim to support recent PHP. Scanning for target PHP 8.5. Find the rule's class with: grep -rl "'${c.rule}'" ${ROOT}/src/Engine/Rules. Its evidence fixtures are ${ROOT}/tools/fixtures/${c.rule}*.fixture with *.observed.json next to them.

Places to judge (level as reported, file:line, extra text):
${c.sample.map((s) => `- ${s.level} ${s.file}:${s.line} ${s.rest}`).join('\n')}

${lens === 'reproduce'
  ? `Your method: for each place, read the source around the line, then build a minimal standalone PHP program that does what that code does (same calls, same argument kinds, same class structure) and run it on the real runtimes. To run code on PHP 7.4 to 8.5, write a .mjs script in your scratch folder that imports { VERSIONS, startPhp, runFixture } from '${ROOT}/tools/lib/runtimes.mjs' (runFixture(php, code) returns { version, status, diagnostics, thrown, fatal, output }); run it with node from ${ROOT}/tools so the node_modules there resolve (node ${'$'}SCRATCH/your.mjs works if you put it under ${ROOT}/tools? NO - keep it in scratch and use the absolute import path; set cwd to ${ROOT}/tools). Decide from what the runtimes print.`
  : `Your method: try to REFUTE each finding. Read the source around the line and its surroundings (guards such as PHP_VERSION_ID checks or method_exists, the class hierarchy, how the value is produced). Ask: does PHP 8.5 really raise the deprecation / change behaviour / fail here, at the level reported? Is the code reachable on 8.5 at all, or is it behind a version check the scanner missed? Is it the internal function/method/constant the rule thinks it is, or a user one with the same name? Where you are unsure, reproduce on the real runtimes as described: import { VERSIONS, startPhp, runFixture } from '${ROOT}/tools/lib/runtimes.mjs' in a scratch .mjs and run it with cwd ${ROOT}/tools.`}

Verdicts: "true-positive" (PHP 8.5 does what the finding says, at that level), "false-positive" (it does not), "wrong-level" (something happens but not what the level says, e.g. reported deprecated but it breaks, or the reverse), "unsure" (say exactly why). Default to "unsure", never to "true-positive", when you could not settle it. If the places show a defect of the rule itself, describe it with a minimal reproduction in rule_defect.`
}

const results = await parallel(
  CASES.flatMap((c) => ['reproduce', 'refute'].map((lens) => () =>
    agent(prompt(c, lens), { label: `${lens}:${c.rule}`, phase: 'Judge', schema: VERDICT })
      .then((v) => v && { lens, ...v })
  ))
)

return results.filter(Boolean)