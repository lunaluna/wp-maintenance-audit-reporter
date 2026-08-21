# WPMAR 7機能 E2E 手動確認チェックリスト

Step 0〜7 の自動テスト(PHPUnit)は、`WPMAR_Data_Collector`/`wp_mail`/`wpdb` などを
すべてスタブ・フィクスチャに置き換えたユニットテストである。実際の WordPress 環境
(`test-armfu.local`)・実データベース・実メール送信・実 PDF ライブラリを通した
「本当に動くか」の確認は別物であり、このチェックリストで手動に補う。

`tests/` ディレクトリは `.distignore` で配布 ZIP から除外されるため、この
ファイルが誤ってリリースパッケージに混入することはない。

## 前提

- `wp` / `mysql` を直接呼ばず、必ず `wpcli-local-bootstrap` スキルで
  `{SITE_PATH}/.wpcli-bootstrap` を確認・生成してから実行する。
- **ローカル開発ツリーに対して破壊的なインストール/更新コマンドを実行しない**
  (`wp plugin install --force` 等)。既存の動作中サイトを壊す。
- ログインが必要な確認は既存アカウントを使わず、**WP-CLI で作成した専用の
  検証用ユーザー**で行う。
- **`wp_maybe_auto_update()` を実 cron コンテキスト外で実行しない**
  (このプラグイン自身が自動更新を無効化する設定を持つため、1.5.0 移行時の
  検証で実際に予期しない副作用が発生した)。

## 実行コマンド一覧

| 機能 | コマンド | 期待結果 |
|------|----------|----------|
| ドライラン | `wp maintenance-audit run --dry` | `dry_preview` と `dry_brevity` を含む JSON を返す。`wp maintenance-audit reports` の行数が実行前後で変わらない。 |
| 実行 | `wp maintenance-audit run --no-snapshot`(**既知の不具合**。下記参照。当面は `--no-snapshot` を外した `wp maintenance-audit run` で代替し、スナップショットが保存される前提で確認する) | `report_id` を含む結果を返す。`wp maintenance-audit reports` の行数が1件増える。 |
| スケジュール取得 | `wp cron event list --fields=hook,next_run_gmt \| grep wpmar_run_audit` | 次回実行時刻(UTC)が、設定の day/hour/minute/tz から計算される時刻と一致する。 |
| レポートレビュー | 管理画面のレポート詳細URL(直前の実行結果の `report_url`)を開く | 本文プレビューと、Markdown / PDF(または PDF未導入時は client_md) の各ダウンロードリンクが表示される。 |
| 管理者向けMarkdown | `wp maintenance-audit export <id> --format=markdown` | WordPress本体/テーマ/プラグイン/サーバー関連情報/ユーザー情報/前回差分/運用セキュリティ/実行時間の各セクションが出力される。 |
| クライアント向けPDF | `wp maintenance-audit export <id> --format=pdf --file=/tmp/wpmar-report.pdf` | 日本語を含む本文が正しく描画されたPDFファイルが生成される(`file /tmp/wpmar-report.pdf` で `PDF document` と判定される)。 |
| メール送信 | 管理画面の設定でテスト用の宛先アドレスを1つ指定して `wp maintenance-audit run --no-snapshot` を実行 | 指定した宛先に2通(クライアント向けHTML本文、管理者向けテキスト本文)が届き、両方が正しく読める。 |

`<id>` は「実行」ステップで得た `report_id`、または `wp maintenance-audit reports`
の出力の `id` 列を使う。

## 確認手順

各機能につき、以下の順で1回実施し、実施日と結果をこのファイルの下部に追記する
(コミットはしない — 手元の作業メモとして使う想定)。

1. `wpcli-local-bootstrap` スキルで `.wpcli-bootstrap` を確認する。
2. 上表の「コマンド」を実行する。
3. 「期待結果」欄の内容を目視で確認する。
4. 予期しない挙動(エラー・空の結果・文字化け等)があれば、その場で
   `composer run phpunit` が green のままかを再確認し、ユニットテストでは
   検出できていない実環境固有の問題として扱う(自動テストの不足の可能性を
   検討し、必要ならこのチェックリストまたは対応するテストファイルを更新する)。

## 既知の不具合

- **`wp maintenance-audit run --no-snapshot`(および `wp wpmar audit run --sync --no-snapshot`)は
  一度も正常に動作しない**。docblock に `[--no-snapshot]` のみを宣言しており、対となる正の
  フラグ `[--snapshot]` を宣言していないため、WP-CLI の引数パーサーが `--no-X` を「X という
  正フラグの否定」として解釈しようとして `Error: unknown --snapshot parameter` で即エラー
  終了する(`includes/cli/class-wpmar-cli-command.php:35,67`、
  `includes/cli/class-wpmar-cli-audit-command.php:43,64`)。成功する CLI 実行は必ず
  `persist_snapshots => true` になり、CLI からスナップショット保存をスキップする手段が
  実質存在しない。修正方針は別セッションでプランをまとめる(旧 `wp maintenance-audit` を
  新 `wp wpmar audit` へ統合してから対応する方針。詳細はメモリ
  `wpmar-cli-no-snapshot-bug-and-command-consolidation` を参照)。

## 作成時に実施した動作確認(2026-08-21)

このチェックリスト作成時に、WP-CLI で確認できる項目を `test-armfu.local` 上で実際に
1回通した(レポートレビュー・メール送信はブラウザ/メールボックスでの目視確認が前提のため、
今回はコマンドの実行は行っていない)。

- ドライラン: 済み(実装セッション冒頭、Step 1 のフィクスチャ作成時に実施)。
- 実行: `--no-snapshot` は前述の既知の不具合で失敗。`wp maintenance-audit run` (`--no-snapshot`なし)で実行し、`report_id: 24` を確認。`wp maintenance-audit reports` の行数が増えることも確認。
- スケジュール取得: `wp cron event list --fields=hook,next_run_gmt` で `wpmar_run_audit` の次回時刻が `2026-08-24 17:00:00`(UTC)であることを確認。設定は `day=25, hour=2, minute=0, tz=Asia/Tokyo` — UTC 17:00 は JST 翌日2:00 と一致。
- 管理者向けMarkdown: `wp maintenance-audit export 24 --format=markdown` で期待した全セクション見出しが出力されることを確認。
- クライアント向けPDF: `wp maintenance-audit export 24 --format=pdf --file=/tmp/wpmar-e2e-report.pdf` で `%PDF-1.4` から始まる2ページのPDFが生成されることを確認(確認後に一時ファイルは削除)。

## ローカル環境での CI 相当確認

各 Step 完了時・全 Step 完了後に以下を実行する(1.5.0 リリース前の最終確認としても
同じコマンドを再実行する):

```bash
cd /Users/mkgq3lla/private/test-armfu/test-armfu.local/app/public/wp-content/plugins/wp-maintenance-audit-reporter
composer run phpunit          # 新規 + 既存すべてのテストが green であること
composer run phpcs            # tests/ は除外対象。本体変更ファイルのみが対象
git branch --show-current     # コミット直前に必ず確認(CLAUDE.md 絶対ルール)
```
