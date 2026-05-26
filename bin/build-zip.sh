#!/usr/bin/env bash
# bin/build-zip.sh
#
# Builds a production-ready distribution zip identical to the one produced by
# .github/workflows/release.yml.
#
# Usage (from the plugin root directory):
#   bash bin/build-zip.sh
#
# The zip is written to the plugin root as:
#   wp-maintenance-audit-reporter.<version>.zip

set -euo pipefail

SLUG="wp-maintenance-audit-reporter"
PLUGIN_FILE="${SLUG}.php"

# ---------------------------------------------------------------------------
# 1. Resolve version from plugin header
# ---------------------------------------------------------------------------
if [ ! -f "$PLUGIN_FILE" ]; then
  echo "Error: $PLUGIN_FILE not found. Run this script from the plugin root." >&2
  exit 1
fi

VERSION=$(grep -E '^\s*\*\s*Version:' "$PLUGIN_FILE" \
  | head -n1 \
  | sed -E 's/.*Version:[[:space:]]*//' \
  | tr -d '\r')

if [ -z "$VERSION" ]; then
  echo "Error: Could not read Version from $PLUGIN_FILE." >&2
  exit 1
fi

echo "Building: ${SLUG} v${VERSION}"

# ---------------------------------------------------------------------------
# 2. Install production-only Composer dependencies
# ---------------------------------------------------------------------------
if command -v composer &>/dev/null; then
  echo "Running composer install --no-dev ..."
  composer install --no-dev --prefer-dist --no-progress --optimize-autoloader
else
  echo "Warning: composer not found. Skipping dependency install." >&2
fi

# ---------------------------------------------------------------------------
# 3. Stage files into a temp directory replicating the zip structure
# ---------------------------------------------------------------------------
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "${STAGE}/${SLUG}"

rsync -a \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='.gitignore' \
  --exclude='.gitattributes' \
  --exclude='.phpunit.result.cache' \
  --exclude='phpunit.xml.dist' \
  --exclude='phpcs.xml.dist' \
  --exclude='tests' \
  --exclude='node_modules' \
  --exclude='bin' \
  ./ "${STAGE}/${SLUG}/"

# ---------------------------------------------------------------------------
# 4. Create the zip
# ---------------------------------------------------------------------------
ZIP_NAME="${SLUG}.${VERSION}.zip"
ZIP_PATH="$(dirname "$(pwd)")/${ZIP_NAME}"

(cd "$STAGE" && zip -rq "$ZIP_NAME" "$SLUG")
mv "${STAGE}/${ZIP_NAME}" "$ZIP_PATH"

echo "Done: ${ZIP_PATH}"
