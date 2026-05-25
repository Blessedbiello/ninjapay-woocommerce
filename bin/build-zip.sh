#!/usr/bin/env bash
#
# Build a distributable plugin zip for WP.org / GitHub releases.
#
# Produces dist/ninjapay-woocommerce-<version>.zip containing only the
# runtime files (main file, src/, production autoloader, readme, LICENSE,
# languages) — no tests, CI, docs, or dev tooling.
#
set -euo pipefail

SLUG="ninjapay-woocommerce"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

VERSION="$(grep -m1 'Stable tag:' readme.txt | sed 's/.*:[[:space:]]*//')"
if [ -z "${VERSION}" ]; then
  echo "error: could not read 'Stable tag:' from readme.txt" >&2
  exit 1
fi

# Generate an optimized, production (no-dev) autoloader. The plugin has
# no runtime Composer deps, so this is just its own PSR-4 classmap — and
# dump-autoload runs offline. Falls back to the existing vendor/ if no
# composer binary is present.
if command -v composer >/dev/null 2>&1; then
  composer dump-autoload --no-dev --optimize --classmap-authoritative >/dev/null
elif [ -f composer.phar ]; then
  php composer.phar dump-autoload --no-dev --optimize --classmap-authoritative >/dev/null
fi
if [ ! -f vendor/autoload.php ]; then
  echo "error: vendor/autoload.php missing — run 'composer dump-autoload' first" >&2
  exit 1
fi

BUILD_DIR="$(mktemp -d)"
DEST="${BUILD_DIR}/${SLUG}"
mkdir -p "${DEST}"

cp "${SLUG}.php" readme.txt LICENSE "${DEST}/"
cp -r src "${DEST}/"
cp -r vendor "${DEST}/"
[ -d languages ] && cp -r languages "${DEST}/"

mkdir -p dist
ZIP="${ROOT}/dist/${SLUG}-${VERSION}.zip"
rm -f "${ZIP}"
( cd "${BUILD_DIR}" && zip -rq "${ZIP}" "${SLUG}" )
rm -rf "${BUILD_DIR}"

echo "Built ${ZIP}"
