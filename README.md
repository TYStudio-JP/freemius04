# Upgrade Warden

Finds what a newer PHP version will reject, change or warn about in the
plugins and themes of a WordPress site, and fixes what can be fixed safely.
To be sold through Freemius: a free edition that scans and records, a Pro
edition that fixes and keeps watch.

**Status: 1.0.0, ready to register and submit; not released yet.** The plugin
runs in WordPress (Tools > Upgrade Warden): stepwise scan, results per plugin
and theme for PHP 8.0 to 8.6, and a run-time log of what PHP says on the live
site. Pro adds the fixes (preview, apply with backup, undo), a weekly scan
and a scan after updates with e-mails, 90 days of log, and a report. All 602
items of the PHP 8.0-8.6 upgrade notes have a recorded verdict in
`tools/ledger/`; 152 rules, each with a sentence in English and Japanese; 933
fixtures run on seven real PHP versions; 426 messages of PHP the log can
explain. Checked in a real WordPress on PHP 7.4, 8.2 and 8.5, in a browser,
and with Plugin Check (no errors, no warnings).
Not done: the Freemius product and its keys, MySQL, PHP 8.6 on a real runtime.

- **Picking this up elsewhere (Japanese): `docs/HANDOFF.ja.md`. Setup: `bash tools/setup.sh`.**
- From here to selling it (Japanese): `docs/release-guide.ja.md`
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
| `src/Monitor/` | The run-time log. `Capture` listens to PHP's messages, `Signatures` explains them from `data/catalog/messages.php`; both without WordPress calls. `Log` and `Monitor` are the WordPress side. |
| `premium/Fix/` | The fixer (Pro): small text edits at the positions the scanner reports. |
| `data/catalog/` | Internal functions and constants that PHP deprecates or removes, and the messages of PHP the run-time log can explain. **Generated**, do not edit. |
| `vendor/php-parser/` | nikic/PHP-Parser 5.9.0 (BSD-3-Clause) under this plugin's own namespace. **Copied by a script**, do not edit. |
| `tools/` | Development only, never shipped. Runs PHP 7.4 to 8.5 through php-wasm, builds the catalog, checks the fixtures. |
| `tools/fixtures/` | One file per verified claim, each with `<name>.observed.json`, the record of what the runtimes did. |
| `tools/ledger/` | One entry per bullet of the PHP 8.0-8.6 upgrade notes: rule, catalog, run-time only, not detectable or out of scope, with the reason. |
| `src/Scan/`, `src/Admin/`, `src/Store/` | WordPress side: stepwise scan job, stored results, the screen. `Admin/RuleText.php` is **generated** from `tools/catalog-src/rule-text.json`. |
| `premium/` | Pro: the fixer with preview/apply/undo, the automatic checks and e-mails, the report. Removed from the free build by Freemius. |
| `languages/` | The translation template and Japanese. Shipped with Pro only; the free edition is translated on translate.wordpress.org. |
| `vendor/freemius/` | The Freemius SDK. Not in the repository: `composer install --no-dev`, or copy it. |
| `tests/` | PHP tests. They tie the engine to the fixtures. |
| `bin/` | Command-line scripts. |

## Trying it

Without PHP on the command line, put `node tools/php.mjs` where the examples
say `php`: it runs the script on a real PHP 8.3 (`--php=7.4` for another one).

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
node verify-fixtures.mjs     # runs every fixture on every runtime, writes fixtures/*.observed.json
php build-messages.php       # writes data/catalog/messages.php from those records
node verify-monitor.mjs      # runs every fixture again with the run-time log listening
node build-ruletext.mjs      # writes src/Admin/RuleText.php and its Japanese from catalog-src/rule-text.json
node lint-tree.mjs <folder>  # compiles a code base on real PHP without running it
```

`build-catalog` keeps a removal or deprecation only when a runtime shows it
**and** the upgrade notes mention it. Either alone is not enough: two builds of
PHP can differ without PHP having changed.

`catalog-src/` holds the few hand-written inputs. Each must quote the upgrade
notes exactly; the build fails on a quote that is not in the notes.

## Checking in a real WordPress

```
cd tools
node wp-check.mjs <wordpress-release-folder> <sqlite-database-integration-checkout> 7.4 8.5
```

Installs WordPress on SQLite inside each runtime and drives the plugin, built
exactly as a release ships it: about 70 checks covering activation, scan,
results, screens, fix, undo, the run-time log on a page where another plugin
does what PHP 8 refuses, the e-mails, the report, deactivation and deletion.
With `--free` in front of the folders it checks the free edition instead.

## Building

```
node tools/build-zip.mjs     # build/upgrade-warden.zip (Pro, for Freemius) and a preview of the free edition
```

## Adding a rule

See `docs/adding-rules.md` first. In short:

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
