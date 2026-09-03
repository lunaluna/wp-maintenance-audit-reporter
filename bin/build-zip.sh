#!/usr/bin/env bash
# 配布用 ZIP をビルドする.
#
# 実処理は同梱ライブラリの汎用ビルダーに委譲する。除外定義はプラグインルートの
# .distignore が単一の正であり、release.yml が呼ぶ build-zip composite action も
# このスクリプトを経由して同じ .distignore を読む.
#
# WPMAR 固有の前処理は 2 つに分かれている:
#   bin/build-zip.pre.sh — 汎用ビルダーがステージング前に呼ぶ。ZIP に同梱する
#                          もの(Action Scheduler)を用意する
#   bin/release.pre.sh   — reusable workflow が build-zip の前に呼ぶ。Release に
#                          添付する追加アセット(vendor-pdf.zip 等)を作る
#
# 呼び出し先はディレクトリを移動しないため、プラグインルートで実行すること.
# 生成物はプラグインルートに書かれる(従来は 1 つ上だった).
set -euo pipefail

bash lib/l2d-updater/bin/build-zip.sh
