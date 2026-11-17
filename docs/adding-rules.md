# Adding rules: the working agreement

Read this before adding a rule. It is short on purpose; the code is the detail.

## The one rule

Nothing about a PHP version enters the product unless a real runtime showed it.
If your memory, the PHP manual or the upgrade notes say "deprecated in 8.1" and
the runtime disagrees, the runtime is right and the claim changes.

Runtimes available: PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5 (php-wasm). There is
no 8.6 runtime. A claim about 8.6 can only be `"evidence": "documented"`, must
quote the 8.6 notes word for word, and must say so in the finding.

## Files you may create (never edit files that already exist)

| What | Where |
|---|---|
| Fixture | `tools/fixtures/<rule.id>[~case].fixture` |
| Rule | `src/Engine/Rules/<Name>.php`, one class implementing `Rules\Rule`. It is picked up automatically. |
| Tests for what must NOT be reported | `tests/Unit/<Name>Test.php`, returning `array( 'name' => function () {...} )` |
| Ledger of the notes you covered | `tools/ledger/<version>[-part].json` |

Do not edit `Scanner.php`, `Walker.php`, `Context.php`, `Finding.php`, existing
rules, existing fixtures, existing tests or anything in `data/`, `lib/`,
`docs/`. Several people work in this tree at once. If you believe a shared
file needs a change, say so in your report instead.

Do not run `git add`, `git commit` or any other git command that changes state.

## Fixture format

```
--RULE--
syntax.example
--CLAIM--
{ "deprecated_in": "8.1", "changed_in": null, "breaks_in": "9.0" }
--CODE--
<?php
... a small program that uses the feature and prints something ...
--FIXED--
<?php
... optional: the same program after the automatic fix ...
--SCAN--
{ "guarded": true }     optional, see tools/verify-fixtures.mjs
```

- Omit claim keys that do not apply. `{}` means: nothing happens on any version.
- `breaks_in`: first version where the code cannot run (parse or fatal error).
- `deprecated_in`: first version raising E_DEPRECATED. `changed_in`: first
  version where it runs, silently, with different output.
- A claim older than 7.4 (for example `"deprecated_in": "7.2"`) is accepted
  only if the 7.x upgrade notes say so; otherwise claim `"7.4"`.
- The program must be deterministic, must not touch the network or the shell,
  and must print the same thing on every version unless `changed_in` says not.
- One behaviour per fixture. Four kinds of cast are four fixtures.

Check it:

```
cd tools && node verify-fixtures.mjs <part-of-the-file-name>
```

It prints what each version did and writes `<fixture>.observed.json`. A
`MISMATCH` means the claim is wrong: change the claim, not the checker.

## Rule

Look at `src/Engine/Rules/NestedTernary.php` (syntax), `Parameters.php`
(declarations) and `InternalFunctions.php` (calls, guards) first.

- `node_types()` lists exact node classes; `inspect()` gets those nodes.
- `$context->report( 'rule.id', $node, array( 'deprecated' => '8.1', 'breaks' => null, 'changed' => null ) )`
  returns the `Finding`; set `subject`, `instead`, `evidence`, `guarded`.
  Write the rule id as a plain string literal directly inside `report(`.
- Every node has a `parent` attribute: `$node->getAttribute( 'parent' )`.
- Names are resolved: see `InternalFunctions::global_name()` for how to tell a
  global function from a namespaced one.
- Things decided at run time (calls, constants) can be guarded:
  `$context->function_is_guarded( $name )`. Things decided at compile time
  (syntax, declarations) cannot.
- The versions in the rule must equal the versions in its fixtures.
- Precision over recall. If you cannot tell from the syntax tree, do not
  report. A wrong "this breaks" costs more trust than a missed deprecation.
  When only part of a change is detectable, detect that part and record the
  rest in the ledger.

A fix (`$finding->fix`) is allowed only with a `--FIXED--` section that the
verifier accepted, and only using the existing fix kinds in
`premium/Fix/Fixer.php` (`replace`, `insert`, `wrap`). Otherwise report without
a fix.

## Tests

```
php tests/run.php <part-of-a-test-name>
```

`FixturesTest` checks your fixtures against your rule automatically. Add your
own test file for code that looks similar and must stay unreported. Try your
rule on real code as well:

```
php bin/scan.php tools/out/corpus/wp-latest --target=8.5 --summary
```

WordPress 7.1.4 supports PHP 8.5: a finding of level `error` or `changed`
there is almost certainly a false positive, and you must look at the source
line and settle it.

## Ledger

One JSON file, an array with one object per bullet of the notes you were given,
in the order of the notes:

```json
{
	"version": "8.1",
	"section": "Backward Incompatible Changes",
	"area": "Core",
	"quote": "first sentence of the bullet, copied exactly",
	"disposition": "rule | catalog | runtime-only | not-detectable | out-of-scope",
	"rule": "rule.id or null",
	"fixtures": ["file names"],
	"reason": "one sentence: why this disposition"
}
```

- `rule`: you wrote a rule, or an existing rule covers it.
- `catalog`: `data/catalog/` already covers it (a removed or deprecated function or constant).
- `runtime-only`: real, but depends on values, not on how the code is written
  (for example passing null to a parameter). Add a fixture that demonstrates it
  with rule id `runtime.<name>` and no rule class; the tests skip `runtime.*`.
- `not-detectable`: cannot be told apart reliably from correct code.
- `out-of-scope`: SAPI, build, Windows, INI, or an extension none of the
  runtimes has.

Every bullet gets an entry. Skipping one silently is the only way to fail this.
