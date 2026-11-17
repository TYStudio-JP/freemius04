#!/usr/bin/env bash
# Builds a minimal PHP 8.6 CLI from the official php-src tag, for running
# verification fixtures. Nothing is installed system-wide except build deps.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SP="$ROOT/tools/out"
TAG="${1:-php-8.6.0RC7}"
PREFIX="$SP/php-native/$TAG"

export DEBIAN_FRONTEND=noninteractive
apt-get install -y -qq re2c libxml2-dev libsqlite3-dev libonig-dev zlib1g-dev >/dev/null

mkdir -p "$SP/php-src-build"
cd "$SP/php-src-build"
if [ ! -d "$TAG" ]; then
	git clone --quiet --depth 1 --branch "$TAG" https://github.com/php/php-src "$TAG"
fi
cd "$TAG"

./buildconf --force >/dev/null
./configure --quiet \
	--prefix="$PREFIX" \
	--disable-all \
	--enable-cli \
	--disable-cgi \
	--disable-phpdbg \
	--enable-tokenizer \
	--enable-ctype \
	--enable-mbstring \
	--enable-filter \
	--enable-session \
	--enable-xml \
	--enable-dom \
	--enable-simplexml \
	--with-libxml \
	--enable-mysqlnd \
	--with-mysqli=mysqlnd \
	--with-zlib \
	>/dev/null

make -j"$(nproc)" >/dev/null 2>"$SP/php-src-build/$TAG.make.err" || { tail -30 "$SP/php-src-build/$TAG.make.err"; exit 1; }
make install >/dev/null

"$PREFIX/bin/php" -v
"$PREFIX/bin/php" -m | tr '\n' ' '
echo
