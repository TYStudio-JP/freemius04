# Upgrade Warden (working title)

Finds what a newer PHP version will reject, change or warn about in the
plugins and themes of a WordPress site, and fixes what can be fixed safely.
To be sold through Freemius: a free edition that scans, a Pro edition that
fixes.

**Status: engine only.** There is no WordPress plugin yet: no admin screen, no
database, no Freemius integration. What exists is the scan engine, the fixer,
the tooling that checks every claim on real PHP runtimes, and command-line
scripts to try them on real code.

- Why this product, and the evidence for and against (Japanese): `docs/market-research.ja.md`
- Design, current state, limits and open decisions (Japanese): `docs/product-design.ja.md`

## The rule this repository is built around

A statement such as "deprecated in 8.4, a compile error from 8.0" enters the
product only after real PHP runtimes showed it. A fix is offered only after the
fixed program was run on those runtimes and printed what the original printed.
What could not be run (PHP 8.6 for now) is taken from the official upgrade
notes, quoted word for word, and is labelled as such all the way to the result.

## Layout

| Path | Contents |
|---|---|
| `src/Engine/` | The scanner. Plain PHP with no WordPress calls. `Scanner` parses a file, `Walker` tracks guards and loops, `Rules/` hold one kind of finding each, `Finding` knows its level for any target version. |
| `premium/Fix/` | The fixer (Pro): small text edits at the positions the scanner reports. |
| `data/catalog/` | Internal functions and constants that PHP deprecates or removes. **Generated**, do not edit. |
| `lib/php-parser/` | nikic/PHP-Parser 5.9.0 (BSD-3-Clause) under this plugin's own namespace. **Copied by a script**, do not edit. |
| `tools/` | Development only, never shipped. Runs PHP 7.4 to 8.5 through php-wasm, builds the catalog, checks the fixtures. |
| `tools/fixtures/` | One file per verified claim, and `observed.json`, the record of what the runtimes did. |
| `tests/` | PHP tests. They tie the engine to the fixtures. |
| `bin/` | Command-line scripts. |

## Trying it

```
php bin/scan.php path/to/plugin --target=8.5          # every finding
php bin/scan.php path/to/plugin --target=8.6 --summary
php bin/fix.php  path/to/plugin                        # what would be fixed
php bin/fix.php  path/to/plugin --write                # fix in place; review with git diff
```

## Tests

```
php tests/run.php            # on the local PHP (7.4 or later)
cd tools && npm ci
node run-tests.mjs           # the same tests on real PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4 and 8.5
```

## The verification tooling

```
cd tools && npm ci
node fetch-upgrading.mjs     # official UPGRADING notes of PHP 7.0 to 8.6, into out/
node dump-runtimes.mjs       # what each runtime contains, into out/
node build-catalog.mjs       # writes data/catalog/, lists what needs review
node verify-fixtures.mjs     # runs every fixture on every runtime, writes fixtures/observed.json
node lint-tree.mjs <folder>  # compiles a code base on real PHP without running it
```

`build-catalog` keeps a removal or deprecation only when a runtime shows it
**and** the upgrade notes mention it. Either alone is not enough: two builds of
PHP can differ without PHP having changed.

`catalog-src/` holds the few hand-written inputs. Each must quote the upgrade
notes exactly; the build fails on a quote that is not in the notes.

## Adding a rule

1. Write a fixture in `tools/fixtures/`: the claim, a small program that uses
   the feature and prints something, and, if there is a fix, the fixed program
   (`php bin/fixture-fixed.php <fixture>` prints what the fixer produces).
2. `node verify-fixtures.mjs`. If the claim does not hold, the claim is wrong.
3. Write the rule in `src/Engine/Rules/` and add it to `Scanner::default_rules()`.
4. `php tests/run.php`. The tests refuse a rule without a verified fixture, a
   scanner that states other versions than the fixture, and a fixer whose
   output differs from the fixed program that was run.

## Updating PHP-Parser

```
git clone --depth 1 --branch <tag> https://github.com/nikic/PHP-Parser /tmp/PHP-Parser
php bin/vendor-php-parser.php /tmp/PHP-Parser <tag>
```

The script moves every class to `TYStudio\UpgradeWarden\Vendor\PhpParser` and
fails if a reference to the original namespace is left.
