=== WP Maintenance Audit Reporter ===
Contributors: lunaluna_dev
Tags: maintenance, report, security, backup, audit
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.4.1-dev
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress の保守向けレポート（コア・テーマ・プラグイン、チェックサム、差分、任意のメール、レポート蓄積、WP-CLI）を扱うプラグインです。英語の説明は readme.txt を参照してください。

== 概要 ==

開発版 **v0.4.1-dev** では、PDF による保存、レポート一覧からの ZIP 一括ダウンロード、CLI の export 改善、および v0.3 のセキュリティ関連シグナルを扱います。

* **PDF（任意）** — フル実行のたびに `uploads/wpmar/pdf/*.pdf` を生成可能（クライアント向け Markdown をソースとする）。ランタイム依存のためプラグイン直下で `composer install` が必要です。
* **ZIP 一括** — レポート一覧で選択した行の `.md` と保存済み `.pdf` を ZIP で取得します。
* **CLI export** — `wp maintenance-audit export <id> --format=markdown|json|pdf`。`--file=<path>` でファイルへ書き出し（他プラグインが bootstrap で Notice を出す環境での PDF 取得向け）。
* **未取得の案内** — **設定・実行** と **レポート** で、レポート行もスナップショット行も無いときに案内を表示します。

* **スケジュール** — 月次の WP-Cron を基準に、任意で WP-CLI 経由のサーバー cron も利用可能。
* **棚卸しと差分** — コア・テーマ・プラグインの差分をスナップショット間で検出。
* **チェックサム** — WordPress.org のマニフェストと照合してコア・プラグインを検証。除外リストを設定可能。サイト言語のマニフェストが取得できない場合はロケールのフォールバック。
* **ドメイン制限** — 許可したホスト以外（ステージングなど）ではスナップショットとレポートの副作用をスキップ。
* **出力** — 詳細な Markdown（アップロード先）と、任意の HTML メール（クライアント向け・運用者向けのペア）。
* **レポート保存** — データベーステーブルと対になる Markdown パス。**保持期間**（無期限 / 12 / 24 ヶ月）に応じて、正常完了後に古い行とファイルを削除。
* **管理画面** — トップレベル **Maintenance Audit** メニュー（`admin.php` 画面）。**設定・実行**（スケジュール、メール、除外、保持、実行）と **レポート**（一覧テーブル、1 ページ 20 件、詳細、Markdown/PDF のダウンロード、ZIP 一括エクスポート、確認なしの行単位・一括削除。成功通知はトランジェントのフラッシュ表示で、クエリ引数の貼り付けはしない）。

無人実行や CI 的な確認には WP-CLI を利用してください。

== インストール方法 ==

1. プラグインフォルダを `/wp-content/plugins/` にアップロードします
2. WordPress の **プラグイン** メニューから有効化します
3. 任意: PHPCS / PHPUnit などの開発ツールが必要な場合は、プラグイン直下で `composer install` を実行します（詳細は README.md）

== よくある質問 ==

= 本番環境で使えますか？ =

まだです。安定版がタグ付けされるまでは開発版として扱ってください。

= 「設定」のサブメニューがなくなったのはなぜですか？ =

v0.2 以降、管理 UI は専用のトップレベル **Maintenance Audit** メニュー配下（サブメニュー **設定・実行** と **レポート**）です。URL は `wp-admin/admin.php?page=…` になり、`options-general.php?page=…` は使いません。

== 変更履歴 ==

= 0.4.1-dev =
* CLI: `maintenance-audit export` に `--file=<path>` を追加（markdown / json / pdf）。他プラグインが bootstrap で Notice を出す環境では PDF などをファイルへ書き出す用途向け。

= 0.4.0-dev =
* PDF: mPDF/Parsedown によりフル実行時に uploads/wpmar/pdf へ保存（設定で制御）。
* ZIP: 選択行の Markdown / PDF を一括ダウンロード。
* 管理画面・CLI: レポート単位の Markdown/PDF 取得、CLI `export --format=pdf`。

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
