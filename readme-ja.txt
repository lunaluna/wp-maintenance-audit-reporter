=== WP Maintenance Audit Reporter ===
Contributors: lunaluna_dev
Tags: maintenance, report, security, backup, audit
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0-RC12
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress の保守向けレポート（コア・テーマ・プラグイン、チェックサム、差分、任意のメール、レポート蓄積、WP-CLI）を扱うプラグインです。英語の説明は readme.txt を参照してください。

== 概要 ==

**v1.0.0-RC3** はリリース候補版です。主要なサブシステム全体のエンドツーエンドテストを経て `0.x` 開発シリーズから昇格しました。**v0.11.0** では **GitHub Releases 自動アップデート**機能を追加しました。WordPress の標準アップデートパイプラインに組み込み、GitHub で新しいリリースが公開されると管理画面に通知が表示され、ワンクリックで更新を適用できます（WordPress.org への掲載は不要）。GitHub API レスポンスは 6 時間キャッシュされます（`wpmar_github_release_cache` トランジェント）。**v0.10.0** では管理者向けレポートの表示を修正しました（WordPress.org ディレクトリとのバージョン比較を `version_compare()` ベースに変更し、インストール版＞ディレクトリ版の場合は「データが正しく取得できませんでした。」と表示／非公式プラグインの重複メッセージを統合／チェックサム差分のファイル一覧のインデントを1段深く／未実装の **【バックアップ状況】** セクションを非表示）。あわせて `.github/workflows/release.yml` を追加し、`v*` タグ push で `wp-maintenance-audit-reporter.<version>.zip`（本番依存のみ含む）を生成して GitHub Release を発行できるようにしました。`.github/workflows/ci.yml` のインデント（タブ→スペース）も修正しています。**v0.9.0** ではセキュリティと信頼性を強化しました。管理画面ハンドラでのノンス検証順序の修正、ファイルストレージでのパストラバーサル防止、タイムゾーン入力のホワイトリスト検証、SSL プローブの二段階チェック（検証あり優先・証明書期限切れ時フォールバック）、カスタムコレクターのエラー隔離、28 件のユニットテスト追加。**v0.8.0** では**マルチサイト ネットワーク集約監査**を追加しました。ネットワーク有効化後、**ネットワーク管理 → Maintenance Audit** で有効化すると全対象サイトを `switch_to_blog` で巡回してレポートを集約し、メインサイトから一対のメールを送信します。**v0.7.0** では、**設定・実行** に **「スナップショットを保存する（差分比較用）」** を追加しました。**今すぐ実行**（手動）でオンにすると手動実行のたびにスナップショット（DB）を更新し、オフのときはレポート・変更履歴は **今回の収集と保存済み最新スナップショットの比較** のままですが、スナップショット表は更新しません。**テストメール上書き先** にアドレスを入れて **今すぐ実行** した場合は、設定どおりの宛先に加え、**クライアント向け** と **管理者向け** のレポートメールをそれぞれそのアドレスにも追加送信します（同じアドレスが既に該当宛先に含まれる種類は重複しません）。**WP-Cron** と **WP-CLI** の実行では従来どおり常にスナップショットを保存します。**v0.6** のメール改善（HTML、更新停滞プラグイン、管理者プレーン整形）や **v0.5** 由来の拡張（`wpmar_report_sections` / `wpmar_notification_channels` / `wpmar_backup_providers`）、DB サイズサンプル、通知ディスパッチも利用できます。

* **メール（クライアント）** — Parsedown が利用可能なとき（`composer install` 済みの `vendor/`）**HTML 本文**。従来クライアント向け用のプレーンテキスト代替付き。HTML を止めたい場合はフィルター `wpmar_client_mail_html_enabled`。
* **PDF（クライアント向け／任意）** — 監査を実行するたびに `uploads/wpmar/pdf/*.pdf` を生成可能（**クライアント向け** Markdown をソースとする）。管理画面「PDF ライブラリ（mPDF）」セクションのボタンから PDF ライブラリをオンデマンドでインストールできます。
* **ZIP 一括** — レポート一覧で選択した行の **管理者向け** `.md` と保存済み **クライアント向け** `.pdf` を ZIP で取得します。
* **CLI export** — `wp maintenance-audit export <id> --format=markdown|json|pdf`。`markdown` は **管理者向け**、`pdf` は **クライアント向け**。`--file=<path>` でファイルへ書き出し（他プラグインが bootstrap で Notice を出す環境での PDF 取得向け）。
* **未取得の案内** — **設定・実行** と **レポート** で、レポート行もスナップショット行も無いときに案内を表示します。
* **スナップショット保存（手動実行・v0.7）** — **設定・実行** の **「スナップショットを保存する（差分比較用）」** は **今すぐ実行** のみ。オフでも変更履歴は保存済み最新と今回収集の比較。**WP-Cron** / **WP-CLI** は常に保存。
* **テストメール上書き先（v0.7）** — **今すぐ実行** 時、入力があれば **クライアント向け**・**管理者向け** をそれぞれそのアドレスへも追加送信（該当宛先に既に含まれる種類は重複しない）。

* **スケジュール** — 月次の WP-Cron を基準に、任意で WP-CLI 経由のサーバー cron も利用可能。
* **棚卸しと差分** — コア・テーマ・プラグインの差分をスナップショット間で検出。
* **チェックサム** — WordPress.org のマニフェストと照合してコア・プラグインを検証。除外リストを設定可能。サイト言語のマニフェストが取得できない場合はロケールのフォールバック。
* **ドメイン制限** — 許可したホスト以外（ステージングなど）ではスナップショットとレポートの副作用をスキップ。
* **出力** — 詳細な **管理者向け** Markdown（アップロード先）と、任意のメールペア（**クライアント向け** HTML またはテキスト ＋ **管理者向け** プレーンテキスト）。
* **レポート保存** — データベーステーブルと対になる Markdown パス。**保持期間**（無期限 / 12 / 24 ヶ月）に応じて、正常完了後に古い行とファイルを削除。
* **管理画面** — トップレベル **Maintenance Audit** メニュー（`admin.php` 画面）。**設定・実行**（スケジュール、メール、除外、保持、実行）と **レポート**（一覧テーブル、1 ページ 20 件、詳細、Markdown（管理者向け）／PDF（クライアント向け）のダウンロード、ZIP 一括エクスポート、確認なしの行単位・一括削除。成功通知はトランジェントのフラッシュ表示で、クエリ引数の貼り付けはしない）。

無人実行や CI 的な確認には WP-CLI を利用してください。

== インストール方法 ==

1. プラグインフォルダを `/wp-content/plugins/` にアップロードします
2. WordPress の **プラグイン** メニューから有効化します
3. PDF 出力が必要な場合は、**設定・実行** ページの「PDF ライブラリ（mPDF）」セクションから **「PDF ライブラリをインストール」** ボタンを押します。GitHub Releases から `vendor-pdf.zip` を自動ダウンロード・展開します（約 94 MB）。

== Git 管理 ==

このプラグインをプロジェクト内で Git 管理している場合、以下の2ディレクトリはオンデマンドで生成されるため `.gitignore` に追加することを推奨します。

  wp-content/plugins/wp-maintenance-audit-reporter/fonts/
  wp-content/plugins/wp-maintenance-audit-reporter/vendor/

`fonts/` は mPDF が PDF 生成時に書き込むフォントキャッシュです。`vendor/` は PDF ライブラリ（mPDF）のオンデマンドインストール先です。

== よくある質問 ==

= 本番環境で使えますか？ =

v1.0.0-RC12 がリリース候補版です。テスト目的では安定版として扱えます。正式版は 1.0.0 タグで公開される予定です。

= 「設定」のサブメニューがなくなったのはなぜですか？ =

v0.2 以降、管理 UI は専用のトップレベル **Maintenance Audit** メニュー配下（サブメニュー **設定・実行** と **レポート**）です。URL は `wp-admin/admin.php?page=…` になり、`options-general.php?page=…` は使いません。

== 変更履歴 ==

= 1.0.0-RC12 =
* 変更: ドライランも非同期化 — シングルサイト／ネットワークの「ドライラン」を「今すぐ実行」と同様に Action Scheduler 経由で登録し即応答。PDF ではなくデータ収集自体が遅い場合の 504 タイムアウトに対応。Action Scheduler 不在時は従来の同期実行＋インラインプレビューにフォールバック。
* 変更: モード対応ポーリング — フラッシュ通知・パネル見出し・完了文言がジョブのモード（full/dry）に応じて切り替わります。ドライランは完了時に要約（`dry_brevity`）を表示し、フルランはプレビュー／ダウンロードリンクを表示。
* 変更: REST ペイロード軽量化 — ドライランジョブの結果は compact な `dry_brevity` 要約のみを返し、巨大な `dry_preview` データを除外。
* 変更: `vendor-pdf.zip` から Action Scheduler を除外（`bin/build-vendor-pdf-zip.sh` が zip 化前に `vendor/woocommerce` を削除）。オンデマンドの PDF バンドルは mPDF＋Parsedown のみ、Action Scheduler は `lib/` のみで配布。
* 修正: 最新版へ更新後も「新バージョンが利用できます」通知が残る不具合 — `check_for_update()` が最新版時に古い `response` エントリを削除し、`after_update()` が `update_plugins` トランジェントをクリアすることで、最新版になると通知が即座に消えるようにしました。

= 1.0.0-RC11 =
* 修正: 管理画面「更新」が「パッケージをインストールできませんでした。」で失敗する不具合 — `WPMAR_GitHub_Updater::extract_zip_url()` が最初の zip リリースアセットを選んでいたため、プラグイン本体 zip ではなく同梱の `vendor-pdf.zip`（mPDF／フォントのみで有効なプラグインではない）を選ぶことがありました（GitHub API はアセットの並び順を保証しません。実際 `vendor-pdf.zip` が先頭で返ります）。その結果 WordPress が誤ったアーカイブのインストールに失敗していました（本体 zip の手動アップロードは成功）。アセットを**名前**で判定するよう変更し、プラグインスラッグ `wp-maintenance-audit-reporter` で始まり `.zip` で終わるアセットのみを選択することで、並び順に関わらず常に正しい本体 zip を選ぶようにしました。`zipball_url` へのフォールバックは従来どおりです。

= 1.0.0-RC10 =
* 追加: Action Scheduler による監査の非同期ジョブ化 — シングルサイト／ネットワークの「今すぐ実行」がバックグラウンドジョブを登録して即応答し、長時間監査での CloudFront 504 タイムアウトを解消。`WPMAR_Job_Dispatcher`、`wpmar_jobs` テーブル（`WPMAR_Jobs_Repository`）、`lib/action-scheduler/` 同梱を追加。
* 追加: REST エンドポイント `GET /wpmar/v1/jobs/<id>`（`manage_options`）。ジョブ状態と、完了時はレポートURL＋nonce 署名付きダウンロードリンクを返却。
* 追加: 管理画面の進捗ポーリングUI — フラッシュ通知＋「レポート生成ジョブ」パネルが状態（queued -> running -> 完了）をポーリング表示し、プレビュー／ダウンロードリンクを描画。ジョブIDはリダイレクトで引き継ぐため再読み込みでも維持。
* 追加: WP-CLI `wp wpmar audit run --sync [--dry-run] [--network] [--no-snapshot]` — CloudFront 非経由の同期フォールバック。既存の `wp maintenance-audit run` は変更なし。
* 変更: 月次 WP-Cron 監査とネットワーク「今すぐ実行」を Action Scheduler ジョブ基盤に統一（ライブラリ不在時は同期／従来の単発イベントにフォールバック）。
* 備考: Action Scheduler の `actionscheduler_*` テーブルはアンインストール時に削除しません（他プラグインと共有の可能性があるため）。

= 1.0.0-RC9 =
* 追加: チェックサム除外リストでディレクトリ指定に対応 — コア・プラグインどちらの除外リストでも、末尾に `/` または `/*` を付けることでディレクトリ以下のファイルをまとめて除外できます（例: コアは `wp-admin/`、プラグインは `akismet:some-dir/`）。これまでは完全一致のみ有効でした。設定ページの説明文にも追記しました。
* 修正: 設定ラベル — 「プラグイン除外」を「プラグイン除外パス」に変更しました（「コア除外パス」との表記統一）。

= 1.0.0-RC8 =
* 追加: WP-CLI `--same-setting` フラグ（ネットワーク） — `wp maintenance-audit run --network --same-setting` で親サイトのみを監査対象にします。全サイトが同一のプラグイン・テーマ構成の場合に便利です。
* 追加: WP-CLI `--id=<blog_id>` フラグ（ネットワーク） — `wp maintenance-audit run --network --id=2` で特定の blog ID 1件のみを監査対象にします。`--same-setting` より優先され、存在しない blog ID を指定するとエラーになります。
* 追加: ネットワーク管理画面に「実行範囲」セレクター — スナップショットチェックボックスの直前にラジオボタンを追加。「ドライラン」「今すぐ実行」どちらにも適用され、すべての対象サイト（デフォルト）・親サイトのみ（`--same-setting` 相当）・特定のサイトのみ（`--id` 相当、blog ID 数値入力付き）から選べます。不正または存在しない blog ID はエラー notice を表示して実行を中断します。
* 修正: `WPMAR_Network_Runner::resolve_blog_ids()` — 存在しない blog ID が指定された場合（直接呼び出しや古い WP-Cron ペイロードなど）、`switch_to_blog()` に渡さず空配列を返すようにしました。

= 1.0.0-RC7 =
* 変更: 出力ファイル名にドメイン・対象・日付を付与 — Markdown および PDF のファイル名にサイトドメイン、対象（管理者 / クライアント）、日付が含まれるようになりました。管理者向け Markdown: `wpmar-report-{domain}-admin-{Ymd}-{His}.md`、クライアント向け PDF: `wpmar-report-{domain}-client-{Ymd}-{id}.pdf`。ネットワーク集約では同じ形式で `wpmar-network-report-` プレフィックスを使用します。従来は `wpmar-report-{YmdHis}.md` / `wpmar-report-{id}.pdf` という対象区別のない名前でした。
* 変更: PDF 埋め込みフォントを Noto Sans JP 可変フォント（1ファイル・mPDF でウェイト指定不可）から BIZ UDGothic Regular + Bold（TTF 2ファイル）に変更。mPDF で Regular / Bold が正しく描き分けられるように。旧ファイル（`NotoSansJP.ttf`）は次回プラグイン読み込み時に自動削除。
* 修正: プラグインアップデート時の PDF ライブラリ（`vendor/`）保持 — `WPMAR_PDF_Installer` が `upgrader_source_selection` ＋ `upgrader_process_complete` をフックし、取り込みパッケージのフォルダ名＋本体ファイルで対象を判定するため、`hook_extra` に `plugin` キーを含まない zip アップロード（インストール扱い）、管理画面の「今すぐ更新」、WP-CLI・自動更新のいずれでも動作します。すでに `vendor/` が存在する場合は、WordPress がプラグインディレクトリを削除する前に一時領域（`wp-content/wpmar-vendor-backup/`）へ退避し、新しいファイル配置後に復元します。フックは全コンテキストで登録され、更新中断時も次回読み込み時のセルフヒールで退避済み `vendor/` を自動復元します。アップデートのたびに PDF ライブラリを再インストールする必要がなくなります。

= 1.0.0-RC6 =
* 追加: ネットワーク設定 UI — ステータスパネルに「直近の完了時刻」と「WP-CLI」を追加（シングルサイトと統一）。
* 追加: ネットワーク設定 UI — タイムゾーンの説明文追加、「許可ホスト」に検出ホスト・一致フィードバック表示、「From」を「送信元メールアドレス」と「送信元表示名」の2行に分割、「出力・保持」を「保持期間」「レポートをファイルとして自動保存」「PDF ライブラリ（mPDF）」の3パネルに分割、「検証ツール」とスナップショットチェックボックスに説明文追加。
* 追加: ネットワーク「今すぐ実行」をバックグラウンド化 — WP-Cron の即時スケジュール（`wpmar_run_network_audit_manual`）方式に変更し、大規模ネットワークでの 504 タイムアウトを回避。
* 追加: `DISABLE_WP_CRON` 警告 notice — WP-Cron が無効な場合、ネットワーク設定・シングルサイト設定の両ページ上部に赤い notice を表示。スケジュール実行・手動実行ともに機能しないことを明示し、WP-CLI または外部 Cron の利用を案内。
* 追加: WP-CLI `--no-snapshot` フラグ — `wp maintenance-audit run --no-snapshot`（`--network` にも対応）でスナップショット保存をスキップ可能に。CLI トリガーのデフォルト「常に保存」より優先される。
* 削除: ネットワーク設定の「含めるサイト」チェックボックス（アーカイブ済み・スパム・削除済み）を「対象サイト」パネルから削除。
* 削除: ネットワーク設定の「許可パスプレフィックス」フィールドと関連ロジックを削除。
* 修正: ネットワーク設定の実行中オーバーレイ（`#wpmar-busy-overlay`）が表示されなかった問題を修正。
* 修正: `WPMAR_Network_Runner` — WordPress コアに存在しない `add_site_transient()` を `get_site_transient()` + `set_site_transient()` に置き換え。WP-CLI 実行時の PHP Fatal error を解消。
* 修正: ネットワーク「今すぐ実行」で `DISABLE_WP_CRON` が true の場合、同期実行（504 リスク）を行わずエラー notice を表示するように変更。

= 1.0.0-RC5 =
* 追加: メール送信失敗ログ — `send_pair()` にスコープ付き `wp_mail_failed` リスナーを追加。`WP_DEBUG_LOG` が有効な場合、トランスポートエラーが宛先とエラーメッセージとともに `wp-content/debug.log` に追記される。
* 追加: 空宛先の警告ログ — メール通知が有効なのに `client_to` または `admin_to` に有効なアドレスがない場合、`wp-content/debug.log` に警告を記録。
* 追加: 空宛先の管理画面アラート — 設定ページで、メール通知が有効かつ宛先が空の場合に `warning` notice を、両方空の場合は `error` notice を表示。
* 追加: PDF ライブラリダウンロード前の事前診断（Pre-flight）— 書き込み権限とディスク空き容量（150 MB 以上）を事前検証。権限不足はパスと `chmod 755` の手順を、ディスク不足は現在の空き容量を表示して中断。
* 追加: 手動 ZIP アップロードフォールバック — 自動ダウンロードが失敗した場合に「手動インストール」パネルを表示。管理者が `vendor-pdf.zip` をブラウザからアップロードすると、ZIP マジックバイト検証後に同じパイプラインで展開。`upload_max_filesize` 超過等も具体的なメッセージで報告。
* 追加: インストーラーパネルに Markdown フォールバック案内 — PDF ライブラリがインストールできない環境でも、クライアント向けレポートをマークダウン形式でダウンロードできることを案内。
* 追加: `client_md` ダウンロードタイプ — レポート詳細画面から `body_client_md` を `wpmar-report-{id}-client.md` として直接ダウンロード可能に。PDF ライブラリの有無に関係なく利用可。
* 追加: レポート詳細画面の PDF 利用可否連動 — PDF ライブラリが未インストールの場合、「PDF をダウンロード（クライアント向け）」ボタンが「Markdown をダウンロード（クライアント向け）」に切り替わる。
* 追加: 設定ページの `pdf_enabled` 未インストール警告 — PDF ライブラリが未インストールの場合、チェックボックス横に現在機能しない旨の警告を表示。
* 修正: `.vscode/bin/phpcs` 検索順 — Homebrew の phpcs 4.x は WordPress Coding Standard（`^3.x` 必須）と非互換のため、Composer インストール版を先に検索するよう変更。

= 1.0.0-RC4 =
* 修正: mPDF インストール時の 404 — ダウンロード URL に `v` プレフィックスを付けていたが（`v1.0.0-RC3`）、リリースタグは `v` なし（`1.0.0-RC3`）のため 404 になっていた。`WPMAR_PDF_Installer::get_download_url()` の URL から `v` を除去。
* 修正: macOS で `bin/build-vendor-pdf-zip.sh` が不完全な zip を生成する問題 — `mktemp -d` がシンボリックリンク経由のパスを返すため `zip` がファイルを解決できなかった。`realpath` を追加して解決。

= 1.0.0-RC3 =
* 追加: PDF ライブラリ（mPDF）を管理画面からオンデマンドでインストールできる `WPMAR_PDF_Installer` を新規追加。設定ページの「PDF ライブラリ（mPDF）」セクションのボタンから GitHub Releases の `vendor-pdf.zip` を自動ダウンロード・展開する。プラグイン配布 zip から `vendor/` を除外することで、WordPress のアップロード制限（30 MB 前後）を超えていた問題を解消。
* 追加: `bin/build-vendor-pdf-zip.sh` — プロダクション依存のみを含む `vendor-pdf.zip` をローカルでビルドするスクリプト。
* 変更: `.github/workflows/release.yml` — プラグイン zip から `vendor/` を除外し、`vendor-pdf.zip` を追加アセットとして自動生成・GitHub Release に添付。
* 変更: インストール手順から手動 `composer install` の要件を撤廃。PDF ライブラリは管理画面から必要に応じてインストール可能。
* 修正: `.vscode/bin/phpcs` シム — VS Code 拡張機能ホストの制限された PATH 環境でも動作するよう、PHP バイナリと phpcs スクリプトを既知パスから検索し、常に `php phpcs_script` の形式で実行するよう改善。

= 1.0.0-RC2 =
* 修正: `WPMAR_GitHub_Updater` — プラグイン有効化時の fatal error を修正。PHP のクラス定数（`const`）に WordPress のランタイム定数（`HOUR_IN_SECONDS` / `MINUTE_IN_SECONDS`）を使用していたことが原因。リテラル整数に変更。
* 修正: PHP 7.4 非互換の `str_contains()` を `false !== strpos()` に置き換え。
* 変更: キャッシュ TTL およびバックオフ TTL を `wpmar_github_updater_cache_ttl` / `wpmar_github_updater_backoff_ttl` フィルターで上書き可能に。

= 1.0.0-RC1 =
* リリース候補版。新機能なし。スケジュール監査、マルチサイト ネットワーク集約、チェックサム、運用セキュリティ、メール／PDF／CLI 出力、レポート保存、GitHub Releases 自動アップデートの全サブシステムのエンドツーエンドテストを経て `0.x` 開発シリーズから昇格。

= 0.11.0 =
* 追加: `WPMAR_GitHub_Updater` — GitHub Releases 自動アップデート対応。`pre_set_site_transient_update_plugins` フィルターで最新リリースの有無を確認してトランジェントに更新情報を注入。`plugins_api` フィルターで「バージョンの詳細を表示」モーダルにリリース情報を提供。`upgrader_process_complete` アクションでアップデート完了後にリリースキャッシュを削除。API レスポンスは 6 時間キャッシュ。エラー・レート制限時は 30 分バックオフ。GitHub Release に添付したアセット zip を優先し、自動生成の zipball よりも正確なディレクトリ構造でプラグインフォルダに展開されるよう考慮。

= 0.10.2 =
* 変更: リリース workflow のトリガを `'v[0-9]*'` と `'[0-9]*'` の両方に拡張。本プロジェクトでは WordPress.org Stable tag の慣習に合わせ **`v` 無しの数字タグ**（例 `0.10.2`）を用いるため、従来の `'v*'` のみの設定では `0.10.1` タグの push が拾われずに workflow が起動しなかった。バージョン抽出ロジック（`${TAG#v}`）は両形式に対応済み。

= 0.10.1 =
* 修正: v0.10.0 で `.github/workflows/ci.yml` のタブ→スペース修正により Actions がジョブを実行するようになった結果、`CI / phpcompat (8.0 / 8.2 / 8.3)` が PHPCS ステップで失敗していた問題を解消（既存の `tests/*` 内の WPCS 違反、および `class-wpmar-runner.php` の等号アラインメント・インラインコメント終端記号）。
* 修正: `includes/class-wpmar-runner.php` の等号アラインメント警告 3 件を phpcbf で自動修正。バックアップセクションの無効化コメントを「説明文」に書き換えて `Squiz.Commenting.InlineComment.InvalidEndChar` に適合。
* 変更: `phpcs.xml.dist` に `<exclude-pattern>tests/*</exclude-pattern>` を追加。PHPUnit テストは PHPUnit 規約（`camelCase` メソッド・最小限の doc）に従うため WPCS の対象外とし、`includes/` 配下の本番ソースだけを引き続き厳格に検査。

= 0.10.0 =
* 修正: テーマ／プラグインの最新バージョン判定を `version_compare()` ベースに変更。インストール版＞ディレクトリ版の場合は「データが正しく取得できませんでした。」と表示（従来は誤って「アップデートあり」と表示）。
* 修正: 管理者メールで「◯◯ は非公式か、既に公開終了しているプラグインです。」と「このプラグインは非公式か既に公開終了している可能性があります。」が重複していたのを1行に統合（「◯◯ は非公式か、既に公開終了している可能性があります。」）。
* 修正: チェックサム差分のファイル一覧のインデントを1段深く（`　　　　`）。
* 修正: `.github/workflows/ci.yml` のインデントをタブ→スペースに修正。YAML パース失敗により GitHub Actions が「No jobs were run」と通知していた不具合を解消。マトリクスに `fail-fast: false` を追加。
* 変更: 管理者向けメール本文から **【バックアップ状況】** セクションを非表示（取得機能が未実装のため）。`render_operator_backup_section()` 等のコードは将来のために残存。
* 追加: `.github/workflows/release.yml` を新規追加。`v*` タグ push 時にプラグインヘッダの `Version:` と一致するか検証し、`composer install --no-dev` を実行、開発専用ディレクトリ（`.git` / `.github` / `tests` / `phpunit.xml.dist` / `phpcs.xml.dist` など）を除外した `wp-maintenance-audit-reporter.<version>.zip` を作成、CHANGELOG から該当バージョンのリリースノートを抽出して GitHub Release を発行。
* テスト: `WPMAR_Runner::directory_version_status()` の新規ユニットテスト 4 件を追加。

= 0.9.0 =
* セキュリティ: 管理画面ハンドラでノンス検証（`check_admin_referer()`）を権限チェックより先に実行するよう修正（CSRF 強化）。
* セキュリティ: ファイルパスに `..` が含まれる場合はアップロード相対パスの構築前に拒否（パストラバーサル防止）。
* セキュリティ: ノーティファイアの QA メール文字列分岐に `is_email()` 検証を追加。
* 修正: タイムゾーン入力を PHP の `timezone_identifiers_list()` に照合して検証。無効・空の値は `Asia/Tokyo` にフォールバック。
* 修正: SSL プローブが証明書検証ありで先に接続を試み、失敗時のみ検証なしにフォールバック（証明書期限切れなど）。結果にバイパスの有無を記録。
* 修正: PDF ストリームハンドラが `readfile()` の戻り値を確認し、失敗時は `wp_die()` を呼ぶように。
* 修正: ネットワーク管理の成功通知が `$_GET` の値を `'1'` と厳密に照合するように（存在確認のみから変更）。
* 変更: データコレクターが `call_user_func()` を `try/catch (Throwable)` でラップし、カスタムコレクターのエラーで監査全体が止まらないように。
* 変更: Cron エラーログが `WP_DEBUG_LOG` でも出力されるように（`WP_DEBUG` のみだった）。
* 変更: アクティベーターのホスト検出を `WPMAR_Domain_Gate::current_host()` に委譲。
* CI: `composer audit --no-dev` ステップを追加。
* テスト: 新規 28 件のユニットテスト（設定ヘルパー、タイムゾーンホワイトリスト、ドメインゲートのホスト・パス照合、ネットワーク設定マージ）。

= 0.8.0 =
* マルチサイト ネットワーク集約監査: ネットワーク有効化後、ネットワーク管理 → Maintenance Audit でロールアップを有効化。全対象ブログを `switch_to_blog` で巡回し、メインサイトに集約レポートを保存、メールを 1 回ずつ送信。
* ネットワーク設定（`wpmar_network_settings` sitemeta）: スケジュール、メール、出力、保持期間、サイトフィルタ、ドメインフォールバック／パスプレフィックス。
* ドメインゲート: サブディレクトリ型マルチサイト向けの `allowed_path_prefix`（ネットワーク設定＋サイト別フォールバック）。
* WP-CLI: `wp maintenance-audit run --network`。
* ネットワーク管理 UI: 設定、ドライラン、手動ロールアップ実行、メインサイトのレポートへのリンク。
* サイト UI: ネットワーク ロールアップ有効時は手動実行を無効化し、ネットワーク設定へのリンクを案内。

= 0.7.0 =
* **設定・実行**: 手動の **今すぐ実行** 向けに **「スナップショットを保存する（差分比較用）」** を追加。オフでも変更履歴は保存済み最新と今回収集の比較。WP-Cron / WP-CLI は従来どおり常に保存。
* **テストメール上書き先**: **今すぐ実行** で、入力があれば **クライアント向け**・**管理者向け** をそれぞれ追加送信（既に該当宛先に含まれる場合はその種をスキップ）。**テストメール付き実行** ボタンは廃止。

= 0.6.0 =
* メール: クライアント向け **HTML**（Markdown→Parsedown）、プレーンの代替本文、mainte 相当の件名、管理者向けは **整形プレーン**（RAW JSON を廃止）。
* レポート: WordPress.org の `last_updated` に基づく **更新停滞プラグイン**の節（180 日 / 365 日）。クライアント向け定型の「自動生成…」一文を削除。

= 0.5.0-dev =
* フック: `wpmar_report_sections` / `wpmar_notification_channels` / `wpmar_backup_providers`。
* 任意のデータベースサイズチェック（既定 OFF）: 設定でオンにしたときのみ `information_schema` により上位テーブルサイズを集計。
* `examples/` に Slack／汎用 JSON webhook／バックアップ備考のサンプル。

= 0.4.1-dev =
* CLI: `maintenance-audit export` に `--file=<path>` を追加（markdown / json / pdf）。他プラグインが bootstrap で Notice を出す環境では PDF などをファイルへ書き出す用途向け。

= 0.4.0-dev =
* PDF（クライアント向け）: mPDF/Parsedown により監査実行時に uploads/wpmar/pdf へ保存（設定で制御）。
* ZIP: 選択行の Markdown（管理者向け）と PDF（クライアント向け）を一括ダウンロード。
* 管理画面・CLI: レポート単位の Markdown（管理者向け）／PDF（クライアント向け）取得、CLI `export --format=pdf`。

= 0.3.0-dev =
* 運用セキュリティ: TLS 期限、PHP EOL マップ、スタック簡易ヒント、管理者セッション、`wp-config` 権限、本番デバッグ警告。
* 設定: SSL 検査の ON/OFF、管理者未ログイン閾値。
* レポート: データセットに security、クライアント向けメールに節を追加。summary_json にセキュリティ集計。

= 0.2.0-dev =
* チェックサム: コア・プラグイン検証、管理画面の除外設定、マニフェストが空のときのロケール フォールバック。
* 設定: 保持月数（0 / 12 / 24）、コア・プラグインのチェックサム除外フィールド。
* ランナー: レポート保存後の保持期間に応じた削除。Markdown / チェックサムコンテキストの本文への反映（実装どおり）。
* 管理: トップレベル Maintenance Audit メニュー。レポート一覧（ページネーション、削除と一括削除、トランジェントのフラッシュ通知）。従来の `wpmar_msg` を URL から除去。
* 品質: Composer 経由の PHPCS（WPCS）と PHPUnit の枠組み。

= 0.1.0-dev =
* 初期スキャフォールド: 有効化、テーブル、アンインストール時の削除。
