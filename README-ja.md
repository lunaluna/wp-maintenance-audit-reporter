# WP Maintenance Audit Reporter

WordPress 用プラグイン：コア・テーマ・プラグインの定期保守監査 — **v0.5.0-dev**（開発中）。

WordPress.org 形式のメタデータと変更履歴は [readme-ja.txt](readme-ja.txt)（日本語） / [readme.txt](readme.txt)（英語）を参照してください。

English: [README.md](README.md).

## v0.4 で追加されること

- **PDF（クライアント向け）** — フル実行時に PDF を `uploads/wpmar/pdf/` へ保存（mPDF / Parsedown。プラグイン直下で `composer install`）。PDF は保存済みの **クライアント向け Markdown（`body_client_md`）** をソースにします。レポート詳細画面のプレビューは **管理者向け Markdown（`body_md`）** です。**設定・実行** で ON/OFF。
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

Composer の開発ツールおよび **PDF 用ランタイム依存**（mPDF / Parsedown）: CI およびローカルで `composer install` には **PHP 8.0+**。プラグイン本体は PHP 7.4 で動く構文に収めているため、サイトは将来まで PHP 7.4 のままにできます。

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

### 配布用 ZIP（GitHub リリース — 予定）

リポジトリを GitHub と連携したあとの一般的なリリース手順の例です。

1. **タグ push**（または Release 公開）時に **`composer install --no-dev --optimize-autoloader`** を実行し、`vendor/` にランタイム依存（PDF 用ライブラリを含む）だけを入れる。
2. **`tests/`**・**`.github/`**・**`phpunit.xml.dist`**・**`phpcs.xml.dist`** など開発用のみのパスをアーカイブから除外する。**`.distignore`**（よくある WordPress プラグイン向けデプロイワークフローが解釈する）や、ワークフロー内の明示的文件リストで対応できる。
3. できあがった ZIP を **GitHub Release に添付**する（WordPress.org SVN への公開がある場合はその手順とも組み合わせる）。

PR 向け CI はこれまでどおり **`composer install`（dev 込み）** で PHPCS / PHPUnit を回し、**リリース用ジョブだけ `--no-dev`** にする、という切り分けがよく使われます。

## ライセンス

GPLv2 以降。詳細は [LICENSE](LICENSE)。
