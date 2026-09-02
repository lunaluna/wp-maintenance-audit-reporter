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
| ドライラン | `wp wpmar audit run --dry-run` | `dry_preview` と `dry_brevity` を含む JSON を返す。`wp wpmar report list` の行数が実行前後で変わらない。 |
| 実行 | `wp wpmar audit run --skip-snapshot` | `report_id` を含む結果を返す。`wp wpmar report list` の行数が1件増える。`wp_wpmar_snapshots` の行数は増えない。 |
| スケジュール取得 | `wp cron event list --fields=hook,next_run_gmt \| grep wpmar_run_audit` | 次回実行時刻(UTC)が、設定の day/hour/minute/tz から計算される時刻と一致する。 |
| レポートレビュー | 管理画面のレポート詳細URL(直前の実行結果の `report_url`)を開く | 本文プレビューと、Markdown / PDF(または PDF未導入時は client_md) の各ダウンロードリンクが表示される。 |
| 管理者向けMarkdown | `wp wpmar report export <id> --format=markdown` | WordPress本体/テーマ/プラグイン/サーバー関連情報/ユーザー情報/前回差分/運用セキュリティ/実行時間の各セクションが出力される。 |
| クライアント向けPDF | `wp wpmar report export <id> --format=pdf --file=/tmp/wpmar-report.pdf` | 日本語を含む本文が正しく描画されたPDFファイルが生成される(`file /tmp/wpmar-report.pdf` で `PDF document` と判定される)。 |
| メール送信 | 管理画面の設定でテスト用の宛先アドレスを1つ指定して `wp wpmar audit run --skip-snapshot` を実行 | 指定した宛先に2通(クライアント向けHTML本文、管理者向けテキスト本文)が届き、両方が正しく読める。 |
| スナップショットプレビュー(1.5.3) | 管理画面「システム機能」画面の「実行履歴」の下にある「スナップショットをプレビュー」ボタンを押す | コア・テーマ・プラグイン・ユーザーそれぞれ直近2世代分のスナップショットが Markdown 整形で `<pre>` 内に展開される。`wp db query "SELECT id, snapshot_type, captured_at FROM wp_wpmar_snapshots ORDER BY id"` の内容と一致する。「閉じる」でURLパラメータが消え、再度未展開状態に戻る。 |

`<id>` は「実行」ステップで得た `report_id`、または `wp wpmar report list`
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

## 既知の不具合(1.5.1 で修正済み)

- 旧 `--no-snapshot` は WP-CLI の `--no-X` 否定解釈と衝突し一度も正常に動作しなかった
  (不具合A)。1.5.1 で正のフラグ `--skip-snapshot` に改名し解消した。
- `wp wpmar storage migrate --no-revert` は `isset()` による読み取りのため `revert => true`
  と誤読され、`--dry-run` を外すと全ファイルを巻き戻す破壊的誤爆になっていた(不具合B)。
  1.5.1 で `WPMAR_CLI_Flags::bool()` 経由の読み取りに統一し解消した。
  詳細はメモリ `wpmar-cli-no-snapshot-bug-and-command-consolidation` を参照。

## 作成時に実施した動作確認(2026-08-21)

このチェックリスト作成時に、WP-CLI で確認できる項目を `test-armfu.local` 上で実際に
1回通した(レポートレビュー・メール送信はブラウザ/メールボックスでの目視確認が前提のため、
今回はコマンドの実行は行っていない)。

- ドライラン: 済み(実装セッション冒頭、Step 1 のフィクスチャ作成時に実施)。
- 実行: `--no-snapshot` は前述の既知の不具合で失敗。`wp maintenance-audit run` (`--no-snapshot`なし)で実行し、`report_id: 24` を確認。`wp maintenance-audit reports` の行数が増えることも確認。
- スケジュール取得: `wp cron event list --fields=hook,next_run_gmt` で `wpmar_run_audit` の次回時刻が `2026-08-24 17:00:00`(UTC)であることを確認。設定は `day=25, hour=2, minute=0, tz=Asia/Tokyo` — UTC 17:00 は JST 翌日2:00 と一致。
- 管理者向けMarkdown: `wp maintenance-audit export 24 --format=markdown` で期待した全セクション見出しが出力されることを確認。
- クライアント向けPDF: `wp maintenance-audit export 24 --format=pdf --file=/tmp/wpmar-e2e-report.pdf` で `%PDF-1.4` から始まる2ページのPDFが生成されることを確認(確認後に一時ファイルは削除)。

## マルチサイト確認

`test-armfu.local` は単一サイト構成のため、マルチサイト固有の挙動(`switch_to_blog()`
経由の権限境界・サイト分離)は実マルチサイト環境で確認する。検証環境は
`/Users/mkgq3lla/works/mgn/alpine-dealer/alpine-dealer.local`(実案件・20サイト、
`blog_id 2` が欠番)を使う。手順・注意点は
`~/.claude/projects/-Users-mkgq3lla-private-test-armfu-test-armfu-local/memory/`
配下の `wpmar-1.5.2-multisite-verification-alpine-dealer.md` /
`wpmar-1.5.3-multisite-verification-alpine-dealer.md` を参照(検証ツリーを
`wp-content/plugins/` の外に退避してから差し替える、バックアップを `plugins/`
配下にリネームで残さない、等)。

この環境は HTTPS 強制かつ Local の自己署名証明書のため、Playwright は
`ERR_CERT_AUTHORITY_INVALID` で使えない。ブラウザ操作の代わりに、
`wp eval-file` + `wp_set_current_user()` + `ob_start()` で対象クラスの
public メソッドを直接呼び、実 DB・実 `switch_to_blog()` に対して検証する。

| # | 確認項目 | 手順 | 期待結果 |
|---|---|---|---|
| 1 | サブサイト管理者に非表示 | `manage_options` はあるがスーパー管理者でないユーザーで、サブサイトの「システム機能」画面のスナップショットセクションを呼ぶ | 出力0バイト(セクション自体が出ない) |
| 2 | スーパー管理者・1世代のみのサイト | スーパー管理者で、監査が1回しか走っていないサブサイトを展開 | DB(`wp_{blog_id}_wpmar_snapshots`)の内容と一致。「前回のスナップショットはまだありません」が出て `### 前回` は出ない |
| 3 | スーパー管理者・2世代あるサイト | スーパー管理者で、2世代あるサイト(通常メインサイト)を展開 | 「最新」→「前回」の順で正しく表示される |
| 4 | 集約監査ONの補足文言 | 集約監査が有効な状態で、メインサイトとサブサイトそれぞれの画面を確認 | サブサイトのみ「このサイトからは監査を実行できませんが…」の注記が出る |
| 5 | ネットワーク横断ビュー・未選択時 | ネットワーク管理画面「システム機能」を未選択のまま開く | `<pre>` が出ない(DB未読み込み) |
| 6 | 欠番/除外 blog_id の直接指定 | URL の `wpmar_snapshot_blog` に欠番(例 `2`)または除外設定した blog_id を直接指定 | 選択済みとして扱われず、ピッカー表示に戻る |
| 7 | サイト切り替えでのリーク無し | 有効な blog_id を2つ以上切り替えて選択 | 毎回そのサイトのテーブル内容のみが表示され、前に選んだサイトの内容が残らない |
| 8 | サイト選択肢の組み立て | サイト picker の `<option>` を確認 | `blog_id => "サイト名（blog_id N）"` の組で構築されている |
| 9 | ネットワーク管理画面へのアクセス制御 | サブサイト管理者でネットワーク管理画面「システム機能」を開く | 既存のケイパビリティガードで到達不可(画面自体が出ない) |
| 10 | PHP エラーログ | 検証前後で `wp-content/wpmar-private/site-1/logs/` や PHP エラーログを確認 | 新規のエラー・警告・通知が増えていない |

**未検証(既知)**: `table_exists()` が偽の場合(「このサイトにはスナップショットのテーブルが
まだ作成されていません。」)の実機確認。この環境は全20サイトに既に
`wpmar_snapshots` テーブルが存在し、新規サイト作成も `wp_initialize_site` フックで
即座にテーブルが作られるため、自然な「テーブル無し」状態を作れなかった。
`tests/SnapshotPreviewNetworkTest.php::test_markdown_for_repository_reports_missing_table`
でユニットテストのみ担保。

**2026-09-02 実施結果**: 上記1〜10すべて合格。詳細・遭遇した副次的事象は
`wpmar-1.5.3-multisite-verification-alpine-dealer.md`(メモリ)を参照。

## ローカル環境での CI 相当確認

各 Step 完了時・全 Step 完了後に以下を実行する(リリース前の最終確認としても
同じコマンドを再実行する):

```bash
cd /Users/mkgq3lla/private/test-armfu/test-armfu.local/app/public/wp-content/plugins/wp-maintenance-audit-reporter
composer run phpunit          # 新規 + 既存すべてのテストが green であること
composer run phpcs            # tests/ は除外対象。本体変更ファイルのみが対象
git branch --show-current     # コミット直前に必ず確認(CLAUDE.md 絶対ルール)
```
