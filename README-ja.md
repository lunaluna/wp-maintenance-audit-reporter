# WP Maintenance Audit Reporter

WordPress 用プラグイン：コア・テーマ・プラグインの定期保守監査 — **v0.2.0-dev**（開発中）。

WordPress.org 形式のメタデータと変更履歴は [readme-ja.txt](readme-ja.txt)（日本語） / [readme.txt](readme.txt)（英語）を参照してください。

English: [README.md](README.md).

## v0.2 で追加されること（0.1 のスキャフォールドに対して）

- **チェックサム** — WordPress.org API によるコア・プラグインの検証。設定で除外リスト。サイトロケールで利用可能なマニフェストがない場合のフォールバック。
- **保持** — 12 ヶ月または 24 ヶ月より古いレポートの自動削除（「無期限保持」も可）。監査成功後に実行。該当するデータベース行とアップロード済み Markdown を削除。
- **レポート管理** — 一覧と詳細（Markdown）表示、1 ページ 20 件のページネーション。単一・一括削除は独自の確認 UI、成功表示は再読み込みで貼りつかないトランジェント通知。
- **管理メニュー** — トップレベル **Maintenance Audit** と **設定・実行** / **レポート**。画面は `wp-admin/admin.php?page=…` で読み込み。

スケジュール、ドメイン制限、Markdown / メール出力、スナップショット、WP-CLI 連携の全体像はデザインの一部です。機能一覧の全体は `readme-ja.txt` / `readme.txt` を参照してください。

## 開発

WordPress / 実行環境の目安: **PHP 7.4+**。

Composer の開発ツール（PHPCS / PHPUnit 用の依存関係）: CI およびローカルで `composer install` には **PHP 8.0+**。プラグイン本体は PHP 7.4 で動く構文に収めているため、サイトは将来まで PHP 7.4 のままにできます。

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

## ライセンス

GPLv2 以降。詳細は [LICENSE](LICENSE)。
