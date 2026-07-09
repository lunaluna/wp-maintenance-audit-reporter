=== WP Maintenance Audit Reporter ===
Contributors: lunaluna_dev
Tags: maintenance, report, security, backup, audit
Requires at least: 6.0
Tested up to: 7.0.1
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress の保守向けレポート（コア・テーマ・プラグイン、チェックサム、差分、任意のメール、レポート蓄積、WP-CLI）を扱うプラグインです。英語の説明は readme.txt を参照してください。

== 概要 ==

月次の保守レポートを自動生成し、メール・Markdown・PDF で出力する WordPress プラグインです。

* **月次スケジュール監査** — 実行日・時刻・タイムゾーンを指定して自動実行（Action Scheduler による非同期ジョブ。WP-Cron ベース）。
* **インベントリと変更履歴（差分）** — コア・テーマ・プラグインの構成をスナップショットとして記録し、前回からの追加・更新・削除をレポート。
* **チェックサム検証** — WordPress.org API によるコア・プラグインのファイル改ざんチェック（除外リスト対応。サイトロケールのマニフェストが無い場合は en_US にフォールバック）。
* **セキュリティ診断** — SSL 証明書期限、PHP の EOL、管理者アカウントの長期未ログイン、`wp-config.php` のパーミッション、本番環境での `WP_DEBUG` 警告など。
* **更新停滞プラグイン検出** — WordPress.org の最終更新から 180 日 / 365 日以上経過したプラグインを報告。
* **メール通知（2 系統）** — クライアント向け（HTML、プレーンテキスト代替付き）と管理者向け（整形プレーンテキスト）。
* **Markdown / PDF 出力** — 管理者向け Markdown とクライアント向け PDF（mPDF + Noto Sans JP。ライブラリは設定画面からオンデマンドインストール）。
* **レポート管理画面** — 一覧・詳細プレビュー・ダウンロード・ZIP 一括取得・保持期間による自動削除。
* **動作ログ（診断）** — 監査実行のステップごとのログ、レポート画面のログビューアとダウンロード、強制終了されたジョブの自動復旧により、途中で止まった実行を最後のステップまで追跡できます。
* **マルチサイト対応** — ネットワーク集約監査（全対象サイトを巡回し、集約レポートをメインサイトに保存）。
* **WP-CLI 対応** — 監査の実行・エクスポート。
* **GitHub Releases 自動アップデート** — WordPress.org 非掲載のまま管理画面からワンクリック更新。

各バージョンの詳しい変更内容は CHANGELOG.md（プラグインに同梱）または下記の変更履歴を参照してください。

== 使い方 ==

プラグインを有効化すると、管理画面に **Maintenance Audit** メニュー（**設定・実行** / **レポート**）が追加されます。

最小構成での始め方: **設定・実行** で「メール通知」を有効化して宛先を入力し、**変更を保存** → **ドライラン** で内容を確認 → **今すぐ実行**。

= 設定・実行 画面 =

**ステータス** — 設定項目ではなく現在の状態を表示するパネルです。

* **次回 WP-Cron** — 次回の定期実行予定時刻。
* **直近の完了時刻 (UTC 保存)** — 最後に監査が完了した時刻。
* **WP-CLI** — CLI コマンドの実行が検出済みかどうか（バージョンと最終実行時刻）。一度も CLI から実行していない場合は「未取得」と表示されます。

**スケジュール:**

* **実行日 (1〜31)** — 月次実行の日。
* **時刻（時 / 分）** — 実行時刻。
* **タイムゾーン** — 例: `Asia/Tokyo`。PHP が解釈できる識別子を指定します。無効な値は `Asia/Tokyo` にフォールバックします。

**ドメインゲート:**

* **許可ホスト** — 「サイトのアドレス」のホスト名と突き合わせる本番判定用の設定です。フィールドの下に検出された現在のホストと、保存値との一致・不一致フィードバックが表示されます。
  * **未入力** — すべての環境でゲートを通過します（緩い設定）。
  * **一致** — 実行時にスナップショット・メール・Markdown/PDF ファイルが通常どおり保存・送信されます。
  * **不一致**（ステージング等） — 実行自体は可能ですが、スナップショット保存・メール送信・ファイル書き出しが抑止されます。本番のホスト名を入力しておくことで、コピー環境での誤送信・誤保存を防げます。

**セキュリティ診断（レポート）:**

* **SSL 証明書の期限確認** — 有効（推奨）にすると、サイトが https のときのみサーバーへ短時間接続して証明書期限を検査します。
* **管理者「長期未ログイン」の日数** — この日数（30〜730）より古い最終セッションの管理者を「注意」としてレポートに数えます。

**オプション：データベースサイズチェック:**

* **上位テーブルサイズを集計** — チェックを入れたときのみ（既定 OFF）、監査中に `information_schema` を参照してテーブルサイズの上位サンプルを集計します。ホスティングによっては失敗することがあります。

**メール通知:**

* **有効化** — レポートメールの送信を有効にします。
* **クライアント向け宛先（改行区切り）** — HTML レポートの送信先。複数指定可。
* **管理者向け宛先（改行区切り）** — 詳細なプレーンテキストレポートの送信先。複数指定可。
* **送信元メールアドレス（オプション）** — 未入力の場合はサイトの管理者メールアドレスを使用します。
* **送信元表示名（オプション）** — 未入力の場合はサイト名を使用します。

メール通知が有効なのに宛先が空の場合は、設定画面に警告が表示されます。

**チェックサム除外リスト** — 意図的に変更しているファイル（翻訳ファイルの手修正など）を改ざん検知から除外します。1 行に 1 エントリ、`#` で始まる行はコメントです。

* **コア除外パス** — ABSPATH からの相対パス（例: `wp-config.php`）。
* **プラグイン除外パス** — `スラッグ:相対パス` 形式（例: `akismet:readme.txt`）。

どちらも末尾に `/` または `/*` を付けるとディレクトリ以下をまとめて除外できます（例: `wp-admin/`、`akismet:views/`）。

**保持期間:**

* **レポート保管期間** — 「無期限」「12 ヶ月より古いレポートを削除」「24 ヶ月より古いレポートを削除」から選択。最新の実行から起算して、古いレポートの DB 行と生成済みファイル（Markdown / PDF）を自動削除します。

**レポートをファイルとして自動保存:**

* **Markdown を uploads に書き出して保存（管理者向け）** — 実行時に `wp-content/uploads/wpmar/` へ `.md` ファイルを保存します。
* **PDF を uploads に書き出して保存（クライアント向け）** — 実行時に `uploads/wpmar/pdf/` へ PDF を保存します。PDF ライブラリが未インストールの場合は警告が表示され、この設定は機能しません。

**PDF ライブラリ（mPDF）** — PDF 生成に使う mPDF のインストール状況を表示するパネルです。未インストールの場合はボタン 1 つで GitHub Releases から `vendor-pdf.zip` をダウンロードして展開します（サーバーでの `composer install` は不要）。自動ダウンロードが失敗する環境では、手動でダウンロードした ZIP をブラウザからアップロードするフォールバックが表示されます。

インストール（ダウンロード・手動アップロードとも）には `install_plugins` 権限が必要です（マルチサイトではネットワーク管理者のみ、`DISALLOW_FILE_MODS` 有効時は無効）。ZIP は隔離した一時ディレクトリで検証（絶対パス・`..`・シンボリックリンク・`vendor/`/`fonts/` 以外の最上位エントリを拒否）してから配置されます。

チェックサム固定（任意）: リリースには `vendor-pdf.zip.sha256` が同梱されます。その値を `wp-config.php` などで定数 `WPMAR_PDF_VENDOR_ZIP_SHA256` に設定するか、`wpmar_pdf_vendor_zip_sha256` フィルターで返すと、展開前に SHA-256 を照合し不一致なら中止します（未設定時は従来どおり照合なし）。

**検証ツール:**

* **テストメール上書き先** — メールアドレスを 1 件だけ指定できます。メール通知が有効なとき「今すぐ実行」を押すと、設定どおりの宛先への送信に加えて、クライアント向け・管理者向けレポートを各 1 通（最大 2 通）このアドレスにも追加送信します。既に宛先リストに含まれるアドレスの場合、該当する種類の重複送信はしません。

**スナップショットと実行ボタン:**

* **スナップショットを保存する（差分比較用）** — チェックを入れた「今すぐ実行」のみ DB のスナップショット行を更新します。チェックなしの手動実行はレポートのみ作成します。定期実行（WP-Cron）では常にスナップショットを保存します。差分は常に「保存済みスナップショット vs 今回の収集結果」で計算されます。
* **変更を保存** — 設定を保存します。
* **ドライラン** — データ収集のみ実行し、要約を画面に表示します。スナップショット保存・メール送信・ファイル書き出しは行いません。
* **今すぐ実行** — 監査をバックグラウンドジョブとして登録します。画面上部の通知と「レポート生成ジョブ」パネルが進捗（queued → running → 完了）をポーリング表示し、実行中は現在のステップ（例 `gather:checksums:start`）と最終更新からの経過秒数も表示されます。完了するとプレビュー／ダウンロードリンクが、失敗した場合は「動作ログをダウンロード」リンクが表示されます（詳しくは後述の「診断ログ（動作ログ）」を参照）。

= レポート 画面 =

生成済みレポートの一覧（20 件/ページ）と詳細を表示します。詳細画面では管理者向け Markdown をプレビューでき、Markdown（管理者向け）・PDF またはMarkdown（クライアント向け）を個別ダウンロードできます。一覧では一括操作「ZIP 一括ダウンロード」で複数レポートをまとめて取得できます。行削除・一括削除は**確認ダイアログなし**で即時実行されるため注意してください。

レポート一覧の下には**診断ログ**セクションがあり、ログファイルを持つ直近の監査ジョブ（状態・最終ステップ・更新日時）と末尾プレビュー、ダウンロードリンクを表示します。実行が途中で止まった、または失敗した際に、どこで止まったかを確認できます（詳しくは次の「診断ログ（動作ログ）」を参照）。

= 診断ログ（動作ログ） =

監査の実行中、各処理フェーズ（ステップ）ごとに1行ずつ記録される内部ログです。プロセスが途中で強制終了（サーバーのメモリ不足やタイムアウトなど）しても、直前のステップまでは記録が残るため、「どこで止まったか」を特定できます。

**ログの見かた** — 各行は次の形式です（実際のログの例）。

`[2026-07-09T00:51:19+00:00] [INFO] [job:cli-6a4ef05f3baf2] step: gather:checksums:start`

日時（UTC）・レベル（`INFO`/`ERROR`）・ジョブ ID・処理内容の順です。通常の1回の実行は次のステップを順にたどります： `lock:acquired` → `gather:start` → `gather:core-updates` → `gather:inventory-done` → `gather:checksums:start` → `gather:checksums:done` → `gather:security-ops:start` → `gather:security-ops:done` → `gather:done` → `diff:done` → `persist-snapshots:done` → `render:done` → `md-write:done` → （メール有効時）`mail:start` → `mail:done` → `report-insert:done` → `notify:done` → （PDF有効時）`pdf:start` → `pdf:done` → `retention:done` → `reschedule:done` → `job ended`。

**実行が途中で止まった場合、ログの最後の行が「最後に完了した処理」です** — 例えば最後の行が `gather:checksums:start` であれば、チェックサム検証の最中に停止したと分かります。致命的なエラーが起きた場合は `[ERROR] FATAL: ...` という行が追加で記録されます。プロセスが強制終了（`SIGKILL` や OOM キラーなど）されてこの記録すら書き込めなかった場合でも、約25分間更新のないジョブは自動的に「失敗」に切り替わり、エラー内容に「ハートビート途絶」と表示されます。

**ログの取得方法（サポート依頼時など）** — レポート画面の「診断ログ」セクションから、ログを持つ直近のジョブ一覧を確認し、「表示」で末尾（最新約200行）をその場で確認、「ダウンロード」でファイルを取得できます。実行中・失敗したジョブの進捗パネル（設定・実行画面）にも、失敗時は「動作ログをダウンロード」リンクが表示されます。いずれも `manage_options` 権限とジョブごとの nonce で保護されているため、サポート依頼時にログファイルをそのまま共有しても問題ありません（設定のパスワードなどの秘匿情報はログに記録されません）。WP-CLI での同期実行（`wp wpmar audit run --sync`）でもログは生成されます（ジョブ ID は `cli-` で始まります）が、管理画面のジョブ一覧には出ないため、サーバー上で直接 `wp-content/uploads/wpmar/logs/` を確認してください。

保存場所は `wp-content/uploads/wpmar/logs/`（ファイル名に推測不能なランダムトークンを付与し、`.htaccess` で直接アクセスから保護）です。直近20回分のみ保持され、古いログは実行のたびに自動削除されます。プラグインのアンインストール時にはディレクトリ全体が削除されます。

= ネットワーク管理（マルチサイト） =

プラグインをネットワーク有効化すると、**ネットワーク管理 → Maintenance Audit** で集約監査を設定できます。全対象サイトを巡回してクライアント向け・管理者向け各 1 本の集約レポートをメインサイトに保存し、メールも 1 回ずつ送信します。シングルサイト版とほぼ同じ設定項目に加えて、対象サイトのフィルター（最大サイト数・除外 blog ID）と「実行範囲」セレクター（すべての対象サイト / 親サイトのみ / 特定のサイトのみ）があります。

= WP-CLI =

本プラグインは 2 つのコマンド名前空間を登録します。`wp wpmar audit`（現行のエントリポイント。非同期ジョブ基盤を経由し、`--sync` で同期実行にフォールバック）と `wp maintenance-audit`（レガシー名前空間。レポート管理用サブコマンドも備える）です。どちらの `run` も、成功時に実行結果を整形済み JSON で出力します。

**`wp wpmar audit run`** — 監査を実行します。

    wp wpmar audit run --sync [--dry-run] [--network] [--no-snapshot]

* `--sync` — 必須。現在のプロセスで同期実行します（非同期キューは未実装のため、指定しないとエラー）。本番デバッグや手動運用での CloudFront タイムアウト回避にも使えます。
* `--dry-run` — データ収集のみ。スナップショット保存・メール送信なし。
* `--network` — マルチサイト集約監査（ネットワーク管理 → Maintenance Audit で有効化が必要）。
* `--no-snapshot` — スナップショットの基準を更新せずにレポートのみ生成。

`--same-setting` / `--id` はこのコマンドにはありません。サイト単位のネットワーク指定はレガシーの `wp maintenance-audit run` を使ってください。

**`wp maintenance-audit run`**（レガシー） — 監査を同期実行します。

    wp maintenance-audit run [--dry] [--network] [--no-snapshot] [--same-setting] [--id=<blog_id>]

* `--dry` — データ収集のみ。保存・メール送信なし。※このレガシーコマンドは `--dry`、`wp wpmar audit run` は `--dry-run` である点に注意。
* `--network` — マルチサイト集約監査（ネットワーク監査の有効化が必要）。
* `--no-snapshot` — スナップショットの基準を更新せずにレポートのみ生成。
* `--same-setting` — `--network` が前提。全対象サイトではなく親サイトのみを監査。
* `--id=<blog_id>` — `--network` が前提。指定した blog ID のみを監査。`--same-setting` より優先され、存在しない blog ID はエラー。

**`wp maintenance-audit test`** — コレクターをドライモードで実行（CLI プローブ用トランジェント以外の DB 書き込みなし）。フラグなし。

    wp maintenance-audit test

**`wp maintenance-audit reports`** — 保存済みレポートの一覧を表形式で表示します。

    wp maintenance-audit reports [--limit=<n>]

* `--limit=<n>` — 取得する行数（既定 20）。

**`wp maintenance-audit delete <id>`** — 保存済みレポートを完全に削除します。

    wp maintenance-audit delete <id> [--yes]

* `<id>` — レポートの数値 ID（必須）。
* `--yes` — 確認プロンプトを省略。

**`wp maintenance-audit export <id>`** — レポート成果物を STDOUT に出力（パイプ用）、またはファイルへ書き出します。

    wp maintenance-audit export <id> [--format=<markdown|json|pdf>] [--file=<path>]

* `<id>` — レポートの主キー（必須）。
* `--format=<fmt>` — `markdown`（既定。管理者向け `body_md`）／ `json`（レポート行全体）／ `pdf`（クライアント向け）。`md` は `markdown` のエイリアス。
* `--file=<path>` — STDOUT ではなくこのパスへ書き出し（他プラグインが CLI ブートストラップ時に PHP Notice を出す環境での PDF 取得に推奨）。親ディレクトリが存在し書き込み可能である必要があります。

== インストール方法 ==

1. プラグインフォルダを `/wp-content/plugins/` にアップロードします
2. WordPress の **プラグイン** メニューから有効化します
3. PDF 出力が必要な場合は、**設定・実行** ページの「PDF ライブラリ（mPDF）」セクションから **「PDF ライブラリをインストール」** ボタンを押します。GitHub Releases から `vendor-pdf.zip`（約 94 MB）を自動ダウンロード・展開します。インストールには `install_plugins` 権限が必要です。

== よくある質問 ==

= 本番環境で使えますか？ =

はい。1.0.0 が最初の安定版リリースです。主要サブシステム全体のエンドツーエンドテストを経て 1.0.0-RC 系列から昇格しました。WordPress 7.0.1 まで動作確認済みです。

= 「設定」のサブメニューがなくなったのはなぜですか？ =

v0.2 以降、管理 UI は専用のトップレベル **Maintenance Audit** メニュー配下（サブメニュー **設定・実行** と **レポート**）です。URL は `wp-admin/admin.php?page=…` になり、`options-general.php?page=…` は使いません。

== 開発 ==

WordPress / 実行環境の目安: **PHP 7.4+**。WordPress **6.0+**、動作確認済みは **7.0.1**。

Composer の開発ツールおよびランタイム依存（mPDF / Parsedown／PDF・クライアント向け HTML メール）は、CI およびローカルの `composer install` に **PHP 8.0+** が必要です。プラグイン本体は PHP 7.4 で動く構文に収めているため、サイトは将来まで PHP 7.4 のままにできます。

`vendor/` はリポジトリに含まれません（`.gitignore` 参照）。依存は `composer.json` にあり `composer.lock` で固定されています。クローン後は一度 `composer install` が必要です。

    cd wp-content/plugins/wp-maintenance-audit-reporter
    composer install

コーディング規約とテスト:

    composer run phpcs
    composer run phpunit

**配布用 ZIP（GitHub リリース）** — `.github/workflows/release.yml` として実装済み。`v*` タグまたは v 無しの数字タグの push（または手動 `workflow_dispatch`）で起動します。

1. タグが `wp-maintenance-audit-reporter.php` の `Version:` ヘッダと一致するか検証（不一致ならジョブ失敗）。
2. `composer install --no-dev --optimize-autoloader` でプロダクション依存をインストール。
3. プラグインツリーをステージング（`.git` / `.github` / `tests/` / `vendor/` / `phpunit.xml.dist` / `phpcs.xml.dist` などを除外）し、`wp-maintenance-audit-reporter.<version>.zip` として圧縮。
4. インストール済みの `vendor/` から `vendor-pdf.zip`（および `vendor-pdf.zip.sha256`）を作成し、管理画面からのオンデマンドインストール用アセットとして添付。
5. `CHANGELOG.md` から該当 `## [version]` 節をリリースノートとして抽出。
6. `gh release create` で GitHub Release を発行し、アセットを添付。

== Git 管理 ==

このプラグインをプロジェクト内で Git 管理している場合、以下の2ディレクトリはオンデマンドで生成されるため `.gitignore` に追加することを推奨します。

  wp-content/plugins/wp-maintenance-audit-reporter/fonts/
  wp-content/plugins/wp-maintenance-audit-reporter/vendor/

`fonts/` は同梱の PDF フォント（Noto Sans JP Regular/Bold、`vendor-pdf.zip` から展開）と、mPDF が生成時に書き込むフォントメトリクスキャッシュの置き場です。`vendor/` は PDF ライブラリ（mPDF）のオンデマンドインストール先です。

== 変更履歴 ==

= 1.1.1 =
* 変更：レポートの「ユーザー情報」セクションをタブ区切りテキストから Markdown テーブルに変更し、クライアント向け PDF で罫線付きテーブルとして表示されるようにしました。クライアント向け・管理者向け両方のレポート本文に適用されます。
* ドキュメント：診断ログ（動作ログ）の見かた・取得方法を使い方セクションに追記しました。
* 詳細は CHANGELOG.md を参照してください。

= 1.1.0 =
* 監査実行の動作ログ（診断）機能を追加。プロセスが強制終了しても直前まで残る、ジョブごとのステップログ、強制終了されたジョブの自動復旧、レポート画面でのログビューアとnonce保護ダウンロードに対応。
* 詳細は CHANGELOG.md を参照してください。

= 1.0.0 =
* 最初の安定版リリース。1.0.0-RC 系列から昇格し、監査・レポート機能に変更はありません。WordPress 7.0.1 まで動作確認済み。
* セキュリティ: PDF ライブラリインストーラーの任意コード実行を修正。vendor バンドルを隔離した一時ディレクトリで検証（絶対パス・`..`・シンボリックリンク・想定外の最上位エントリを拒否）してから配置し、プラグインディレクトリへ直接展開しません。展開直後のコード実行も廃止。インストールには `install_plugins` 権限が必要になり、アップロードは `is_uploaded_file()` とサイズ上限で検証、任意の SHA-256 照合（`WPMAR_PDF_VENDOR_ZIP_SHA256` / `wpmar_pdf_vendor_zip_sha256`）に対応。
* セキュリティ（多層防御）: 設定・一括処理ハンドラーで権限チェックを nonce 検証より前に統一。uploads 相対パスはシンボリックリンクを解決して uploads ルート内に限定。レポートダウンロードの GET はデータベースを変更しません。
* 詳細は CHANGELOG.md を参照してください。

= 1.0.0-RC14 =
* 変更: PDF 埋め込みフォントを BIZ UDGothic から Noto Sans JP（Regular + Bold）に変更。mPDF は CFF/OpenType アウトラインを埋め込めず、Noto Sans JP は可変フォント（1ファイル・太字の区別なし）でのみ配布されているため、リリースビルドで fontTools によりウェイト軸を静的な Regular（400）/ Bold（700）の TrueType にインスタンス化します（`bin/build-vendor-pdf-zip.sh`、`.github/workflows/release.yml`）。グリフはフル収録のまま（mPDF が PDF ごとにサブセット化）。`WPMAR_PDF_Writer` は `notosansjp`（`NotoSansJP-Regular.ttf`/`NotoSansJP-Bold.ttf`）を登録し、無い場合は `sun-exta` にフォールバック。
* 変更: PDF ライブラリ設定パネルが古いバンドル（mPDF はあるが Noto フォントが無い状態、`WPMAR_PDF_Installer::fonts_present()` で判定）を検知し、最新の `vendor-pdf.zip` を再ダウンロードする再インストール導線を表示。プラグイン更新だけではフォントは切り替わりません。`maybe_cleanup_legacy_fonts()` は旧 `BIZUDGothic-Regular.ttf`/`BIZUDGothic-Bold.ttf` も削除します。

= 1.0.0-RC13 =
* 変更: クライアント向けレポートでテーマ・プラグイン名をスラッグから表示名に変更 — クライアント向けメール・PDF の変更履歴セクションとファイル改ざんチェック（チェックサム）セクションで、スラッグ（`snow-monkey`、`advanced-query-loop`）ではなく表示名（例: `Snow Monkey`、`Advanced Query Loop`）を表示するようにしました。スナップショットデータはスラッグのまま保持し、変換は出力層でのみ行います。管理者向けメールと Markdown エクスポートは従来どおりスラッグを維持します。表示名が取得できない場合（例: 削除済みでインベントリに無いプラグイン）はスラッグにフォールバックします。

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
