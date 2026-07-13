# WP Maintenance Audit Reporter

WordPress plugin: scheduled maintenance audits for core, themes, and plugins — **v1.2.0**.

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
- **Diagnostics logging** — per-job step log for audit runs, a Reports-screen log viewer with download, and automatic recovery of jobs stuck by a killed process, so a stalled run can be traced to its last completed step
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

Installing (both download and manual upload) requires the `install_plugins` capability (super admins only on multisite; disabled when `DISALLOW_FILE_MODS` is set). The archive is validated in an isolated staging directory — absolute paths, `..`, symlinks, and any top-level entry other than `vendor/` or `fonts/` are rejected — before it is moved into place.

**Optional checksum pinning:** each release ships a `vendor-pdf.zip.sha256`. Set that value in the `WPMAR_PDF_VENDOR_ZIP_SHA256` constant (e.g. in `wp-config.php`) or return it from the `wpmar_pdf_vendor_zip_sha256` filter to require a SHA-256 match before extraction (no verification is performed when unset).

#### 検証ツール (QA tools)

- **テストメール上書き先** — a single extra address. When mail is enabled and this is filled, **今すぐ実行** additionally sends one client copy and one admin copy (up to 2 mails) to this address, skipping any type whose recipient list already contains it.

#### Snapshot & run buttons

- **スナップショットを保存する（差分比較用）** — only checked manual runs update the snapshot rows; unchecked manual runs produce the report only. Scheduled (WP-Cron) runs always persist snapshots. Deltas are always computed as "stored snapshot vs this run's collection".
- **変更を保存** — saves settings.
- **ドライラン** — collects data only and shows a summary; no snapshot, mail, or file output.
- **今すぐ実行** — enqueues the audit as a background job. A flash notice and the "レポート生成ジョブ" panel poll progress (queued → running → completed); while running, the panel also shows the current step (e.g. `gather:checksums:start`) and seconds since the last update. On completion it renders preview/download links; on failure it shows a "動作ログをダウンロード" (download log) link — see "診断ログ（動作ログ）" below.

### レポート (Reports) screen

Lists generated reports (20 per page) with a detail view that previews the administrator-facing Markdown. Downloads: Markdown (administrator-facing) and PDF or Markdown (client-facing). The bulk action **ZIP 一括ダウンロード** fetches multiple reports at once. Row delete and bulk delete run immediately — there is **no** confirmation dialog.

A **診断ログ (Diagnostics)** section below the report list shows recent audit jobs that have a log file (status, last completed step, updated time) with a tail preview and a download link — useful when a run stalls or fails and you need to see exactly where it stopped (see "診断ログ（動作ログ）" / Diagnostics below).

### 診断ログ (Diagnostics)

While an audit runs, one line is written per processing phase (step). Even if the process is killed abruptly (host OOM, execution timeout), everything up to the last completed step survives — so a stalled run can be traced to where it stopped.

#### Reading the log

Each line looks like this (a real example):

```text
[2026-07-09T00:51:19+00:00] [INFO] [job:cli-6a4ef05f3baf2] step: gather:checksums:start
```

Timestamp (UTC) · level (`INFO`/`ERROR`) · job id · what happened. A normal run walks through these steps in order:

`lock:acquired` → `gather:start` → `gather:core-updates` → `gather:inventory-done` → `gather:checksums:start` → `gather:checksums:done` → `gather:security-ops:start` → `gather:security-ops:done` → `gather:done` → `diff:done` → `persist-snapshots:done` → `render:done` → `md-write:done` → (if mail enabled) `mail:start` → `mail:done` → `report-insert:done` → `notify:done` → (if PDF enabled) `pdf:start` → `pdf:done` → `retention:done` → `reschedule:done` → `job ended`

**If a run stalls, the last line in the log is the last thing it completed.** For example, if the log ends at `gather:checksums:start`, the run stopped during checksum verification. A fatal error additionally logs an `[ERROR] FATAL: ...` line. Even if the process was killed hard enough (`SIGKILL`, OOM killer) that nothing could be written at all, a job with no update for ~25 minutes is automatically flipped to "failed" with "ハートビート途絶" (heartbeat lost) as the error.

#### Getting the log (e.g. for a support request)

- The **Reports screen**'s 診断ログ section lists recent jobs that have a log; "表示" (view) shows the tail (last ~200 lines) inline, "ダウンロード" (download) fetches the file.
- The running/failed job panel (Settings & Run screen) also shows a "動作ログをダウンロード" (download log) link on failure.
- Both are protected by the `manage_options` capability and a per-job nonce, so it's safe to hand the log file to a support request as-is — secrets such as mail passwords are never written to it.
- A synchronous WP-CLI run (`wp wpmar audit run --sync`) also produces a log (job id starting with `cli-`), but it won't appear in the admin job list — check `wp-content/uploads/wpmar/logs/` directly on the server.

Log files live under `wp-content/uploads/wpmar/logs/` (filenames carry an unguessable random token and the directory is `.htaccess`-protected against direct access). Only the 20 most recent runs are kept — older ones are pruned automatically after each run — and the whole directory is removed on uninstall.

### Network admin (multisite)

Network-activate the plugin, then configure rollup audits under **Network Admin → Maintenance Audit**. All target sites are visited and one client-facing plus one administrator-facing merged report is stored on the main site, with a single mail dispatch. Settings mirror the single-site screen, plus site filters (max sites, excluded blog IDs) and a run-scope selector (all target sites / main site only / a specific site).

### WP-CLI

The plugin registers two command namespaces:

- **`wp wpmar audit`** — the current entry point. Routes through the asynchronous job system; a synchronous fallback is available via `--sync`.
- **`wp maintenance-audit`** — the legacy namespace, which also carries the report-management subcommands (`run`, `test`, `reports`, `delete`, `export`).

Both `run` commands print the run result as pretty-printed JSON on success.

#### `wp wpmar audit run`

Executes an audit run.

```bash
wp wpmar audit run --sync [--dry-run] [--network] [--no-snapshot]
```

| Flag | Description |
|------|-------------|
| `--sync` | **Required.** Runs synchronously in the current process — the async queue is not yet wired, so omitting it errors out. Also serves as a CloudFront-bypassing fallback for production debugging / manual runs. |
| `--dry-run` | Harvest data only — no snapshot persistence, no mail. |
| `--network` | Multisite rollup audit. Requires network audit enabled under **Network Admin → Maintenance Audit**. |
| `--no-snapshot` | Generate the report without updating the snapshot baseline. |

`--same-setting` / `--id` are not available here — use the legacy `wp maintenance-audit run` for per-site network scoping.

#### `wp maintenance-audit` (legacy)

**`run`** — execute an audit synchronously.

```bash
wp maintenance-audit run [--dry] [--network] [--no-snapshot] [--same-setting] [--id=<blog_id>]
```

| Flag | Description |
|------|-------------|
| `--dry` | Harvest data only — no persistence, no mail. Note: this legacy command uses `--dry`, whereas `wp wpmar audit run` uses `--dry-run`. |
| `--network` | Multisite rollup audit. Requires network audit enabled. |
| `--no-snapshot` | Generate the report without updating the snapshot baseline. |
| `--same-setting` | Requires `--network`. Audit the main site only instead of all target sites — useful when every site shares identical plugins/themes/config. |
| `--id=<blog_id>` | Requires `--network`. Audit only the given blog ID. Takes precedence over `--same-setting`; errors if the blog ID does not exist. |

**`test`** — run collector instrumentation in dry mode (no DB writes besides the CLI probe transient). Takes no flags.

```bash
wp maintenance-audit test
```

**`reports`** — print recent persisted reports as a table.

```bash
wp maintenance-audit reports [--limit=<n>]
```

| Flag | Description |
|------|-------------|
| `--limit=<n>` | Number of rows to retrieve (default 20). |

**`delete`** — permanently delete a stored report.

```bash
wp maintenance-audit delete <id> [--yes]
```

| Argument / Flag | Description |
|-----------------|-------------|
| `<id>` | Numeric report identifier (required). |
| `--yes` | Skip the confirmation prompt. |

**`export`** — stream a report artefact to STDOUT for piping, or write it to a file.

```bash
wp maintenance-audit export <id> [--format=<markdown|json|pdf>] [--file=<path>]
```

| Argument / Flag | Description |
|-----------------|-------------|
| `<id>` | Report primary key (required). |
| `--format=<fmt>` | `markdown` (default; administrator-facing `body_md`), `json` (the full report row), or `pdf` (client-facing). `md` is accepted as an alias for `markdown`. |
| `--file=<path>` | Write to this path instead of STDOUT. Recommended for PDF when another plugin prints PHP notices during CLI bootstrap. The parent directory must exist and be writable. |

## Sites behind HTTP Basic authentication

When the whole site sits behind HTTP Basic authentication (e.g. `.htaccess` `AuthType Basic`), WordPress's loopback requests (WP-Cron / Action Scheduler) are rejected at the web-server layer, which constrains this plugin as follows.

### What does not work

- **Scheduled monthly report generation** — the WP-Cron loopback request is rejected with `401`, so the schedule never fires.

### What works

- **Manual report generation** — runs triggered from the admin screen work. Processing advances incrementally on each status poll, so **keep the admin page open** while the report is being generated; closing it pauses the run until the page is opened again.

The plugin detects blocked loopbacks automatically (the verdict is cached for 12 hours) and shows a warning on its admin screens with a re-check button.

### Recommended: server cron + WP-CLI

To generate reports on a schedule in a Basic-auth environment, run the WP-CLI command directly from the server's cron. This path uses no HTTP loopback at all, so Basic authentication does not affect it.

```bash
# Example: run at 03:00 on the 1st of every month
0 3 1 * * cd /path/to/wordpress && wp wpmar audit run --sync
```

On multisite, target each site with `--url`:

```bash
0 3 1 * * cd /path/to/wordpress && wp wpmar audit run --sync --url=https://example.com/site1/
```

## Changelog

Detailed per-version changes are recorded in [CHANGELOG.md](CHANGELOG.md).

- **v1.2.0** (2026-07-14) — Manual report generation now works on sites behind HTTP Basic authentication: blocked loopbacks are detected automatically (12h-cached, re-checkable) and pending jobs progress incrementally while the admin polling page stays open. Adds admin warnings with a re-check button, a schedule-settings note, and README guidance recommending server cron + `wp wpmar audit run --sync` for scheduled reports. Scheduled generation under Basic auth remains unsupported by design.
- **v1.1.1** (2026-07-09) — The report's user-information section is now a Markdown table instead of tab-separated text, so the client PDF renders it as a bordered table (applies to both the client and operator report bodies); also adds a diagnostics-log usage guide (reading step logs, retrieving them for support) to this README.
- **v1.1.0** (2026-07-09) — Added diagnostics logging for audit runs: an unbuffered per-job step log that survives a stalled/killed process, automatic recovery of jobs stuck by a killed process, and a Reports-screen viewer with a nonce-protected download link.
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
