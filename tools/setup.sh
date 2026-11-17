#!/usr/bin/env bash
#
# Sets up a fresh checkout for development and verification.
#
#   bash tools/setup.sh            everything (about 1.5 GB of downloads)
#   bash tools/setup.sh --minimal  only what the tests and fixtures need
#
# Needs: PHP 7.4 or later on the command line, Node.js 20 or later, git, and
# network access to registry.npmjs.org, raw.githubusercontent.com and
# github.com. Everything goes under tools/node_modules and tools/out/, which
# git ignores.
#
# The code bases used to check for false positives are pinned to the commits
# that were checked, so results can be compared with docs/product-design.ja.md.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TOOLS="$ROOT/tools"
OUT="$TOOLS/out"
MINIMAL=0
[ "${1:-}" = "--minimal" ] && MINIMAL=1

echo "== PHP runtimes 7.4 to 8.5 (php-wasm from npm)"
cd "$TOOLS"
# A symbolic link left by the original environment would point nowhere.
[ -L node_modules ] && rm node_modules
npm ci --no-audit --no-fund

echo "== Official upgrade notes"
node fetch-upgrading.mjs

echo "== What each runtime contains (input of build-catalog.mjs)"
node dump-runtimes.mjs

if [ "$MINIMAL" = "1" ]; then
	echo "Minimal setup done. Run: php tests/run.php && (cd tools && node verify-fixtures.mjs)"
	exit 0
fi

# Clones a repository at a pinned commit into tools/out/corpus/<name>.
# Usage: pin <name> <repository> <commit> [sparse path ...]
pin() {
	local name="$1" repo="$2" commit="$3"
	shift 3
	local dir="$OUT/corpus/$name"

	if [ -d "$dir/.git" ] && [ "$(git -C "$dir" rev-parse HEAD)" = "$commit" ]; then
		echo "   $name already at ${commit:0:7}"
		return
	fi

	rm -rf "$dir"
	mkdir -p "$dir"
	git -C "$dir" init -q
	git -C "$dir" remote add origin "https://github.com/$repo"

	if [ "$#" -gt 0 ]; then
		git -C "$dir" sparse-checkout set --no-cone "$@"
	fi

	git -C "$dir" fetch -q --depth 1 --filter=blob:none origin "$commit"
	git -C "$dir" checkout -q FETCH_HEAD
	echo "   $name at ${commit:0:7}"
}

echo "== Code bases for false-positive checks (pinned)"
mkdir -p "$OUT/corpus"
pin wp-latest            WordPress/WordPress               db96f5eaa77a8d3e8eec8923a518fa2326f0d46a
pin wp-4.0               WordPress/WordPress               4.0
pin sqlite-database-integration WordPress/sqlite-database-integration 973810cc16570b9df0be379981bf1c751e157a09
pin plugins/action-scheduler   woocommerce/action-scheduler e63a83ce717a452d059028b90e9736812abeebd4
pin plugins/classic-editor     WordPress/classic-editor bb8d0cc7bfafa7458d90503c44e3c7723cb7324c
pin plugins/contact-form-7     takayukister/contact-form-7 e7a80fdc17afc3d2bbc63d6833567a01d1b9573b
pin plugins/elementor          elementor/elementor 63df482d23ec2b3925f1a3b502b7d6fd2f62934e
pin plugins/performance        WordPress/performance a30ea429eedad998c6c65843e1a07f5ce141548f
pin plugins/two-factor         WordPress/two-factor b8920e7cbbcb08fe39b9e871d7601431b9e77c54
pin plugins/wordpress-importer WordPress/wordpress-importer 2fd6589b4db2e1551ea7826f1ebe2dcc3db9f362
pin plugins/wordpress-seo      Yoast/wordpress-seo 9683c1b75b58097ba6ac715e23d68434bb3ba286
pin plugins/wp-cli             wp-cli/wp-cli 5ec3c05d8b46bb50a6179bc722b97ede35fbd86f
pin plugins/woocommerce        woocommerce/woocommerce 64d3c26a1318b4320af94b414a90c040770a0701 '/plugins/woocommerce/includes/' '/plugins/woocommerce/src/'

echo "== PHP-Parser source (only needed to update lib/php-parser)"
pin php-parser nikic/PHP-Parser v5.9.0

cat <<EOF

Setup done. Typical next steps:

  php tests/run.php
  cd tools && node run-tests.mjs                      # the same tests on PHP 7.4 to 8.5
  cd tools && node verify-fixtures.mjs                # every fixture on every runtime (about 20 minutes)
  cd tools && node wp-check.mjs out/corpus/wp-latest out/corpus/sqlite-database-integration 7.4 8.5
  php bin/scan.php tools/out/corpus/wp-latest --target=8.5 --summary
EOF
