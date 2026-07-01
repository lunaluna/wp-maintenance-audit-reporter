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
# Bundle Noto Sans JP (Regular + Bold) as static TrueType instances.
#
# mPDF cannot embed CFF/OpenType (postscript) outlines, and Google distributes
# Noto Sans JP as a single variable TTF (no distinct bold weight). So we pin the
# weight axis into static Regular (400) and Bold (700) TrueType instances with
# fontTools. Full glyph coverage is kept — mPDF subsets per generated PDF.
# ---------------------------------------------------------------------------
echo ""
if ! command -v python3 &>/dev/null; then
  echo "Error: python3 not found. Install Python 3 and fonttools (python3 -m pip install fonttools brotli) and retry." >&2
  exit 1
fi
if ! python3 -c "import fontTools" &>/dev/null; then
  echo "Error: fonttools not found. Run: python3 -m pip install fonttools brotli" >&2
  exit 1
fi

echo "Downloading Noto Sans JP variable font ..."
mkdir -p "${TMP_DIR}/fonts"
NOTO_VF="${TMP_DIR}/NotoSansJP-VF.ttf"
curl -fsSL \
  "https://raw.githubusercontent.com/google/fonts/main/ofl/notosansjp/NotoSansJP%5Bwght%5D.ttf" \
  -o "$NOTO_VF"

echo "Instancing static Regular (400) and Bold (700) with fonttools ..."
python3 -m fontTools.varLib.instancer "$NOTO_VF" wght=400 \
  -o "${TMP_DIR}/fonts/NotoSansJP-Regular.ttf"
python3 -m fontTools.varLib.instancer "$NOTO_VF" wght=700 \
  -o "${TMP_DIR}/fonts/NotoSansJP-Bold.ttf"
rm -f "$NOTO_VF"

# ---------------------------------------------------------------------------
# Drop Action Scheduler from the on-demand bundle.
# It is a mandatory library shipped inside the plugin package under lib/
# (see bin/build-zip.sh), so including it here too would double-ship it and
# bloat vendor-pdf.zip. The PDF feature does not depend on it.
# ---------------------------------------------------------------------------
rm -rf "${TMP_DIR}/vendor/woocommerce"

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
