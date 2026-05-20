=== WP Maintenance Audit Reporter ===
Contributors: lunaluna_dev
Tags: maintenance, report, security, backup, audit
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress の保守向けレポート（コア・テーマ・プラグイン、チェックサム、差分、任意のメール、レポート蓄積、WP-CLI）を扱うプラグインです。英語の説明は readme.txt を参照してください。

== 概要 ==

**v0.7.0** では、**設定・実行** に **「スナップショットを保存する（差分比較用）」** を追加しました。**今すぐ実行** / **テストメール付き実行** でオンにすると手動実行のたびにスナップショット（DB）を更新し、オフのときはレポート・変更履歴は **今回の収集と保存済み最新スナップショットの比較** のままですが、スナップショット表は更新しません。**WP-Cron** と **WP-CLI** の実行では従来どおり常にスナップショットを保存します。**v0.6** のメール改善（HTML、更新停滞プラグイン、管理者プレーン整形）や **v0.5** 由来の拡張（`wpmar_report_sections` / `wpmar_notification_channels` / `wpmar_backup_providers`）、DB サイズサンプル、通知ディスパッチも利用できます。

* **メール（クライアント）** — Parsedown が利用可能なとき（`composer install` 済みの `vendor/`）**HTML 本文**。従来クライアント向け用のプレーンテキスト代替付き。HTML を止めたい場合はフィルター `wpmar_client_mail_html_enabled`。
* **PDF（クライアント向け／任意）** — 監査を実行するたびに `uploads/wpmar/pdf/*.pdf` を生成可能（**クライアント向け** Markdown をソースとする）。ランタイム依存のためプラグイン直下で `composer install` が必要です。
* **ZIP 一括** — レポート一覧で選択した行の **管理者向け** `.md` と保存済み **クライアント向け** `.pdf` を ZIP で取得します。
* **CLI export** — `wp maintenance-audit export <id> --format=markdown|json|pdf`。`markdown` は **管理者向け**、`pdf` は **クライアント向け**。`--file=<path>` でファイルへ書き出し（他プラグインが bootstrap で Notice を出す環境での PDF 取得向け）。
* **未取得の案内** — **設定・実行** と **レポート** で、レポート行もスナップショット行も無いときに案内を表示します。
* **スナップショット保存（手動実行・v0.7）** — **設定・実行** の **「スナップショットを保存する（差分比較用）」** は **今すぐ実行** / **テストメール付き実行** のみ。オフでも変更履歴は保存済み最新と今回収集の比較。**WP-Cron** / **WP-CLI** は常に保存。

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
3. 配布用 ZIP を作る前にプラグイン直下で **`composer install --no-dev`** を実行し、`vendor/` に **Parsedown / mPDF** を含めます（PDF・クライアント向け HTML メールに必要）。PHPCS / PHPUnit 用には開発者向けに `composer install`（README.md）。

== よくある質問 ==

= 本番環境で使えますか？ =

まだです。安定版がタグ付けされるまでは開発版として扱ってください。

= 「設定」のサブメニューがなくなったのはなぜですか？ =

v0.2 以降、管理 UI は専用のトップレベル **Maintenance Audit** メニュー配下（サブメニュー **設定・実行** と **レポート**）です。URL は `wp-admin/admin.php?page=…` になり、`options-general.php?page=…` は使いません。

== 変更履歴 ==

= 0.7.0 =
* **設定・実行**: 手動の **今すぐ実行** / **テストメール付き実行** 向けに **「スナップショットを保存する（差分比較用）」** を追加。オフでも変更履歴は保存済み最新と今回収集の比較。WP-Cron / WP-CLI は従来どおり常に保存。

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
