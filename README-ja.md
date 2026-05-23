# WP Maintenance Audit Reporter

WordPress 用プラグイン：コア・テーマ・プラグインの定期保守監査 — **v0.10.2**。

WordPress.org 形式のメタデータと変更履歴は [readme-ja.txt](readme-ja.txt)（日本語） / [readme.txt](readme.txt)（英語）を参照してください。

English: [README.md](README.md).

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
# 1. wp-maintenance-audit-reporter.php / WPMAR_VERSION / composer.json / CHANGELOG.md を新バージョンに更新
git commit -am "release: 0.10.2"
git push origin main

# 2. タグを打って push（release.yml が起動）。Stable tag 風の v 無し表記:
git tag 0.10.2
git push origin 0.10.2
# （v0.10.2 のような v 付きタグも受け付けます）
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

- **PDF（クライアント向け）** — 監査実行時に PDF を `uploads/wpmar/pdf/` へ保存（mPDF / Parsedown。プラグイン直下で `composer install`）。PDF は保存済みの **クライアント向け Markdown（`body_client_md`）** をソースにします。レポート詳細画面のプレビューは **管理者向け Markdown（`body_md`）** です。**設定・実行** で ON/OFF。
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

WordPress **6.0+**。

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
2. **`composer install --no-dev --optimize-autoloader`** を実行し、`vendor/` にランタイム依存（mPDF / Parsedown）だけを入れる。
3. `wp-maintenance-audit-reporter/` ディレクトリにステージング（`.git` / `.github` / `tests/` / `phpunit.xml.dist` / `phpcs.xml.dist` / `.phpunit.result.cache` などを除外）し、`wp-maintenance-audit-reporter.<version>.zip` として圧縮。
4. `CHANGELOG.md` から該当 `## [version]` 節をリリースノートとして抽出（無ければ汎用の文言にフォールバック）。
5. `gh release create` で GitHub Release を作成し、zip を添付。

PR 向け CI（**`.github/workflows/ci.yml`**）はこれまでどおり **`composer install`（dev 込み）** で PHPCS / PHPUnit を回します。

## ライセンス

GPLv2 以降。詳細は [LICENSE](LICENSE)。
