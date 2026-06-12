#!/usr/bin/env bash
# bin/build-vendor-pdf-zip.sh
#
# Creates vendor-pdf.zip for upload to GitHub Releases.
# The zip contains only production Composer dependencies (no dev tools).
# The WordPress admin can then download and extract it on-demand via the
# "PDF ライブラリをインストール" button in the plugin settings.
#
# Usage (from the plugin root directory):
#   bash bin/build-vendor-pdf-zip.sh

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_FILE="${PLUGIN_DIR}/wp-maintenance-audit-reporter.php"
OUT_ZIP="${PLUGIN_DIR}/vendor-pdf.zip"
TMP_DIR="$(mktemp -d)"
# Resolve symlinks (macOS /var/folders → /private/var/folders) so that zip
# can traverse the directory without "No such file or directory" warnings.
TMP_DIR="$(realpath "$TMP_DIR")"

cleanup() { rm -rf "$TMP_DIR"; }
trap cleanup EXIT

# ---------------------------------------------------------------------------
# Resolve version from plugin header
# ---------------------------------------------------------------------------
if [ ! -f "$PLUGIN_FILE" ]; then
  echo "Error: $(basename "$PLUGIN_FILE") not found. Run from the plugin root." >&2
  exit 1
fi

VERSION="$(grep -E '^\s*\*\s*Version:' "$PLUGIN_FILE" \
  | head -n1 \
  | sed -E 's/.*Version:[[:space:]]*//' \
  | tr -d '\r')"

if [ -z "$VERSION" ]; then
  echo "Error: Could not read Version from plugin header." >&2
  exit 1
fi

echo "Building vendor-pdf.zip  (plugin v${VERSION}, production deps only)"
echo "Output: ${OUT_ZIP}"
echo ""

# ---------------------------------------------------------------------------
# Install production deps into a temp directory
# ---------------------------------------------------------------------------
if ! command -v composer &>/dev/null; then
  echo "Error: composer not found. Install Composer and retry." >&2
  exit 1
fi

cp "${PLUGIN_DIR}/composer.json" "${TMP_DIR}/"
[ -f "${PLUGIN_DIR}/composer.lock" ] && cp "${PLUGIN_DIR}/composer.lock" "${TMP_DIR}/"

echo "Running composer install --no-dev ..."
composer install \
  --working-dir="$TMP_DIR" \
  --no-dev \
  --prefer-dist \
  --optimize-autoloader \
  --no-interaction \
  --no-progress

# ---------------------------------------------------------------------------
# Download Noto Sans JP fonts (Regular + Bold)
# ---------------------------------------------------------------------------
echo ""
echo "Downloading Noto Sans JP font ..."
mkdir -p "${TMP_DIR}/fonts"
curl -fsSL \
  "https://raw.githubusercontent.com/google/fonts/main/ofl/notosansjp/NotoSansJP%5Bwght%5D.ttf" \
  -o "${TMP_DIR}/fonts/NotoSansJP.ttf"

# ---------------------------------------------------------------------------
# Package vendor/ and fonts/ into a zip
# Both sit at the zip root so ZipArchive::extractTo(PLUGIN_DIR) is enough.
# -X suppresses macOS resource-fork entries (__MACOSX/).
# ---------------------------------------------------------------------------
echo ""
echo "Creating zip ..."
rm -f "$OUT_ZIP"
(cd "$TMP_DIR" && zip -rq -X "$OUT_ZIP" vendor/ fonts/)

SIZE="$(du -sh "$OUT_ZIP" | cut -f1)"
echo "Done: ${OUT_ZIP}  (${SIZE})"
echo ""
echo "Upload to the GitHub Release:"
echo "  gh release upload v${VERSION} \"${OUT_ZIP}#vendor-pdf.zip\""
