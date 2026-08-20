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
#
# Excludes come from .distignore (the single source of truth shared with
# release.yml), not a list duplicated in this script. rsync --exclude-from's
# handling of comment lines (`#`) is unverified, so comments/blanks are
# stripped here before rsync ever sees the file.
# ---------------------------------------------------------------------------
if [ ! -f ".distignore" ]; then
  echo "Error: .distignore not found. Run this script from the plugin root." >&2
  exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "${STAGE}/${SLUG}"

grep -vE '^[[:space:]]*(#|$)' .distignore > "${STAGE}/excludes.txt"

rsync -a --exclude-from="${STAGE}/excludes.txt" ./ "${STAGE}/${SLUG}/"

# ---------------------------------------------------------------------------
# 3b. Bundle mandatory libraries into lib/ (Action Scheduler).
#
# vendor/ is excluded from the zip (mPDF and its deps ship on-demand), so any
# library required from first activation must be copied into lib/ explicitly.
# Action Scheduler is self-contained (its own bootstrap loads its classes), so
# the single package directory is all that needs shipping.
# ---------------------------------------------------------------------------
AS_SRC="vendor/woocommerce/action-scheduler"
AS_DEST="${STAGE}/${SLUG}/lib/action-scheduler"
if [ -d "$AS_SRC" ]; then
  mkdir -p "$AS_DEST"
  rsync -a \
    --exclude='.git' \
    --exclude='.github' \
    --exclude='.claude' \
    --exclude='tests' \
    --exclude='docs' \
    "$AS_SRC/" "$AS_DEST/"
  echo "Bundled Action Scheduler -> lib/action-scheduler"
else
  echo "Error: $AS_SRC not found. Run 'composer install' so Action Scheduler can be bundled into lib/." >&2
  exit 1
fi

# ---------------------------------------------------------------------------
# 3c. Guard: lib/l2d-updater/ must be present in the staged tree.
#
# lib/l2d-updater/ はメインファイルが require するため、欠落するとプラグインが
# 起動時に fatal になる。Action Scheduler と同様に hard fail させる。
# ---------------------------------------------------------------------------
for required in loader.php class-l2d-github-updater.php; do
  if [ ! -f "${STAGE}/${SLUG}/lib/l2d-updater/${required}" ]; then
    echo "Error: lib/l2d-updater/${required} is missing from the staged tree." >&2
    exit 1
  fi
done

# ---------------------------------------------------------------------------
# 4. Create the zip
# ---------------------------------------------------------------------------
ZIP_NAME="${SLUG}.${VERSION}.zip"
ZIP_PATH="$(dirname "$(pwd)")/${ZIP_NAME}"

(cd "$STAGE" && zip -rq "$ZIP_NAME" "$SLUG")
mv "${STAGE}/${ZIP_NAME}" "$ZIP_PATH"

echo "Done: ${ZIP_PATH}"
