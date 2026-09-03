#!/usr/bin/env bash
# reusable workflow(plugin-release.yml)が build-zip の前に実行する WPMAR 固有の
# リリース前処理フック.
#
# 生成するもの:
#   fonts/NotoSansJP-{Regular,Bold}.ttf — mPDF 用の静的インスタンス
#   vendor-pdf.zip                      — オンデマンド配布する PDF ライブラリ本体
#   vendor-pdf.zip.sha256               — Release アセットとして公開する SHA-256
#   vendor-pdf.sha256                   — 配布 ZIP に同梱する SHA-256(名前が別なのは意図的)
#
# vendor-pdf.zip / vendor-pdf.zip.sha256 は release.yml の extra_assets で
# Release に添付する。vendor-pdf.sha256 は .distignore で除外していないため
# 配布 ZIP に同梱され、既定でチェックサム検証が働く.
set -euo pipefail

# 本番依存のみをインストールする(vendor-pdf.zip の中身).
composer install --no-dev --prefer-dist --no-progress --optimize-autoloader

# mPDF は CFF/OpenType アウトラインを埋め込めず、Noto Sans JP は可変フォント 1 本
# (太字の別インスタンスが無い)で配布されている。fontTools で wght 軸を固定した
# 静的な Regular(400) / Bold(700) の TrueType を作る.
python3 -m pip install --quiet fonttools brotli
mkdir -p fonts
curl -fsSL \
  "https://raw.githubusercontent.com/google/fonts/main/ofl/notosansjp/NotoSansJP%5Bwght%5D.ttf" \
  -o /tmp/NotoSansJP-VF.ttf
# --update-name-table は STAT から各インスタンスの命名・Bold ビットを整える
# (ファミリ "Noto Sans JP"、Regular/Bold のサブファミリ、別個の PS 名).
python3 -m fontTools.varLib.instancer --update-name-table /tmp/NotoSansJP-VF.ttf wght=400 \
  -o fonts/NotoSansJP-Regular.ttf
python3 -m fontTools.varLib.instancer --update-name-table /tmp/NotoSansJP-VF.ttf wght=700 \
  -o fonts/NotoSansJP-Bold.ttf
rm -f /tmp/NotoSansJP-VF.ttf

zip -rq -X vendor-pdf.zip vendor/ fonts/

SHA="$(shasum -a 256 vendor-pdf.zip | awk '{print $1}')"
echo "$SHA" > vendor-pdf.zip.sha256
echo "$SHA" > vendor-pdf.sha256
echo "vendor-pdf.zip SHA-256: $SHA"
