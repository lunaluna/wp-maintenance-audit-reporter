# WP Maintenance Audit Reporter

WordPress plugin: scheduled maintenance audits for core, themes, and plugins — **v1.0.0**.

See [readme.txt](readme.txt) for WordPress.org–style metadata and changelog. **日本語:** [README-ja.md](README-ja.md), [readme-ja.txt](readme-ja.txt).

## Overview

Generates monthly maintenance reports automatically and delivers them by mail, Markdown, and PDF.

- **Scheduled monthly audits** — run on a configurable day/time/timezone (asynchronous jobs via Action Scheduler, on top of WP-Cron)
- **Inventory and change history (deltas)** — snapshots core/theme/plugin state and reports additions, updates, and removals since the previous run
- **Checksum verification** — core and plugin file-integrity checks against the WordPress.org API (exclude lists supported; falls back to the en_US manifest when the site locale has none)
- **Security ops** — TLS certificate expiry, PHP EOL, stale administrator sessions, `wp-config.php` permissions, `WP_DEBUG` warnings in production, and more
- **Stale plugin detection** — flags plugins whose WordPress.org `last_updated` is 180+ / 365+ days old
- **Two mail streams** — client-facing (HTML with plaintext alternative) and administrator-facing (structured plaintext)
- **Markdown / PDF output** — administrator-facing Markdown and client-facing PDF (mPDF + Noto Sans JP; library installed on demand from the settings page)
- **Reports admin** — list, detail preview, downloads, ZIP bulk export, retention-based cleanup
- **Multisite** — network rollup audits (visits all target sites, stores merged reports on the main site)
- **WP-CLI** — run audits and export reports from the command line
- **GitHub Releases updater** — one-click dashboard updates without WordPress.org listing

For detailed per-version changes, see [CHANGELOG.md](CHANGELOG.md).

## Usage

Activating the plugin adds a top-level **Maintenance Audit** menu with two screens: **設定・実行** (Settings & Run) and **レポート** (Reports).

Minimal setup: on **設定・実行**, enable mail notification and enter recipients → **変更を保存** (Save) → **ドライラン** (Dry run) to inspect the output → **今すぐ実行** (Run now).

### 設定・実行 (Settings & Run) screen

#### ステータス (Status)

Read-only panel showing current state.

- **次回 WP-Cron** — next scheduled run.
- **直近の完了時刻 (UTC 保存)** — when the last audit completed.
- **WP-CLI** — whether CLI usage has been detected (version and last run); shows "未取得" until a CLI command is run once.

#### スケジュール (Schedule)

- **実行日 (1〜31)** — day of month for the run.
- **時刻 (時 / 分)** — hour and minute.
- **タイムゾーン** — e.g. `Asia/Tokyo`; any identifier PHP understands. Invalid values fall back to `Asia/Tokyo`.

#### ドメインゲート (Domain gate)

- **許可ホスト (Allowed host)** — matched against the host of the Site Address. The field shows the detected current host and match/mismatch feedback.
  - **Empty** — the gate passes in every environment (permissive).
  - **Match** — runs persist snapshots and send mail / write files normally.
  - **Mismatch** (e.g. staging) — runs still execute, but snapshot persistence, mail, and file output are suppressed. Enter the production host to keep cloned environments from mailing or persisting.

#### セキュリティ診断（レポート） (Security checks)

- **SSL 証明書の期限確認** — when enabled (recommended), makes a short TLS connection to check certificate expiry, only on https sites.
- **管理者「長期未ログイン」の日数** — administrators whose last session is older than this many days (30–730) are counted as stale in the report.

#### オプション：データベースサイズチェック (Optional: DB size check)

- **上位テーブルサイズを集計** — off by default; when checked, samples the largest tables via `information_schema` during the audit (may fail on some hosts).

#### メール通知 (Mail notification)

- **有効化** — enables report mail.
- **クライアント向け宛先（改行区切り）** — recipients of the client-facing HTML report, one address per line.
- **管理者向け宛先（改行区切り）** — recipients of the detailed plaintext report, one address per line.
- **送信元メールアドレス（オプション）** — falls back to the site admin email when empty.
- **送信元表示名（オプション）** — falls back to the site title when empty.

When mail is enabled but a recipient list is empty, the settings screen shows a warning notice.

#### チェックサム除外リスト (Checksum exclude lists)

Excludes intentionally modified files from integrity checking. One entry per line; lines starting with `#` are comments.

- **コア除外パス** — paths relative to ABSPATH (e.g. `wp-config.php`).
- **プラグイン除外パス** — `slug:relative-path` entries (e.g. `akismet:readme.txt`).

Append `/` or `/*` to exclude a whole directory (e.g. `wp-admin/`, `akismet:views/`).

#### 保持期間 (Retention)

- **レポート保管期間** — keep forever, or delete reports older than 12 / 24 months. Cleanup removes both DB rows and generated Markdown/PDF files, counted from the latest run.

#### レポートをファイルとして自動保存 (Auto-save report files)

- **Markdown を uploads に書き出して保存（管理者向け）** — writes the administrator-facing `.md` to `wp-content/uploads/wpmar/` on each run.
- **PDF を uploads に書き出して保存（クライアント向け）** — writes the client-facing PDF to `uploads/wpmar/pdf/`. A warning appears (and the setting has no effect) while the PDF library is not installed.

#### PDF ライブラリ（mPDF） (PDF library)

Shows the mPDF installation status. When absent, a one-click button downloads `vendor-pdf.zip` from GitHub Releases and extracts it (no server-side `composer install` needed). If the automatic download fails, a manual ZIP-upload fallback appears.

#### 検証ツール (QA tools)

- **テストメール上書き先** — a single extra address. When mail is enabled and this is filled, **今すぐ実行** additionally sends one client copy and one admin copy (up to 2 mails) to this address, skipping any type whose recipient list already contains it.

#### Snapshot & run buttons

- **スナップショットを保存する（差分比較用）** — only checked manual runs update the snapshot rows; unchecked manual runs produce the report only. Scheduled (WP-Cron) runs always persist snapshots. Deltas are always computed as "stored snapshot vs this run's collection".
- **変更を保存** — saves settings.
- **ドライラン** — collects data only and shows a summary; no snapshot, mail, or file output.
- **今すぐ実行** — enqueues the audit as a background job. A flash notice and the "レポート生成ジョブ" panel poll progress (queued → running → completed), then render preview/download links.

### レポート (Reports) screen

Lists generated reports (20 per page) with a detail view that previews the administrator-facing Markdown. Downloads: Markdown (administrator-facing) and PDF or Markdown (client-facing). The bulk action **ZIP 一括ダウンロード** fetches multiple reports at once. Row delete and bulk delete run immediately — there is **no** confirmation dialog.

### Network admin (multisite)

Network-activate the plugin, then configure rollup audits under **Network Admin → Maintenance Audit**. All target sites are visited and one client-facing plus one administrator-facing merged report is stored on the main site, with a single mail dispatch. Settings mirror the single-site screen, plus site filters (max sites, excluded blog IDs) and a run-scope selector (all target sites / main site only / a specific site).

### WP-CLI

```bash
# Synchronous run (recommended; bypasses CloudFront-style timeouts)
wp wpmar audit run --sync [--dry-run] [--network] [--no-snapshot]

# Legacy command (direct run, not via the async job system)
wp maintenance-audit run [--network] [--same-setting] [--id=<blog_id>] [--no-snapshot]

# Export a report
wp maintenance-audit export <id> --format=markdown|json|pdf [--file=<path>]
```

## Changelog

Detailed per-version changes are recorded in [CHANGELOG.md](CHANGELOG.md).

- **v1.0.0** (2026-07-05) — First stable release. No functional changes since 1.0.0-RC14. Tested up to WordPress 7.0.1.

## Git Management

If you manage this plugin in a project under Git version control, it is recommended to add the following two directories to your `.gitignore`, as they are generated on demand and should not be committed:

```gitignore
wp-content/plugins/wp-maintenance-audit-reporter/fonts/
wp-content/plugins/wp-maintenance-audit-reporter/vendor/
```

`fonts/` holds the bundled PDF fonts (Noto Sans JP Regular/Bold, extracted from `vendor-pdf.zip`) together with the font-metric cache mPDF writes during generation. `vendor/` is the on-demand install target for the PDF library (mPDF).

## Development

WordPress/runtime target: **PHP 7.4+**.

Composer dev tooling and **runtime libraries** (mPDF, Parsedown) for PDF and **client HTML mail**: **PHP 8.0+** on CI and local `composer install`. The plugin bootstrap avoids PHP-only syntax beyond 7.4 so sites may stay on PHP 7.4 until you raise the declared minimum later.

WordPress **6.0+**. Tested up to **7.0.1**.

### Composer

The **`vendor/` directory is not committed** to this repository (see `.gitignore`). Third-party libraries are listed in `composer.json` and locked in `composer.lock`; anyone who clones must run `composer install` once.

```bash
cd wp-content/plugins/wp-maintenance-audit-reporter
composer install
```

**GitHub Actions** (`.github/workflows/ci.yml`) uses the same flow (`composer install` before PHPCS and PHPUnit).

### Coding standards

From the plugin directory:

```bash
composer run phpcs
```

### Tests

```bash
composer run phpunit
```

### Distribution ZIP (GitHub releases)

Implemented as **`.github/workflows/release.yml`**. Trigger: push of a `v*` tag (or manual `workflow_dispatch`).

1. The tag is parsed and asserted to match the `Version:` header of `wp-maintenance-audit-reporter.php`. Mismatch fails the job.
2. **`composer install --no-dev --optimize-autoloader`** is run to install production dependencies.
3. The plugin tree is staged into `wp-maintenance-audit-reporter/`, **excluding** `.git`, `.github`, `tests/`, `vendor/`, `phpunit.xml.dist`, `phpcs.xml.dist`, `.phpunit.result.cache`, and similar dev paths, and zipped as `wp-maintenance-audit-reporter.<version>.zip`.
4. A separate **`vendor-pdf.zip`** is created from the installed `vendor/` directory and attached to the release as an additional asset for on-demand installation via the admin UI.
5. Release notes are extracted from the matching `## [version]` section of `CHANGELOG.md` (falls back to a generic note when absent).
6. `gh release create` publishes the GitHub Release with both zips attached.

Pull-request CI continues to use **`composer install`** (dev deps) for PHPCS / PHPUnit via **`.github/workflows/ci.yml`**.

### Release procedure

```bash
# 1. Bump version in wp-maintenance-audit-reporter.php, WPMAR_VERSION, composer.json, readme*.txt, README*.md
git commit -am "release: 1.0.0"
git push origin main

# 2. Tag and push (this triggers release.yml). Bare semver matches Stable-tag style:
git tag 1.0.0
git push origin 1.0.0
# (v-prefixed tags like v1.0.0 are also accepted.)
```

## License

GPLv2 or later. See [LICENSE](LICENSE).
