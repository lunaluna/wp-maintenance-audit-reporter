# WP Maintenance Audit Reporter

WordPress 用プラグイン：コア・テーマ・プラグインの定期保守監査 — **v1.0.0-RC9**。

WordPress.org 形式のメタデータと変更履歴は [readme-ja.txt](readme-ja.txt)（日本語） / [readme.txt](readme.txt)（英語）を参照してください。

English: [README.md](README.md).

## v1.0.0-RC9 の修正内容（チェックサム除外のディレクトリ指定対応・「プラグイン除外パス」ラベル修正）

- **チェックサム除外リストでディレクトリ指定に対応** — コア・プラグインどちらの除外リストでも、末尾に `/` または `/*` を付けることでディレクトリ以下のファイルをまとめて除外できるようになりました（例: コアは `wp-admin/` や `wp-admin/*`、プラグインは `akismet:some-dir/`）。これまでは完全一致のファイルパスのみ有効でした。内部的には `normalize_path_set` を `build_exclude_set`（`exact` と `dirs` を分けて返す）と `is_excluded`（完全一致＋プレフィックスマッチ）に置き換えました。設定ページの説明文にも新しい指定方法を追記しました。
- **設定ラベルの修正** — 「プラグイン除外」を「プラグイン除外パス」に修正しました（「コア除外パス」との表記統一）。

## v1.0.0-RC8 の追加内容（ネットワーク実行範囲 UI・CLI --same-setting / --id フラグ）

- **WP-CLI `--same-setting` フラグ（ネットワーク）** — `wp maintenance-audit run --network --same-setting` で親サイトのみを監査対象にします。全サイトが同一のプラグイン・テーマ構成の場合に便利です。
- **WP-CLI `--id=<blog_id>` フラグ（ネットワーク）** — `wp maintenance-audit run --network --id=2` で特定の blog ID 1件のみを監査対象にします。`--same-setting` より優先され、存在しない blog ID を指定するとエラーになります。
- **ネットワーク管理画面に「実行範囲」セレクターを追加** — スナップショットチェックボックスの直前にラジオボタンを追加。「ドライラン」「今すぐ実行」どちらにも適用されます。選択肢：すべての対象サイト（デフォルト）・親サイトのみ（`--same-setting` 相当）・特定のサイトのみ（`--id` 相当、blog ID 数値入力付き）。不正または存在しない blog ID はエラー notice を表示して実行を中断します。
- **修正: `resolve_blog_ids()` 存在しない blog ID ガード** — `WPMAR_Network_Runner::resolve_blog_ids()` で存在しない blog ID が指定された場合（古い WP-Cron ペイロードなど）、`switch_to_blog()` を呼ばず空配列を返すようにしました。

## v1.0.0-RC7 の変更内容（出力ファイル名にドメイン・対象・日付を付与、アップデート時の PDF ライブラリ保持、BIZ UDGothic フォントに変更）

- **出力ファイル名にドメイン・対象・日付を付与** — Markdown および PDF のファイル名にサイトドメイン、対象（管理者 / クライアント）、日付が含まれるようになりました。管理者向け Markdown: `wpmar-report-{domain}-admin-{Ymd}-{His}.md`、クライアント向け PDF: `wpmar-report-{domain}-client-{Ymd}-{id}.pdf`。ネットワーク集約では同じ形式で `wpmar-network-report-` プレフィックスを使用します。従来は `wpmar-report-{YmdHis}.md` / `wpmar-report-{id}.pdf` という対象区別のない名前でした。
- **プラグインアップデート時に `vendor/` を保持** — `WPMAR_PDF_Installer` が `upgrader_source_selection` および `upgrader_process_complete` フックに対応しました。
- **PDF 埋め込みフォントを Noto Sans JP から BIZ UDGothic に変更** — PDF 生成に使用するフォントを Noto Sans JP 可変フォント（1ファイル・mPDF でウェイト指定不可）から BIZ UDGothic Regular + Bold（TTF 2ファイル）に変更しました。mPDF が Regular / Bold を正しく描き分けるようになります。旧フォントファイル（`NotoSansJP.ttf`）は次回プラグイン読み込み時に `WPMAR_PDF_Installer::maybe_cleanup_legacy_fonts()` により自動削除されます。取り込まれるパッケージのフォルダ名＋本体ファイルで「対象が本プラグインか」を判定するため、`hook_extra` に `plugin` キーを含まない zip アップロード（インストール扱い）でも、管理画面からの「今すぐ更新」でも、WP-CLI・自動更新でも動作します。すでに `vendor/` が存在する場合は、WordPress がプラグインディレクトリを削除する前に一時領域（`wp-content/wpmar-vendor-backup/`）へ退避し、新しいファイルが配置された後に復元します。フックは管理画面に限らず全コンテキストで登録され、更新がコピー中に中断した場合も次回読み込み時のセルフヒールで退避済みの `vendor/` を自動復元します。アップデートのたびに PDF ライブラリを再インストールする必要がなくなります。

## v1.0.0-RC6 の変更内容（ネットワーク管理画面 UI の整備・504 修正・CLI --no-snapshot）

- **ネットワーク設定ページの UI をシングルサイトと同水準に整備** — ステータスパネルに「直近の完了時刻」と「WP-CLI」を追加。タイムゾーン欄に説明文を追加。「許可ホスト」行に検出ホストとの一致・不一致フィードバックを追加。「From」を「送信元メールアドレス」と「送信元表示名」の 2 行に分割。「出力・保持」を「保持期間」「レポートをファイルとして自動保存」「PDF ライブラリ（mPDF）」の 3 パネルに分割。「検証ツール」とスナップショットチェックボックスに説明文を追加。
- **削除: 含めるサイト チェックボックス** — 「対象サイト」パネルの「アーカイブ済み」「スパム」「削除済み」フィルターを廃止。除外したいサイトは「除外する blog ID」を使用してください。
- **削除: 許可パスプレフィックス** — パスプレフィックスによるゲート設定フィールドと `WPMAR_Domain_Gate` / `WPMAR_Network_Settings` の関連ロジックをすべて削除。
- **ネットワーク「今すぐ実行」をバックグラウンド実行に変更** — 同期実行（大規模ネットワークで 504 ゲートウェイタイムアウトを引き起こす原因）から WP-Cron の単発イベント（`wpmar_run_network_audit_manual`）のスケジュール登録＋ `spawn_cron()` 呼び出しに変更。新定数 `WPMAR_HOOK_NETWORK_MANUAL_RUN` とハンドラー `WPMAR_Scheduler::handle_network_manual_event()` を追加。`DISABLE_WP_CRON` が true の場合はエラー通知を表示するのみで実行しません（同期フォールバックなし）。
- **`DISABLE_WP_CRON` 警告バナー** — WP-Cron が無効な場合、ネットワーク管理画面とシングルサイト両方の設定ページ上部に赤い `notice-error` バナーを表示。スケジュール実行と手動実行が機能しないことを警告し、`wp maintenance-audit run --network` または外部 Cron を案内します。
- **WP-CLI `--no-snapshot` フラグ** — `wp maintenance-audit run --no-snapshot`（`--network` との併用も可）でスナップショットの保存を省略できるようになりました。CLI のデフォルト「常に保存」より優先されます。
- **修正: ネットワーク管理ページで実行中オーバーレイが表示されない** — `#wpmar-busy-overlay` がネットワーク設定ページの HTML に存在しなかった問題を修正。ドライランおよびフルランで正しくオーバーレイが表示されるようになりました。
- **修正: `add_site_transient()` Fatal エラー** — WordPress コアに `add_site_transient()` は存在しません。`get_site_transient()` + `set_site_transient()` に置き換え、`wp maintenance-audit run --network` 実行時の PHP Fatal エラーを解消しました。

## v1.0.0-RC5 の追加内容（PDF インストーラーのフォールバックとクライアント向け Markdown エクスポート）

- **メール送信失敗ログ** — `send_pair()` にスコープ付きの `wp_mail_failed` リスナーを追加。`WP_DEBUG_LOG` が有効な場合、トランスポートエラーが宛先アドレスと PHPMailer エラーメッセージとともに `wp-content/debug.log` に追記されます。これまで無言で失敗していた `wp_mail()` の問題を診断できるようになります。
- **空宛先の警告ログ** — メール通知が有効なのに `client_to` または `admin_to` にサニタイズ後の有効なアドレスが存在しない場合、`wp-content/debug.log` に警告を記録し、設定漏れに気付けるようにします。
- **空宛先の管理画面アラート** — 設定ページを開いたとき、メール通知が有効で宛先のいずれかが空であれば `warning`（黄）の notice を、両方空であれば `error`（赤）の notice を表示します。
- **事前診断チェック（Pre-flight）** — GitHub ダウンロード開始前に、プラグインディレクトリへの書き込み権限とディスク空き容量（150 MB 以上）を検証。権限不足はパスと `chmod 755` の修正手順を、ディスク不足は現在の空き容量を表示してエラーを中断します。
- **手動 ZIP アップロードフォールバック** — ファイアウォールやネットワーク制限で自動ダウンロードが失敗した場合、「手動インストール」パネルが表示されます。管理者が `vendor-pdf.zip` を手元にダウンロードしてブラウザ上からアップロードすると、サーバー側で ZIP マジックバイトを検証して同じパイプラインで展開します。`upload_max_filesize` 超過などのエラーも具体的なメッセージで報告します。
- **Markdown フォールバック案内** — PDF ライブラリがインストールできない環境でも、クライアント向けレポートをマークダウン形式でダウンロードできることをインストーラーパネルに案内表示します。
- **`client_md` ダウンロードタイプ** — レポート詳細画面から `body_client_md`（クライアント向けマークダウン）を `wpmar-report-{id}-client.md` としてダウンロードできるようになりました。PDF ライブラリの有無に関係なく利用可能です。
- **PDF 利用可否に連動するボタン切り替え** — レポート詳細画面で PDF ライブラリが未インストールの場合、「PDF をダウンロード（クライアント向け）」ボタンが「Markdown をダウンロード（クライアント向け）」に切り替わります。
- **`pdf_enabled` 未インストール警告** — 設定ページの「PDF を uploads に書き出して保存」チェックボックスの横に、PDF ライブラリが未インストールの場合は現在機能しない旨の警告を表示します。
- **`.vscode/bin/phpcs` 検索順修正** — Homebrew の `phpcs` 4.x は WordPress Coding Standard（`^3.x` 必須）と非互換のため、シムが Homebrew より先に Composer インストール版 `phpcs` を検索するよう変更しました。

## v1.0.0-RC4 の修正内容

- **mPDF インストール時の `vendor-pdf.zip` 404** — ダウンロード URL に `v` プレフィックスを付けていたが（`v1.0.0-RC3`）、リリースタグは `v` なし（`1.0.0-RC3`）のため 404 になっていた。`WPMAR_PDF_Installer::get_download_url()` の URL から `v` を除去。
- **macOS での `build-vendor-pdf-zip.sh` 不完全 zip** — `mktemp -d` が `/var/folders/…` というシンボリックリンク経由のパスを返すため、`zip` がファイルを解決できず不完全なアーカイブが生成されていた。`realpath` を追加して解決。

## v1.0.0-RC3 で追加されること（PDF ライブラリのオンデマンドインストール）

- **`WPMAR_PDF_Installer`** — 管理画面の設定ページから mPDF ベンダーバンドルをオンデマンドでインストールできます。新設の「PDF ライブラリ（mPDF）」パネルにインストール状況を表示し、ボタンを押すと GitHub Releases から `vendor-pdf.zip` をダウンロードしてプラグインの `vendor/` ディレクトリに `ZipArchive` で展開します。サーバー上での手動 `composer install` が不要になり、`upload_max_filesize` / `post_max_size` の 30 MB 制限でアップロードが失敗していた問題を解消します。
- **`bin/build-vendor-pdf-zip.sh`** — プロダクション依存のみを一時ディレクトリにインストールして `vendor-pdf.zip` としてパッケージ化するビルドスクリプト。
- **リリースパイプラインの更新** — `release.yml` がプラグイン zip から `vendor/` を除外し、`vendor-pdf.zip` を別アセットとして自動ビルド・添付。
- **phpcs シム修正** — `.vscode/bin/phpcs` が既知のパスから PHP バイナリを検索し、常に `php phpcs_script` 形式で実行することで VS Code 拡張機能ホストでの `env: php: No such file or directory` エラーを解消。

## v1.0.0-RC2 の修正内容

- **有効化時の fatal error** — `WPMAR_GitHub_Updater` のクラス定数（`const`）に WordPress のランタイム定数（`HOUR_IN_SECONDS` / `MINUTE_IN_SECONDS`）を使用していた問題を修正。PHP はクラス定数を WordPress 起動前のコンパイル時に評価するため fatal error が発生していた。リテラル整数（`DEFAULT_CACHE_TTL = 21600`、`DEFAULT_BACKOFF_TTL = 1800`）に変更。
- **PHP 7.4 非互換** — PHP 8.0 以降でのみ使用できる `str_contains()` を `false !== strpos()` に置き換え。
- **TTL のフィルター対応** — キャッシュ時間とバックオフ時間を `apply_filters()` 経由で上書き可能に:
  - `wpmar_github_updater_cache_ttl`（デフォルト 21600 秒 / 6 時間）
  - `wpmar_github_updater_backoff_ttl`（デフォルト 1800 秒 / 30 分）

## v1.0.0-RC1 について（リリース候補版）

- **リリース候補版** — 主要なサブシステム全体のエンドツーエンドテストを経て `0.x` 開発シリーズから昇格。新機能なし。v0.11.0 時点の全機能をそのまま引き継ぎます。

## v0.11.0 で追加されること（GitHub Releases 自動アップデート）

- **`WPMAR_GitHub_Updater`** — WordPress.org への掲載なしに、GitHub Releases から直接プラグインを自動アップデートできるようになります。
  - `pre_set_site_transient_update_plugins` フィルター — GitHub API で最新リリースを確認し、新バージョンがあれば WordPress のトランジェントに更新情報を注入。プラグイン一覧に「アップデートあり」バッジが表示され、ワンクリックで更新可能になります。
  - `plugins_api` フィルター — 「バージョン x.x.x の詳細を表示」モーダルにバージョン情報・リリースノートを提供。
  - `upgrader_process_complete` アクション — アップデート完了後にリリースキャッシュを削除し、次回チェック時に最新情報を取得。
- **トランジェントキャッシュ** — GitHub API レスポンスを 6 時間キャッシュ（`wpmar_github_release_cache`）。エラー・レート制限時は 30 分バックオフ。
- **リリースアセット優先** — `release.yml` が添付した zip を自動生成の zipball より優先して使用することで、展開後のディレクトリ名がプラグインフォルダと一致し、WordPress のアップデーターがリネームなしで正常に展開可能。

## v0.10.2 で変更されること（リリーストリガ）

- **`v` 無しタグも受け付け** — `.github/workflows/release.yml` のトリガを `'v[0-9]*'` と `'[0-9]*'` の両方に拡張。本プロジェクトでは WordPress.org Stable tag の慣習に合わせ `v` 無し（例 `0.10.2`）でタグを打ちます。

## v0.10.1 で修正されること（CI を緑に）

- **CI / phpcompat の修復** — v0.10.0 の YAML タブ→スペース修正で Actions がジョブを実行するようになった結果、PHP 8.0 / 8.2 / 8.3 全てで PHPCS が失敗していました。v0.10.1 では `includes/class-wpmar-runner.php` の等号アラインメントとインラインコメント終端の違反を修正し、`phpcs.xml.dist` に `tests/*` の除外を追加（PHPUnit テストは PHPUnit 規約に従うため WPCS 対象外）。

## v0.10 で追加・修正されること（レポート修正・リリースパイプライン）

- **`version_compare()` ベースの比較** — テーマ／プラグインの「最新バージョン」判定を文字列の不一致ではなく `version_compare()` に変更。インストール版＞ディレクトリ版の場合は誤って「アップデートあり」と表示せず、`データが正しく取得できませんでした。` と表示。
- **非公式プラグインの重複メッセージ統合** — チェックサム文言とバージョン情報側のフォールバックが両方出ていたのを、1行 `◯◯ は非公式か、既に公開終了している可能性があります。` に統一。
- **チェックサム差分のインデント** — `〜の以下のファイルに変更が見つかりました:` 配下のファイル行を1段深い `　　　　` に変更。
- **バックアップ状況セクションの非表示** — 取得機能が未実装のため、管理者向けメール本文から `# 【バックアップ状況】` の出力を停止。`render_operator_backup_section()` 等のコードは将来のために残存。
- **CI workflow のパース修正** — `.github/workflows/ci.yml` のインデントをタブ→スペースに変更（YAML 1.2 はタブインデント不可）。GitHub Actions が「No jobs were run」と通知していた問題を解消。マトリクスに `fail-fast: false` を追加。
- **リリースパイプライン** — `.github/workflows/release.yml` を新規追加。`v*` タグ push（または手動 `workflow_dispatch`）で起動し、タグとプラグインヘッダ `Version:` の一致確認 → `composer install --no-dev --optimize-autoloader` → `wp-maintenance-audit-reporter.<version>.zip`（`.git` / `.github` / `tests` / `phpunit.xml.dist` / `phpcs.xml.dist` を除外）作成 → `CHANGELOG.md` から該当バージョンの節をリリースノートに抽出 → `gh release create` で GitHub Release 発行。

### リリース手順

```bash
# 1. wp-maintenance-audit-reporter.php / WPMAR_VERSION / composer.json / readme*.txt / README*.md を新バージョンに更新
git commit -am "release: 1.0.0-RC7"
git push origin main

# 2. タグを打って push（release.yml が起動）。Stable tag 風の v 無し表記:
git tag 1.0.0-RC7
git push origin 1.0.0-RC7
# （v1.0.0-RC7 のような v 付きタグも受け付けます）
```

## v0.9 で追加・修正されること（セキュリティ・信頼性）

- **ノンス→権限の順序修正** — 管理画面の設定ハンドラで `check_admin_referer()` を `current_user_can()` より先に呼ぶよう修正（CSRF 対策の強化）。
- **パストラバーサル防止** — `WPMAR_MD_Writer` が `..` を含む相対パスをアップロード相対パスの構築前に拒否。
- **タイムゾーンのホワイトリスト検証** — `WPMAR_Settings` がフォーム入力のタイムゾーン文字列を PHP の `timezone_identifiers_list()` に照合して検証。無効・空の値は `Asia/Tokyo` にフォールバック。
- **SSL 二段階チェック** — `WPMAR_Check_Security_Ops` がまず証明書検証あり（`verify_peer=true`）で接続を試み、失敗時（例：証明書期限切れ）のみ検証なしにフォールバック。結果にバイパスの有無を記録。
- **コレクターエラーの隔離** — データコレクターが `call_user_func()` を `try/catch (Throwable)` でラップし、カスタムコレクターのエラーで監査全体が止まらないように。
- **ノーティファイア: `is_email()` 検証** — QA メールアドレスの文字列分岐に `is_email()` チェックを追加。
- **CI audit** — `.github/workflows/ci.yml` に `composer audit --no-dev` ステップを追加。
- **ユニットテスト** — 新規 28 件：`SettingsTest`（設定ヘルパー、タイムゾーンホワイトリスト、保持期間、スケジュールクランプ）と `DomainGateTest`（ホスト・パス照合、ネットワークフォールバック）。

## v0.8 で追加されること（マルチサイト）

- **ネットワーク集約監査** — プラグインをネットワーク有効化し、**ネットワーク管理 → Maintenance Audit** で有効化。全対象サイトを `switch_to_blog` で巡回し、**クライアント向け・管理者向け各1本**の集約レポートを **メインサイト** に保存、メールも1回ずつ送信。
- **サイトフィルタ** — blog ID 除外、最大サイト数、archive/spam/deleted の含有。
- **ドメインゲート** — サイト切替後に home_url で照合。ネットワーク側の許可ホスト／**パスプレフィックス**（サブディレクトリ型向け）。
- **CLI** — `wp maintenance-audit run --network`。

## v0.7 で追加されること

- **手動実行時のスナップショット保存** — **設定・実行** の **「スナップショットを保存する（差分比較用）」** は **今すぐ実行** のみに効きます。オンにすると手動実行のたびに `wpmar_snapshots` を更新し、次元ごとに古い行は最大 2 世代まで保持します。オフのときはレポート本文と変更履歴は **今回の収集** と **DB にある最新スナップショット** の比較のままですが、スナップショット表は更新しません。**WP-Cron** および **WP-CLI** の実行では従来どおり常にスナップショットを保存します。
- **テストメール上書き先** — **今すぐ実行** 時にアドレスを入れていれば、**クライアント向け** と **管理者向け** の両方をそのアドレスへも送ります（該当の宛先リストに既に同じアドレスがある場合は、その種類の重複送信はしません）。専用の「テストメール付き実行」ボタンはありません。

## v0.6 で追加・変更されること

- **クライアント向け HTML メール** — PDF と同じ **クライアント向け Markdown** を Parsedown で HTML 化。依存が揃うとき `text/html`、プレーンテキスト代替付き。`wpmar_client_mail_html_enabled` で HTML をオフに可能。
- **件名** — 社内保守スクリプト相当の形式（サイト名＋サイトローカル日付）。
- **更新停滞プラグイン** — WordPress.org の `last_updated` から 180 日 / 365 日の目安でクライアント向け本文に節を追加。
- **管理者向けメール** — RAW JSON ではなく mainte.sh 風の **整形プレーンテキスト**。

## v0.4 で追加されること

- **PDF（クライアント向け）** — 監査実行時に PDF を `uploads/wpmar/pdf/` へ保存（mPDF / Parsedown）。設定ページからオンデマンドで PDF ライブラリをインストール可能（サーバーでの `composer install` 不要）。PDF は保存済みの **クライアント向け Markdown（`body_client_md`）** をソースにします。レポート詳細画面のプレビューは **管理者向け Markdown（`body_md`）** です。**設定・実行** で ON/OFF。
- **ZIP 一括ダウンロード** — **レポート** 一覧で行を選び、一括操作「ZIP 一括ダウンロード」で **管理者向け** `.md` と保存済み **クライアント向け** `.pdf` を ZIP 取得。行アクション・詳細からも Markdown（管理者向け）／PDF（クライアント向け）を個別ダウンロード。
- **CLI export** — `wp maintenance-audit export <id> --format=markdown|json|pdf`。`markdown` は **管理者向け** 本文、`pdf` は **クライアント向け**（保存済みクライアント向け Markdown がソース）。`--file=<path>` でファイルへ書き出し可能（他プラグインが CLI bootstrap で Notice を出すときの PDF 取得に有用）。
- **管理画面の案内** — **設定・実行** と **レポート** で、レポート行もスナップショット行もまだ無いときに案内を表示。行削除・一括削除は **確認ダイアログなし** でそのまま実行されます。

## v0.3 で追加されること

- **運用・セキュリティ診断** — HTTPS サイト向け TLS 証明書期限（設定で無効化可）、PHP 系列ごとの EOL 参照マップ（PHP.net の公表に合わせコード更新）、WordPress/PHP/MySQL の簡易ヒント、管理者の最終セッション時刻、`wp-config.php` のパーミッション、本番環境タイプでの `WP_DEBUG` / `SCRIPT_DEBUG` 警告。

## v0.2 で追加されること（0.1 のスキャフォールドに対して）

- **チェックサム** — WordPress.org API によるコア・プラグインの検証。設定で除外リスト。サイトロケールで利用可能なマニフェストがない場合のフォールバック。
- **保持** — 12 ヶ月または 24 ヶ月より古いレポートの自動削除（「無期限保持」も可）。監査成功後に実行。該当するデータベース行とアップロード済み Markdown（管理者向け）／PDF（クライアント向け）を削除。
- **レポート管理** — 一覧と詳細（**管理者向け** Markdown）表示、1 ページ 20 件のページネーション、Markdown（管理者向け）／PDF（クライアント向け）ダウンロード、ZIP 一括エクスポート。単一・一括削除は確認なしで実行、成功表示は再読み込みで貼りつかないトランジェント通知。
- **管理メニュー** — トップレベル **Maintenance Audit** と **設定・実行** / **レポート**。画面は `wp-admin/admin.php?page=…` で読み込み。

スケジュール、ドメイン制限、Markdown / メール出力、スナップショット、WP-CLI 連携の全体像はデザインの一部です。機能一覧の全体は `readme-ja.txt` / `readme.txt` を参照してください。

## 開発

WordPress / 実行環境の目安: **PHP 7.4+**。

Composer の開発ツールおよび **ランタイム依存**（mPDF / Parsedown／PDF および **クライアント向け HTML メール**）: CI およびローカルで `composer install` には **PHP 8.0+**。プラグイン本体は PHP 7.4 で動く構文に収めているため、サイトは将来まで PHP 7.4 のままにできます。

WordPress **6.0+**。動作確認済み: **7.0**。

### Composer

**`vendor/` はリポジトリに含まれません**（`.gitignore` 参照）。依存は `composer.json` にあり、`composer.lock` で固定されています。クローン後は一度 `composer install` が必要です。

```bash
cd wp-content/plugins/wp-maintenance-audit-reporter
composer install
```

**GitHub Actions**（`.github/workflows/ci.yml`）も同じ手順です（PHPCS / PHPUnit の前に `composer install`）。

### コーディング規約

プラグイン直下で:

```bash
composer run phpcs
```

### テスト

```bash
composer run phpunit
```

### 配布用 ZIP（GitHub リリース）

v0.10.0 で **`.github/workflows/release.yml`** として実装済み。トリガーは `v*` タグの push（または手動 `workflow_dispatch`）。

1. タグを解析し、`wp-maintenance-audit-reporter.php` の `Version:` ヘッダと一致するか検証。一致しなければジョブが失敗。
2. **`composer install --no-dev --optimize-autoloader`** を実行してプロダクション依存をインストール。
3. `wp-maintenance-audit-reporter/` ディレクトリにステージング（`.git` / `.github` / `tests/` / **`vendor/`** / `phpunit.xml.dist` / `phpcs.xml.dist` / `.phpunit.result.cache` などを除外）し、`wp-maintenance-audit-reporter.<version>.zip` として圧縮。
4. インストール済みの `vendor/` から **`vendor-pdf.zip`** を別途作成し、管理画面からのオンデマンドインストール用の追加アセットとして添付。
5. `CHANGELOG.md` から該当 `## [version]` 節をリリースノートとして抽出（無ければ汎用の文言にフォールバック）。
6. `gh release create` で GitHub Release を作成し、両 zip を添付。

PR 向け CI（**`.github/workflows/ci.yml`**）はこれまでどおり **`composer install`（dev 込み）** で PHPCS / PHPUnit を回します。

## ライセンス

GPLv2 以降。詳細は [LICENSE](LICENSE)。
