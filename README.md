# WP Maintenance Audit Reporter

WordPress plugin: scheduled maintenance audits for core, themes, and plugins — **v1.5.6**.

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

Entries containing `*` (any string, crosses `/`) or `?` (any single character) are matched as glob patterns via `fnmatch()` (e.g. `wordfence:*/.htaccess` excludes that filename at any nesting depth).

#### 保持期間 (Retention)

- **レポート保管期間** — keep forever, or delete reports older than 12 / 24 months. Cleanup removes both DB rows and generated Markdown/PDF files, counted from the latest run.

#### レポートをファイルとして自動保存 (Auto-save report files)

- **Markdown を書き出して保存（管理者向け）** — writes the administrator-facing `.md` to the protected private storage directory's `reports/` on each run (see "Storage directory (private storage)" below).
- **PDF を書き出して保存（クライアント向け）** — writes the client-facing PDF to the private storage directory's `pdf/`. A warning appears (and the setting has no effect) while the PDF library is not installed.

#### PDF ライブラリ（mPDF） (PDF library)

Shows the mPDF installation status. When absent, a one-click button downloads `vendor-pdf.zip` from GitHub Releases and extracts it (no server-side `composer install` needed). If the automatic download fails, a manual ZIP-upload fallback appears.

Installing (both download and manual upload) requires the `install_plugins` capability (super admins only on multisite; disabled when `DISALLOW_FILE_MODS` is set). The archive is validated in an isolated staging directory — absolute paths, `..`, symlinks, and any top-level entry other than `vendor/` or `fonts/` are rejected — before it is moved into place.

**Install location (since v1.5.5):** the library (mPDF + fonts, ~94 MB) installs to `wp-content/wpmar-pdf-lib/`, outside the plugin directory, so it survives a plugin update regardless of upgrade path — including an update applied while the plugin is deactivated, which previously wiped it. An existing in-plugin install from before v1.5.5 is migrated there automatically on first load. When `wp-content` isn't writable, both a fresh install and the automatic migration fall back to the plugin directory instead of failing. The location is filterable via `wpmar_pdf_lib_dir` (default `WP_CONTENT_DIR . '/wpmar-pdf-lib/'`); uninstalling the plugin deletes it too, unless the `wpmar_pdf_lib_delete_on_uninstall` filter returns `false`.

**Optional checksum pinning:** each release ships a `vendor-pdf.zip.sha256`. Set that value in the `WPMAR_PDF_VENDOR_ZIP_SHA256` constant (e.g. in `wp-config.php`) or return it from the `wpmar_pdf_vendor_zip_sha256` filter to require a SHA-256 match before extraction (no verification is performed when unset).

#### 検証ツール (QA tools)

- **テストメール上書き先** — a single extra address. When mail is enabled and this is filled, **今すぐ実行** additionally sends one client copy and one admin copy (up to 2 mails) to this address, skipping any type whose recipient list already contains it.

#### Snapshot & run buttons

- **スナップショットを保存する（差分比較用）** — only checked manual runs update the snapshot rows; unchecked manual runs produce the report only. Scheduled (WP-Cron) runs always persist snapshots. Deltas are always computed as "stored snapshot vs this run's collection".
- **変更を保存** — saves settings.
- **ドライラン** — collects data only and shows a summary; no snapshot, mail, or file output.
- **今すぐ実行** — enqueues the audit as a background job. A flash notice and the "レポート生成ジョブ" panel poll progress (queued → running → completed); while running, the panel also shows the current step (e.g. `gather:checksums:start`) and seconds since the last update. On completion it renders preview/download links; on failure it shows a "動作ログをダウンロード" (download log) link — see "診断ログ（動作ログ）" below.

### システム機能 (System Tools) screen

View/clear the wp.org cache, manually recover a stuck run lock, and read the run/segment history (duration, peak memory) — available per-site and network-wide.

#### Snapshot preview

The "スナップショットをプレビュー" (Preview snapshot) button under 実行履歴 (run history) expands the stored diff-baseline snapshots — the latest 2 generations for each of core/themes/plugins/users — as formatted Markdown.

- **This is not a copy of the audit report body.** What's stored is a slug → version map used purely for diffing (e.g. `akismet` → `5.7.2`); it carries no active/inactive flag, display name, or update-available status.
- Only the **latest 2 generations** are kept per dimension (core/themes/plugins/users); anything older is pruned automatically on the next audit run.
- **On multisite, the site-level screen is restricted to super admins.** A subsite administrator holding `manage_options` will not see this section at all: plugin/theme files are shared network-wide, so showing them here would leak information a subsite admin cannot normally access, and the users snapshot also carries plain-text email addresses.
- The network admin "システム機能" screen offers a site picker to view any one site's snapshots across the network (super admin only, `manage_network_options`).

#### Baseline freshness (v1.5.6+)

Above the preview toggle, a "基準の鮮度（最終保存日時）" (baseline freshness) list — always visible, no need to expand the preview — shows the newest `captured_at` per dimension, so you can tell at a glance whether the last scheduled run actually refreshed the diff baseline. A warning appears when the oldest dimension's baseline is more than 60 days old (an unmeasured provisional threshold — one monthly-cycle's grace). The same "いつと比べたか" (what it was compared against) and "更新したか" (whether it updated) information also appears per-run in the report body itself, under 前回スナップショットからの差分, and in `summary_json`.

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
- A synchronous WP-CLI run (`wp wpmar audit run`) also produces a log (job id starting with `cli-`), but it won't appear in the admin job list — check the private storage directory's `logs/` (below) directly on the server.

Log files live under the private storage directory's `logs/` (filenames carry an unguessable random token and the directory is `.htaccess`-protected against direct access). Only the 20 most recent runs are kept — older ones are pruned automatically after each run — and the whole directory is removed on uninstall.

### Storage directory (private storage)

Reports (Markdown), PDFs, and diagnostics logs are stored by default under `wp-content/wpmar-private/` (with `reports/`, `pdf/`, `logs/`, and `tmp/` subdirectories). `wp-content/uploads/` is routinely exposed unintentionally by backup publishing, CDNs, and sync tools, so as of v1.3.1 the default storage location has moved outside `uploads`.

**Defense in depth:**

1. Every filename carries an unguessable random token — the only defense that doesn't depend on the web server type.
2. Every directory gets an auto-generated `.htaccess` (`Require all denied` / `Deny from all`) and `index.php`.
3. By default this still sits under the document root (inside `wp-content`), but the constant below lets you move it outside entirely.
4. On multisite, a `site-{blog_id}/` split is always inserted under the base directory, since `wp-content` is shared network-wide (unlike `wp_upload_dir()`, which WordPress already splits per site).

**Changing the location (`WPMAR_PRIVATE_STORAGE_DIR`):** define this constant (e.g. in `wp-config.php`) to move storage outside the document root entirely:

```php
define( 'WPMAR_PRIVATE_STORAGE_DIR', dirname( ABSPATH ) . '/wpmar-private' );
```

The `wpmar_private_storage_dir` filter offers the same override programmatically.

**Non-Apache servers (nginx, etc.):** `.htaccess` only works under Apache. On nginx, add an explicit deny rule, e.g.:

```nginx
location ^~ /wp-content/wpmar-private/ {
    deny all;
    return 404;
}
```

Behind a CDN/reverse proxy (e.g. CloudFront), add the same deny rule at the CDN layer in addition to the origin.

**Write-fallback:** on hosts where `wp-content` isn't writable (some hardened setups), the plugin automatically falls back to the already-protected `wp-content/uploads/wpmar/` and shows an admin notice. Random-token protection stays in effect during the fallback. Setting `WPMAR_PRIVATE_STORAGE_DIR`, or fixing directory permissions, is recommended over relying on the fallback long-term.

**Backups:** exclude `wp-content/wpmar-private/` from backups, or encrypt it if included — it contains administrator email addresses and a vulnerability-relevant version inventory.

**Upgrading from v1.3.0 or earlier:** existing md/PDF/log files are migrated automatically to the new storage location after the plugin updates (files are renamed with a freshly generated random token). Migration proceeds gradually in the background, and the admin screens show progress ("レポートの保存先を移行中: X / Y 件" — "Migrating report storage: X / Y"). Old files are not deleted until migration completes. If the process is interrupted mid-migration, it resumes on the next request. To check or drive it manually, use WP-CLI:

```bash
# Preview only — reports counts and post-migration paths, changes nothing
wp wpmar storage migrate --dry-run

# Run it (default batch size: 20)
wp wpmar storage migrate

# Run across every site on a multisite network
wp wpmar storage migrate --network
```

**Downgrading:** before reinstalling the v1.3.0 zip, run `wp wpmar storage migrate --revert` first. This moves files back under `uploads/wpmar/` and updates the DB paths to the legacy format (filenames, random tokens included, are kept as-is, so they remain safe to serve even under v1.3.0). Downgrading without `--revert` first can leave files under `wp-content/wpmar-private/` outside the reach of both retention cleanup and the uninstaller.

### Network admin (multisite)

Network-activate the plugin, then configure rollup audits under **Network Admin → Maintenance Audit**. All target sites are visited and one client-facing plus one administrator-facing merged report is stored on the main site, with a single mail dispatch. Settings mirror the single-site screen, plus site filters (max sites, excluded blog IDs), a **"Report output scope"** selector (all sites / main site only / main site + selected child sites), and a dry-run-only "dry run target sites" selector.

Several similarly-named settings sit side by side here, so the distinction matters:

| Setting | What it narrows | Persisted? | Effect on the audit |
|---|---|---|---|
| Target sites (max sites, excluded blog IDs) | **The audit itself** | Yes | Excluded sites are never audited, so their snapshot baseline never updates |
| Report output scope (v1.5.4+) | **Which sites' data appears in the report** | Yes, applied to every run including scheduled ones | Every site is still audited and its snapshot still updates — only what's shown in the report changes |
| Dry run target sites | A one-shot pick for dry runs only | No | Has no effect on "Run now" (as of v1.5.4 — earlier versions applied it there too) |

**Behaviour change in v1.5.4:** "Run now" always audits every target site now. Previously this same selector could narrow "Run now" to the main site or a single site, but any site left out kept a stale snapshot baseline once the run finished — dry runs don't have this problem since they never persist snapshots. Use a dry run, or WP-CLI's `--same-setting` / `--id=<blog_id>`, for a scoped manual run.

### WP-CLI

The plugin registers a single `wp wpmar` namespace with three subcommand groups: `audit` (run/test), `report` (list/delete/export), and `storage` (migrate). All `run`-like commands print the run result as pretty-printed JSON on success.

#### `wp wpmar audit run`

Executes an audit run. Runs synchronously by default.

```bash
wp wpmar audit run [--dry-run] [--network] [--skip-snapshot] [--same-setting] [--id=<blog_id>] [--async] [--sync]
```

| Flag | Description |
|------|-------------|
| `--dry-run` | Harvest data only — no snapshot persistence, no mail. |
| `--network` | Multisite rollup audit. Requires network audit enabled under **Network Admin → Maintenance Audit**. |
| `--skip-snapshot` | Generate the report without updating the snapshot baseline. |
| `--same-setting` | Requires `--network`. Audit the main site only instead of all target sites — useful when every site shares identical plugins/themes/config. |
| `--id=<blog_id>` | Requires `--network`. Audit only the given blog ID. Takes precedence over `--same-setting`; errors if the blog ID does not exist. |
| `--async` | Enqueue the run on the Action Scheduler queue instead of running it in this process. Returns a job id immediately; the audit only progresses once the queue is worked (`wp action-scheduler run` or the next cron tick). Cannot be combined with `--sync`. |
| `--sync` | Kept for backward compatibility with earlier scripts — synchronous is already the default, so this is a no-op. |

**`test`** — run collector instrumentation in dry mode (no DB writes besides the CLI probe transient). Takes no flags.

```bash
wp wpmar audit test
```

#### `wp wpmar report`

**`list`** — print recent persisted reports as a table.

```bash
wp wpmar report list [--limit=<n>]
```

| Flag | Description |
|------|-------------|
| `--limit=<n>` | Number of rows to retrieve (default 20). |

**`delete <id>`** — permanently delete a stored report.

```bash
wp wpmar report delete <id> [--yes]
```

| Argument / Flag | Description |
|-----------------|-------------|
| `<id>` | Numeric report identifier (required). |
| `--yes` | Skip the confirmation prompt. |

**`export <id>`** — stream a report artefact to STDOUT for piping, or write it to a file.

```bash
wp wpmar report export <id> [--format=<markdown|json|pdf>] [--file=<path>]
```

| Argument / Flag | Description |
|-----------------|-------------|
| `<id>` | Report primary key (required). |
| `--format=<fmt>` | `markdown` (default; administrator-facing `body_md`), `json` (the full report row), or `pdf` (client-facing). `md` is accepted as an alias for `markdown`. |
| `--file=<path>` | Write to this path instead of STDOUT. Recommended for PDF when another plugin prints PHP notices during CLI bootstrap. The parent directory must exist and be writable. |

#### `wp wpmar storage migrate`

Migrates report/PDF/log storage to (or, with `--revert`, back from) the protected private-storage directory — see [Storage directory (private storage)](#storage-directory-private-storage) above.

```bash
wp wpmar storage migrate [--dry-run] [--network] [--batch=<size>] [--revert]
```

| Flag | Description |
|------|-------------|
| `--dry-run` | Report eligible-row counts and post-migration paths without moving any file or touching the database. |
| `--network` | Repeat on every site in the network (`switch_to_blog()` per site). Without this flag, only the current site is processed. |
| `--batch=<size>` | Rows processed per internal batch (default 20). Progress is saved after every batch, so an interrupted run resumes from where it left off. |
| `--revert` | Reverse direction: move files back to the legacy `uploads/wpmar/` layout and strip the `private:` prefix from stored paths (filenames, tokens included, are kept as-is). Intended for downgrading to a pre-1.3.1 release; not exposed in the admin UI since a downgrade is a deliberate operator action. |

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
0 3 1 * * cd /path/to/wordpress && wp wpmar audit run
```

On multisite, target each site with `--url`:

```bash
0 3 1 * * cd /path/to/wordpress && wp wpmar audit run --url=https://example.com/site1/
```

## Changelog

Detailed per-version changes are recorded in [CHANGELOG.md](CHANGELOG.md).

- **v1.5.5** (2026-09-03) — The PDF library (mPDF + fonts, ~94 MB) now installs to `wp-content/wpmar-pdf-lib/`, outside the plugin directory, instead of the plugin's own `vendor/`/`fonts/`; an existing in-plugin install is migrated there automatically on first load after updating. Fixes the library disappearing entirely when the plugin was updated while deactivated (the previous `vendor/`-backup safeguard only ran while the plugin was active). Filterable via `wpmar_pdf_lib_dir`; falls back to the plugin directory when `wp-content` isn't writable. Uninstalling deletes the external directory too, unless `wpmar_pdf_lib_delete_on_uninstall` returns `false`.
- **v1.5.4** (2026-09-03) — Network rollup reports can now narrow which sites' data appears in the merged report via a new "Report output scope" setting (all sites / main site only / main site + selected sites) on the network admin screen; every target site is still audited and its snapshot baseline still updates regardless of scope, so only what's shown in the report changes. "Run now" on the network admin screen now always audits every target site — the existing run-scope selector is dry-run-only, since narrowing "Run now" previously left skipped sites with a stale snapshot baseline; use a dry run or WP-CLI's `--same-setting`/`--id=<blog_id>` for a scoped manual run.
- **v1.5.3** (2026-09-02) — Adds a snapshot preview to both the site-level and network "システム機能" (System Tools) screens. A "スナップショットをプレビュー" button expands the latest 2 generations of core/themes/plugins/users as formatted Markdown. On multisite, the site-level section is restricted to super admins (plugin/theme snapshots are network-shared and the users snapshot carries plain-text emails); the network admin screen adds a site picker to view any one site's snapshots.
- **v1.5.2.1** (2026-09-02) — `wp wpmar audit run --network` now prompts for confirmation before a synchronous run (no `--async`, dry-run or not), since it loops every target site in a single PHP process and can exceed a host's execution/cron timeout on large networks. `--yes` bypasses it as usual; `--same-setting`/`--id=<blog_id>` runs are unaffected.
- **v1.5.2** (2026-09-02) — Core update copy now distinguishes "security patch applied, just an old major" from "known-vulnerable" using wp.org's stable-check API, instead of one generic "an update is available" message for both. Applies to the admin body, the client body, and PDF/mail. Adds a `wp_insecure` machine-readable summary code and a dedicated warning-count slot; a site with unpatched known vulnerabilities now increases `warning_count` by 2 instead of 1.
- **v1.5.1** (2026-08-21) — Consolidates the two WP-CLI namespaces into one: `wp wpmar audit` (run/test), `wp wpmar report` (list/delete/export), and `wp wpmar storage` (migrate); the legacy `wp maintenance-audit` namespace is removed. `wp wpmar audit run` is now synchronous by default (`--sync` becomes a no-op, `--async` opt-in enqueues via Action Scheduler) and gains `--same-setting`/`--id=<blog_id>`. Fixes `--no-snapshot` never having worked (renamed to `--skip-snapshot`) and a bug where `wp wpmar storage migrate --no-revert` was silently read as `revert => true`, risking a destructive rollback without `--dry-run`.
- **v1.5.0** (2026-08-20) — Migrates the self-update mechanism to the shared `l2d-wp-github-update-lib` library (vendored into `lib/l2d-updater/`), replacing the plugin-local implementation; behaviour is unchanged (same cache key and filter names). Adds a `wpmar_github_updater_enabled` kill switch and makes the update-details "Description" come from this plugin's own header. Releases now publish as a GitHub Release draft first, requiring `gh release edit <tag> --draft=false` to go live — closes a real risk of unattended auto-update rollout to sites running `forced-auto-update-controller` alongside this plugin.
- **v1.4.1** (2026-08-18) — GitHub-Releases update-mechanism bug-fix release, no new features. Removes the auto-generated-zipball fallback (`extract_zip_url()`) that could deactivate the plugin on install when a release had no matching asset; switches the update-availability comparison from the `WPMAR_VERSION` constant to the version WordPress actually reads from the plugin header, so drift between the two can no longer leave a stale or missing update notice; moves the release cache from a per-blog transient to a site-wide one, matching `Network: true`; reads "Requires PHP" / "Tested up to" from the plugin header instead of hardcoding them. Also unifies the distributed-zip exclusion list (`.distignore`, shared by `release.yml` and `bin/build-zip.sh`, replacing two lists that had already drifted) and extends the CI tag/version check from 1 location to 5.
- **v1.4.0** (2026-08-09) — Multisite memory-hardening release. A network rollup now runs each site as its own independent background job (plus one aggregate job) instead of looping every site in one process, so peak memory no longer grows with site count and one site's failure no longer takes the whole run down; transient job/segment failures get a capped automatic retry (`wpmar_job_max_attempts`, default one retry), and wp.org plugin/theme metadata is cached network-wide (`wpmar_wporg_cache_ttl`). Adds "システム機能" screens (site + network) for the wp.org cache, run-lock recovery, the diagnostics log, and a persistent run/segment history recording duration and peak memory. Also fixes uninstall leaving `wp-content/wpmar-private/` (reports, PDFs, logs) and every sub-site's tables/sitemeta behind, and a network run lock held until timeout after a fatal error.
- **v1.3.1** (2026-07-27) — Security release. Report/PDF/log storage moves to a protected `wp-content/wpmar-private/` directory by default (random-token filenames, auto `.htaccess`/`index.php`, `WPMAR_PRIVATE_STORAGE_DIR` override), fixing an unauthenticated disclosure issue; existing v1.3.0 files migrate automatically (`wp wpmar storage migrate`, with `--dry-run`/`--network`/`--revert`). Also unifies Parsedown safe mode across the PDF and HTML-email paths, enables `vendor-pdf.zip` checksum verification by default, and adds an `Update URI` header, `nosniff` on all downloads, capability-before-nonce ordering in the PDF installer, `SECURITY.md`, and CI hardening.
- **v1.3.0** (2026-07-14) — Checksum exclude lists (core and plugin) now support `fnmatch()`-style glob patterns (`*`, `?`) in addition to exact paths and directory prefixes, so a single entry like `wordfence:*/.htaccess` can exclude a repeating filename at any nesting depth.
- **v1.2.0** (2026-07-14) — Manual report generation now works on sites behind HTTP Basic authentication: blocked loopbacks are detected automatically (12h-cached, re-checkable) and pending jobs progress incrementally while the admin polling page stays open. Adds admin warnings with a re-check button, a schedule-settings note, and README guidance recommending server cron + `wp wpmar audit run --sync` for scheduled reports. Scheduled generation under Basic auth remains unsupported by design.
- **v1.1.1** (2026-07-09) — The report's user-information section is now a Markdown table instead of tab-separated text, so the client PDF renders it as a bordered table (applies to both the client and operator report bodies); also adds a diagnostics-log usage guide (reading step logs, retrieving them for support) to this README.
- **v1.1.0** (2026-07-09) — Added diagnostics logging for audit runs: an unbuffered per-job step log that survives a stalled/killed process, automatic recovery of jobs stuck by a killed process, and a Reports-screen viewer with a nonce-protected download link.
- **v1.0.0** (2026-07-05) — First stable release. No functional changes since 1.0.0-RC14. Tested up to WordPress 7.0.1.

## Git Management

If you manage this plugin in a project under Git version control, it is recommended to add the following three directories to your `.gitignore`, as they are generated on demand and should not be committed:

```gitignore
wp-content/wpmar-pdf-lib/
wp-content/plugins/wp-maintenance-audit-reporter/fonts/
wp-content/plugins/wp-maintenance-audit-reporter/vendor/
```

`wp-content/wpmar-pdf-lib/` is where the PDF library installs since v1.5.5 (see "PDF library" above); its `fonts/` holds the bundled PDF fonts (Noto Sans JP Regular/Bold, extracted from `vendor-pdf.zip`) together with the font-metric cache mPDF writes during generation, and its `vendor/` is the on-demand install target for the PDF library (mPDF). The two paths under the plugin directory are kept as a fallback: they're still used by a site mid-migration from before v1.5.5, or one where `wp-content` isn't writable and installation falls back into the plugin directory.

## Development

WordPress/runtime target: **PHP 7.4+**.

Composer dev tooling and **runtime libraries** (mPDF, Parsedown) for PDF and **client HTML mail**: **PHP 8.0+** on CI and local `composer install`. The plugin bootstrap avoids PHP-only syntax beyond 7.4 so sites may stay on PHP 7.4 until you raise the declared minimum later.

WordPress **6.0+**. Tested up to **7.1**.

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

1. The tag is parsed and asserted to match five version-bearing locations: the `Version:` header and `WPMAR_VERSION` constant in `wp-maintenance-audit-reporter.php`, `Stable tag:` in `readme.txt` and `readme-ja.txt`, and `"version"` in `composer.json`. Any mismatch fails the job.
2. **`composer install --no-dev --optimize-autoloader`** is run to install production dependencies.
3. **`bash bin/build-zip.sh`** stages the plugin tree into `wp-maintenance-audit-reporter/` and zips it as `wp-maintenance-audit-reporter.<version>.zip`. CI and local builds share this one script so they can't drift apart. Excluded paths (`tests/`, `bin/`, `vendor/`, `fonts/`, and similar dev/build paths) come from **`.distignore`**, the single source of truth for what does *not* ship — Action Scheduler is bundled into `lib/action-scheduler/` by a separate step in the same script, since `vendor/` itself is excluded.
4. A separate **`vendor-pdf.zip`** is created from the installed `vendor/` directory and attached to the release as an additional asset for on-demand installation via the admin UI.
5. Release notes are extracted from the matching `## [version]` section of `CHANGELOG.md` (falls back to a generic note when absent).
6. `gh release create` publishes the GitHub Release with both zips attached, as a **draft** — draft releases don't show up at `/releases/latest`, so no site picks them up via auto-update until a maintainer verifies the assets and publishes with `gh release edit <tag> --draft=false`.

Pull-request CI continues to use **`composer install`** (dev deps) for PHPCS / PHPUnit via **`.github/workflows/ci.yml`**.

To build the same zip locally (e.g. to inspect its contents before tagging), run `bash bin/build-zip.sh` from the plugin root; it writes `../wp-maintenance-audit-reporter.<version>.zip`.

### Release procedure

```bash
# 1. Bump version in wp-maintenance-audit-reporter.php, WPMAR_VERSION, composer.json, readme*.txt, README*.md
git commit -am "release: 1.0.0"
git push origin main

# 2. Tag and push (this triggers release.yml). Bare semver matches Stable-tag style:
git tag 1.0.0
git push origin 1.0.0
# (v-prefixed tags like v1.0.0 are also accepted.)

# 3. release.yml publishes a draft. Download and verify the assets, then publish it:
gh release download 1.0.0 -D /tmp/wpmar-release-check
gh release edit 1.0.0 --draft=false
```

## License

GPLv2 or later. See [LICENSE](LICENSE).
