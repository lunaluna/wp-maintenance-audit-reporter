=== WP Maintenance Audit Reporter ===
Contributors: lunaluna_dev
Tags: maintenance, report, security, backup, audit
Requires at least: 6.0
Tested up to: 7.0.1
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scheduled maintenance reports for WordPress (core, themes, plugins, checksums, deltas, optional email, persisted reports, WP-CLI). Japanese documentation: readme-ja.txt (bundled with the plugin).

== Description ==

Generates monthly maintenance reports automatically and delivers them by mail, Markdown, and PDF.

* **Scheduled monthly audits** — run on a configurable day/time/timezone (asynchronous jobs via Action Scheduler, on top of WP-Cron).
* **Inventory and change history (deltas)** — snapshots core/theme/plugin state and reports additions, updates, and removals since the previous run.
* **Checksum verification** — core and plugin file-integrity checks against the WordPress.org API (exclude lists supported; falls back to the en_US manifest when the site locale has none).
* **Security ops** — TLS certificate expiry, PHP EOL, stale administrator sessions, `wp-config.php` permissions, `WP_DEBUG` warnings in production, and more.
* **Stale plugin detection** — flags plugins whose WordPress.org `last_updated` is 180+ / 365+ days old.
* **Two mail streams** — client-facing (HTML with plaintext alternative) and administrator-facing (structured plaintext).
* **Markdown / PDF output** — administrator-facing Markdown and client-facing PDF (mPDF + Noto Sans JP; library installed on demand from the settings page).
* **Reports admin** — list, detail preview, downloads, ZIP bulk export, retention-based cleanup.
* **Diagnostics logging** — per-job step log for audit runs, a Reports-screen log viewer with download, and automatic recovery of jobs stuck by a killed process, so a stalled run can be traced to its last completed step.
* **Multisite** — network rollup audits (visits all target sites, stores merged reports on the main site).
* **WP-CLI** — run audits and export reports from the command line.
* **GitHub Releases updater** — one-click dashboard updates without WordPress.org listing.

For detailed per-version changes, see CHANGELOG.md (bundled with the plugin) or the Changelog section below.

== Usage ==

Activating the plugin adds a top-level **Maintenance Audit** menu with two screens: **設定・実行** (Settings & Run) and **レポート** (Reports).

Minimal setup: on **設定・実行**, enable mail notification and enter recipients, click **変更を保存** (Save), run **ドライラン** (Dry run) to inspect the output, then **今すぐ実行** (Run now).

= 設定・実行 (Settings & Run) screen =

**ステータス (Status)** — read-only panel showing current state:

* **次回 WP-Cron** — next scheduled run.
* **直近の完了時刻 (UTC 保存)** — when the last audit completed.
* **WP-CLI** — whether CLI usage has been detected (version and last run); shows "未取得" until a CLI command is run once.

**スケジュール (Schedule):**

* **実行日 (1〜31)** — day of month for the run.
* **時刻 (時 / 分)** — hour and minute.
* **タイムゾーン** — e.g. `Asia/Tokyo`; any identifier PHP understands. Invalid values fall back to `Asia/Tokyo`.

**ドメインゲート (Domain gate):**

* **許可ホスト (Allowed host)** — matched against the host of the Site Address. The field shows the detected current host and match/mismatch feedback.
  * **Empty** — the gate passes in every environment (permissive).
  * **Match** — runs persist snapshots and send mail / write files normally.
  * **Mismatch** (e.g. staging) — runs still execute, but snapshot persistence, mail, and file output are suppressed. Enter the production host to keep cloned environments from mailing or persisting.

**セキュリティ診断（レポート） (Security checks):**

* **SSL 証明書の期限確認** — when enabled (recommended), makes a short TLS connection to check certificate expiry, only on https sites.
* **管理者「長期未ログイン」の日数** — administrators whose last session is older than this many days (30–730) are counted as stale in the report.

**オプション：データベースサイズチェック (Optional: DB size check):**

* **上位テーブルサイズを集計** — off by default; when checked, samples the largest tables via `information_schema` during the audit (may fail on some hosts).

**メール通知 (Mail notification):**

* **有効化** — enables report mail.
* **クライアント向け宛先（改行区切り）** — recipients of the client-facing HTML report, one address per line.
* **管理者向け宛先（改行区切り）** — recipients of the detailed plaintext report, one address per line.
* **送信元メールアドレス（オプション）** — falls back to the site admin email when empty.
* **送信元表示名（オプション）** — falls back to the site title when empty.

When mail is enabled but a recipient list is empty, the settings screen shows a warning notice.

**チェックサム除外リスト (Checksum exclude lists)** — excludes intentionally modified files from integrity checking. One entry per line; lines starting with `#` are comments.

* **コア除外パス** — paths relative to ABSPATH (e.g. `wp-config.php`).
* **プラグイン除外パス** — `slug:relative-path` entries (e.g. `akismet:readme.txt`).

Append `/` or `/*` to exclude a whole directory (e.g. `wp-admin/`, `akismet:views/`).

**保持期間 (Retention):**

* **レポート保管期間** — keep forever, or delete reports older than 12 / 24 months. Cleanup removes both DB rows and generated Markdown/PDF files, counted from the latest run.

**レポートをファイルとして自動保存 (Auto-save report files):**

* **Markdown を uploads に書き出して保存（管理者向け）** — writes the administrator-facing `.md` to `wp-content/uploads/wpmar/` on each run.
* **PDF を uploads に書き出して保存（クライアント向け）** — writes the client-facing PDF to `uploads/wpmar/pdf/`. A warning appears (and the setting has no effect) while the PDF library is not installed.

**PDF ライブラリ（mPDF） (PDF library)** — shows the mPDF installation status. When absent, a one-click button downloads `vendor-pdf.zip` from GitHub Releases and extracts it (no server-side `composer install` needed). If the automatic download fails, a manual ZIP-upload fallback appears.

Installing (both download and manual upload) requires the `install_plugins` capability (super admins only on multisite; disabled when `DISALLOW_FILE_MODS` is set). The archive is validated in an isolated staging directory — absolute paths, `..`, symlinks, and any top-level entry other than `vendor/` or `fonts/` are rejected — before it is moved into place.

Optional checksum pinning: each release ships a `vendor-pdf.zip.sha256`. Set that value in the `WPMAR_PDF_VENDOR_ZIP_SHA256` constant (e.g. in `wp-config.php`) or return it from the `wpmar_pdf_vendor_zip_sha256` filter to require a SHA-256 match before extraction (no verification is performed when unset).

**検証ツール (QA tools):**

* **テストメール上書き先** — a single extra address. When mail is enabled and this is filled, **今すぐ実行** additionally sends one client copy and one admin copy (up to 2 mails) to this address, skipping any type whose recipient list already contains it.

**Snapshot & run buttons:**

* **スナップショットを保存する（差分比較用）** — only checked manual runs update the snapshot rows; unchecked manual runs produce the report only. Scheduled (WP-Cron) runs always persist snapshots. Deltas are always computed as "stored snapshot vs this run's collection".
* **変更を保存** — saves settings.
* **ドライラン** — collects data only and shows a summary; no snapshot, mail, or file output.
* **今すぐ実行** — enqueues the audit as a background job. A flash notice and the "レポート生成ジョブ" panel poll progress (queued → running → completed), then render preview/download links.

= レポート (Reports) screen =

Lists generated reports (20 per page) with a detail view that previews the administrator-facing Markdown. Downloads: Markdown (administrator-facing) and PDF or Markdown (client-facing). The bulk action **ZIP 一括ダウンロード** fetches multiple reports at once. Row delete and bulk delete run immediately — there is **no** confirmation dialog.

A **診断ログ (Diagnostics)** section below the report list shows recent audit jobs that have a log file (status, last completed step, updated time), a tail preview, and a download link — useful when a run stalls or fails and you need to see exactly where it stopped.

= Network admin (multisite) =

Network-activate the plugin, then configure rollup audits under **Network Admin → Maintenance Audit**. All target sites are visited and one client-facing plus one administrator-facing merged report is stored on the main site, with a single mail dispatch. Settings mirror the single-site screen, plus site filters (max sites, excluded blog IDs) and a run-scope selector (all target sites / main site only / a specific site).

= WP-CLI =

The plugin registers two command namespaces: `wp wpmar audit` (the current entry point; routes through the async job system, with a synchronous fallback via `--sync`) and `wp maintenance-audit` (the legacy namespace, which also carries the report-management subcommands). Both `run` commands print the run result as pretty-printed JSON on success.

**`wp wpmar audit run`** — execute an audit run.

    wp wpmar audit run --sync [--dry-run] [--network] [--no-snapshot]

* `--sync` — Required. Runs synchronously in the current process (the async queue is not yet wired, so omitting it errors out). Also a CloudFront-bypassing fallback for production debugging / manual runs.
* `--dry-run` — Harvest data only; no snapshot persistence, no mail.
* `--network` — Multisite rollup audit (requires network audit enabled under Network Admin → Maintenance Audit).
* `--no-snapshot` — Generate the report without updating the snapshot baseline.

`--same-setting` / `--id` are not available here — use the legacy `wp maintenance-audit run` for per-site network scoping.

**`wp maintenance-audit run`** (legacy) — execute an audit synchronously.

    wp maintenance-audit run [--dry] [--network] [--no-snapshot] [--same-setting] [--id=<blog_id>]

* `--dry` — Harvest data only; no persistence, no mail. Note: this legacy command uses `--dry`, whereas `wp wpmar audit run` uses `--dry-run`.
* `--network` — Multisite rollup audit (requires network audit enabled).
* `--no-snapshot` — Generate the report without updating the snapshot baseline.
* `--same-setting` — Requires `--network`. Audit the main site only instead of all target sites.
* `--id=<blog_id>` — Requires `--network`. Audit only the given blog ID; takes precedence over `--same-setting`; errors if the blog ID does not exist.

**`wp maintenance-audit test`** — run collector instrumentation in dry mode (no DB writes besides the CLI probe transient). No flags.

    wp maintenance-audit test

**`wp maintenance-audit reports`** — print recent persisted reports as a table.

    wp maintenance-audit reports [--limit=<n>]

* `--limit=<n>` — Number of rows to retrieve (default 20).

**`wp maintenance-audit delete <id>`** — permanently delete a stored report.

    wp maintenance-audit delete <id> [--yes]

* `<id>` — Numeric report identifier (required).
* `--yes` — Skip the confirmation prompt.

**`wp maintenance-audit export <id>`** — stream a report artefact to STDOUT for piping, or write it to a file.

    wp maintenance-audit export <id> [--format=<markdown|json|pdf>] [--file=<path>]

* `<id>` — Report primary key (required).
* `--format=<fmt>` — `markdown` (default; administrator-facing `body_md`), `json` (the full report row), or `pdf` (client-facing). `md` is an alias for `markdown`.
* `--file=<path>` — Write to this path instead of STDOUT (recommended for PDF when another plugin prints PHP notices during CLI bootstrap). The parent directory must exist and be writable.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. If PDF output is needed, click **"PDF ライブラリをインストール"** in the **"PDF ライブラリ（mPDF）"** panel on the settings page. The plugin downloads and extracts `vendor-pdf.zip` (~94 MB) from GitHub Releases automatically. Installing requires the `install_plugins` capability.

== Frequently Asked Questions ==

= Is this production-ready? =

Yes. 1.0.0 is the first stable release, promoted from the 1.0.0-RC series after end-to-end testing of all major subsystems. Tested up to WordPress 7.0.1.

= Where did the Settings submenu go? =

From v0.2 onward the UI lives under a dedicated **Maintenance Audit** top-level admin menu (submenus **設定・実行** and **レポート**). URLs use `wp-admin/admin.php?page=…` instead of `options-general.php?page=…`.

== Development ==

WordPress/runtime target: **PHP 7.4+**. WordPress **6.0+**, tested up to **7.0.1**.

Composer dev tooling and runtime libraries (mPDF, Parsedown) for PDF and client HTML mail need **PHP 8.0+** on CI and local `composer install`. The plugin bootstrap avoids PHP syntax beyond 7.4 so sites may stay on PHP 7.4 until you raise the declared minimum later.

The `vendor/` directory is not committed (see `.gitignore`); third-party libraries are listed in `composer.json` and locked in `composer.lock`. After cloning, run `composer install` once:

    cd wp-content/plugins/wp-maintenance-audit-reporter
    composer install

Coding standards and tests:

    composer run phpcs
    composer run phpunit

**Distribution ZIP (GitHub Releases)** — implemented as `.github/workflows/release.yml`, triggered by pushing a `v*` or bare-semver tag (or manual `workflow_dispatch`):

1. The tag is asserted to match the `Version:` header of `wp-maintenance-audit-reporter.php` (mismatch fails the job).
2. `composer install --no-dev --optimize-autoloader` installs production dependencies.
3. The plugin tree is staged (excluding `.git`, `.github`, `tests/`, `vendor/`, `phpunit.xml.dist`, `phpcs.xml.dist`, and similar dev paths) and zipped as `wp-maintenance-audit-reporter.<version>.zip`.
4. A separate `vendor-pdf.zip` (plus its `vendor-pdf.zip.sha256`) is built from the installed `vendor/` directory and attached for on-demand installation via the admin UI.
5. Release notes are extracted from the matching `## [version]` section of `CHANGELOG.md`.
6. `gh release create` publishes the GitHub Release with the assets attached.

== Git Management ==

If you manage this plugin in a project under Git version control, it is recommended to add the following two directories to your `.gitignore`, as they are generated on demand and should not be committed:

  wp-content/plugins/wp-maintenance-audit-reporter/fonts/
  wp-content/plugins/wp-maintenance-audit-reporter/vendor/

`fonts/` holds the bundled PDF fonts (Noto Sans JP Regular/Bold, extracted from `vendor-pdf.zip`) plus the font-metric cache mPDF writes during generation. `vendor/` is the on-demand install target for the PDF library (mPDF).

== Changelog ==

= 1.1.1 =
* Changed: the report's user-information section (【ユーザー情報】) is now a Markdown table instead of tab-separated text, so the client PDF renders it as a bordered table. Applies to both the client and operator report bodies.
* See CHANGELOG.md for full details.

= 1.1.0 =
* Added diagnostics logging for audit runs: an unbuffered per-job step log (survives a stalled/killed process), automatic recovery of jobs stuck by a killed process, and a Reports-screen viewer with a nonce-protected download link.
* See CHANGELOG.md for full details.

= 1.0.0 =
* First stable release. Promoted from the 1.0.0-RC series with no functional changes to the audit/report feature set. Tested up to WordPress 7.0.1.
* Security: PDF library installer hardened against arbitrary code execution — the vendor bundle is now validated in an isolated staging directory (absolute paths, `..` traversal, symlinks, and unexpected top-level entries are rejected) and moved into place instead of being extracted directly into the plugin, and freshly-installed code is no longer executed in the upload request. The installer now requires the `install_plugins` capability, verifies the upload with `is_uploaded_file()` and a size cap, and supports optional SHA-256 pinning (`WPMAR_PDF_VENDOR_ZIP_SHA256` / `wpmar_pdf_vendor_zip_sha256`).
* Security (defense-in-depth): capability checks now run before nonce verification in the settings/bulk handlers; uploads-relative paths resolve symlinks and stay within the uploads root; the report-download GET no longer mutates the database.
* See CHANGELOG.md for full details.

= 1.0.0-RC14 =
* Changed: PDF embedded font — replaced BIZ UDGothic with Noto Sans JP (Regular + Bold). mPDF cannot embed CFF/OpenType outlines and Noto Sans JP ships only as a variable TTF (no distinct bold), so the release build instances the weight axis into static Regular (400)/Bold (700) TrueType fonts with fontTools (`bin/build-vendor-pdf-zip.sh`, `.github/workflows/release.yml`). Full glyph coverage is kept (mPDF subsets each PDF). `WPMAR_PDF_Writer` registers `notosansjp` (`NotoSansJP-Regular.ttf`/`NotoSansJP-Bold.ttf`) with a `sun-exta` fallback.
* Changed: PDF library settings panel detects a stale bundle (mPDF present but Noto fonts missing, via `WPMAR_PDF_Installer::fonts_present()`) and prompts a re-install that re-downloads the current `vendor-pdf.zip` — a plugin update alone does not refresh the fonts. `maybe_cleanup_legacy_fonts()` also removes the superseded `BIZUDGothic-Regular.ttf`/`BIZUDGothic-Bold.ttf`.

= 1.0.0-RC13 =
* Changed: Client-facing reports now show theme/plugin display names instead of slugs — the change-history section and the file-integrity (checksum) section in the client email and PDF render display names (e.g. `Snow Monkey`, `Advanced Query Loop`) instead of slugs. Snapshot data stays slug-keyed; conversion happens only at the output layer. Operator email and the Markdown export keep slugs. Falls back to the slug when a display name is unavailable (e.g. a removed plugin).

= 1.0.0-RC12 =
* Changed: Dry run is now asynchronous — "ドライラン" (single-site and network) is enqueued via Action Scheduler like "今すぐ実行" and returns immediately, addressing 504 timeouts when data collection itself (not PDF) is the slow phase. Falls back to the previous synchronous inline preview when Action Scheduler is unavailable.
* Changed: Mode-aware polling — the flash notice, panel heading, and completion text adapt to the job mode (full/dry). A dry run shows its `dry_brevity` summary on completion; a full run shows preview/download links.
* Changed: Leaner REST payload — dry-run job results return only the compact `dry_brevity` summary (the bulky `dry_preview` dataset is dropped).
* Changed: `vendor-pdf.zip` no longer bundles Action Scheduler (`bin/build-vendor-pdf-zip.sh` removes `vendor/woocommerce` before packaging); the on-demand PDF bundle ships only mPDF + Parsedown, and Action Scheduler ships solely under `lib/`.
* Fixed: "new version available" notice persisting after updating to the latest version — `check_for_update()` now clears any stale `response` entry when the installed version is current, and `after_update()` clears the `update_plugins` transient so the notice disappears immediately.

= 1.0.0-RC11 =
* Fixed: Dashboard one-click update failing with "パッケージをインストールできませんでした。" — `WPMAR_GitHub_Updater::extract_zip_url()` selected the first zip release asset, which could be the sibling `vendor-pdf.zip` (mPDF/fonts, not a valid plugin) instead of the plugin zip, because the GitHub API does not guarantee asset order (`vendor-pdf.zip` is returned first). WordPress then failed to install the wrong archive (manual upload of the plugin zip still worked). The asset is now matched by name — it must start with the plugin slug `wp-maintenance-audit-reporter` and end in `.zip` — so the correct plugin zip is always chosen regardless of order. The `zipball_url` fallback is unchanged.

= 1.0.0-RC10 =
* Added: Asynchronous audit jobs via Action Scheduler — "今すぐ実行" (single-site and network) enqueues a background job and returns immediately, eliminating CloudFront 504 timeouts on long audits. New `WPMAR_Job_Dispatcher`, a `wpmar_jobs` tracking table (`WPMAR_Jobs_Repository`), and Action Scheduler bundled in `lib/action-scheduler/`.
* Added: REST endpoint `GET /wpmar/v1/jobs/<id>` (manage_options) returning job status and, on completion, the report URL + nonce-signed download links.
* Added: Admin polling UI — a flash notice + "レポート生成ジョブ" panel poll for status (queued -> running -> completed) and show preview/download links; the job id is carried via redirect so the panel survives reloads.
* Added: WP-CLI `wp wpmar audit run --sync [--dry-run] [--network] [--no-snapshot]` — synchronous, CloudFront-bypassing fallback. Existing `wp maintenance-audit run` unchanged.
* Changed: Monthly WP-Cron audit and network "今すぐ実行" now route through the Action Scheduler job system (synchronous / legacy single-event fallback when the library is absent).
* Note: Action Scheduler's `actionscheduler_*` tables are left intact on uninstall (may be shared with other plugins).

= 1.0.0-RC9 =
* Added: Directory exclusions in checksum exclude lists — both core and plugin exclude lists now support directory prefixes. Append `/` or `/*` to exclude all files under a directory (e.g. `wp-admin/` or `wp-admin/*` for core; `akismet:some-dir/` for a plugin). Previously only exact file paths were matched. The settings panel description has been updated to document the new syntax.
* Fixed: Checksum settings label — "プラグイン除外" renamed to "プラグイン除外パス" for consistency with "コア除外パス".

= 1.0.0-RC8 =
* Added: WP-CLI `--same-setting` flag (network) — `wp maintenance-audit run --network --same-setting` audits the main site only. Useful when all network sites share identical plugins, themes, and configuration.
* Added: WP-CLI `--id=<blog_id>` flag (network) — `wp maintenance-audit run --network --id=2` audits a single specified blog ID only. Takes precedence over `--same-setting`; raises an error if the blog ID does not exist.
* Added: Network admin "実行範囲" run-scope selector — a radio-button group above the snapshot checkbox lets operators choose the audit scope for both "ドライラン" and "今すぐ実行": all target sites (default), main site only (--same-setting), or a specific site by blog ID (--id). Invalid or non-existent blog IDs show an error notice before execution.
* Fixed: `WPMAR_Network_Runner::resolve_blog_ids()` — nonexistent blog IDs now return an empty array instead of being passed to `switch_to_blog()`, preventing errors on stale WP-Cron payloads.

= 1.0.0-RC7 =
* Changed: Output file naming — Markdown and PDF artefacts now embed the site domain, audience label, and date in the filename. Administrator-facing Markdown: `wpmar-report-{domain}-admin-{Ymd}-{His}.md`; client-facing PDF: `wpmar-report-{domain}-client-{Ymd}-{id}.pdf`. Network rollup uses the same pattern with the `wpmar-network-report-` prefix. Previously all artefacts used `wpmar-report-{YmdHis}.md` / `wpmar-report-{id}.pdf` with no domain or audience distinction.
* Changed: PDF embedded font — replaced Noto Sans JP variable font (single file; no font-weight support in mPDF) with BIZ UDGothic Regular + Bold (two TTF files); mPDF now renders correct Regular/Bold weights in exported PDFs. Legacy `NotoSansJP.ttf` is automatically removed on the next plugin load.
* Fixed: PDF library (`vendor/`) preserved across plugin updates — `WPMAR_PDF_Installer` hooks `upgrader_source_selection` + `upgrader_process_complete` and detects this plugin by the incoming package's folder + main file, so it works for zip upload (install), dashboard "update now", and WP-CLI / auto-update alike. Any existing `vendor/` is moved to `wp-content/wpmar-vendor-backup/` before WordPress removes the plugin directory and restored after the new files are in place; hooks register in all contexts and an orphaned backup self-heals on the next load. Eliminates the need to re-install the PDF library after each plugin update.

= 1.0.0-RC6 =
* Added: Network settings UI — status panel now shows "直近の完了時刻" and "WP-CLI" items, matching the single-site page.
* Added: Network settings UI — timezone field now has description text; "許可ホスト" row shows detected host and match/mismatch feedback; "From" split into separate labelled "送信元メールアドレス" and "送信元表示名" rows; "出力・保持" split into three panels ("保持期間", "レポートをファイルとして自動保存", "PDF ライブラリ（mPDF）"); "検証ツール" and snapshot checkbox gained description text.
* Added: Background execution for network "今すぐ実行" — schedules an immediate WP-Cron event (`wpmar_run_network_audit_manual`) instead of running synchronously, preventing 504 gateway timeouts on large networks.
* Added: `DISABLE_WP_CRON` notice — when WP-Cron is disabled, a red error banner appears on both the network and single-site settings pages warning that scheduled and manual execution are non-functional; operators are directed to WP-CLI or an external cron.
* Added: WP-CLI `--no-snapshot` flag — `wp maintenance-audit run --no-snapshot` (and `--network --no-snapshot`) skips snapshot persistence, overriding the CLI trigger's default "always persist" behaviour.
* Removed: Network settings — "含めるサイト" checkboxes (archived/spam/deleted) removed from the "対象サイト" panel.
* Removed: Network settings — "許可パスプレフィックス" field and all related `WPMAR_Domain_Gate` / `WPMAR_Network_Settings` logic removed.
* Fixed: Network settings — `#wpmar-busy-overlay` element was missing, so the execution overlay never appeared; element added to match the single-site page.
* Fixed: `WPMAR_Network_Runner` — `add_site_transient()` does not exist in WordPress core; replaced with `get_site_transient()` check + `set_site_transient()`, resolving a PHP Fatal error on `wp maintenance-audit run --network`.
* Fixed: Network "今すぐ実行" with `DISABLE_WP_CRON` — no longer attempts synchronous execution (which risks 504); shows an error notice instead.

= 1.0.0-RC5 =
* Added: Mail send failure logging — `send_pair()` registers a scoped `wp_mail_failed` listener; when `WP_DEBUG_LOG` is enabled, transport failures are appended to `wp-content/debug.log` with the recipient address and error message.
* Added: Empty recipient warnings — when mail is enabled but `client_to` or `admin_to` resolves to no valid addresses, a warning is written to `wp-content/debug.log`.
* Added: Empty recipient admin notices — the settings page now shows a `warning` notice for each empty recipient list and an `error` notice when both are empty while mail sending is enabled.
* Added: Pre-flight check before PDF library download — validates write permissions and available disk space (≥150 MB); surfaces actionable error messages before the download starts.
* Added: Manual ZIP upload fallback — when the automatic GitHub download fails, a "手動インストール" panel appears; admins upload `vendor-pdf.zip` directly from the browser; ZIP magic bytes are validated server-side before extraction.
* Added: Markdown fallback note in the installer panel — informs admins that client-facing reports remain downloadable as Markdown when the PDF library cannot be installed.
* Added: `client_md` download type — `body_client_md` (client-facing Markdown) can now be downloaded as `wpmar-report-{id}-client.md` from the report detail screen, independent of the PDF library.
* Added: PDF availability awareness on report detail — "PDF をダウンロード（クライアント向け）" button replaced with "Markdown をダウンロード（クライアント向け）" when the PDF library is not installed.
* Added: `pdf_enabled` warning in settings — a warning note is shown next to the "PDF を uploads に書き出して保存" checkbox when the PDF library is not installed.
* Fixed: `.vscode/bin/phpcs` search order — Homebrew's phpcs 4.x is incompatible with WordPress Coding Standard (^3.x required); shim now searches Composer-installed phpcs before Homebrew.

= 1.0.0-RC4 =
* Fixed: `vendor-pdf.zip` 404 on mPDF install — download URL was constructed with a `v` prefix (`v1.0.0-RC3`) but release tags are bare semver (`1.0.0-RC3`). Removed the `v` prefix from `WPMAR_PDF_Installer::get_download_url()`.
* Fixed: `bin/build-vendor-pdf-zip.sh` produced a truncated zip on macOS — `mktemp -d` returns a symlinked path; added `realpath` to resolve it before calling `zip`.

= 1.0.0-RC3 =
* Added: `WPMAR_PDF_Installer` — install the mPDF vendor bundle on-demand from the plugin settings page. A button in the new "PDF ライブラリ（mPDF）" panel downloads `vendor-pdf.zip` from GitHub Releases and extracts it into the plugin's `vendor/` directory. Removes the previous requirement to run `composer install` on the server and resolves upload failures caused by the 30 MB `upload_max_filesize` / `post_max_size` limit.
* Added: `bin/build-vendor-pdf-zip.sh` — local build script that runs `composer install --no-dev` in a temp directory and packages `vendor/` as `vendor-pdf.zip` for upload to GitHub Releases.
* Changed: `.github/workflows/release.yml` — plugin zip now excludes `vendor/`; a separate `vendor-pdf.zip` asset is built and attached to the GitHub Release automatically.
* Changed: Installation no longer requires manual `composer install`. PDF library can be installed via the admin UI when needed.
* Fixed: `.vscode/bin/phpcs` shim — resolves `env: php: No such file or directory` in the VS Code extension host by locating the PHP binary from known paths and always invoking phpcs as `php phpcs_script` rather than relying on the `#!/usr/bin/env php` shebang.

= 1.0.0-RC2 =
* Fixed: `WPMAR_GitHub_Updater` — fatal error on plugin activation caused by PHP class `const` declarations using WordPress runtime constants (`HOUR_IN_SECONDS`, `MINUTE_IN_SECONDS`). Replaced with literal integer defaults.
* Fixed: `str_contains()` replaced with `false !== strpos()` for PHP 7.4 compatibility.
* Changed: cache and back-off TTL values are now filterable via `wpmar_github_updater_cache_ttl` and `wpmar_github_updater_backoff_ttl` filters.

= 1.0.0-RC1 =
* Release candidate. No new features; promoted from the `0.x` development series after end-to-end testing of all major subsystems (scheduled auditing, multisite network rollup, checksums, security ops, mail/PDF/CLI output, report storage, GitHub Releases update checker).

= 0.11.0 =
* Added: `WPMAR_GitHub_Updater` — GitHub Releases update checker. Hooks into `pre_set_site_transient_update_plugins` to inject update metadata when a newer release is available, `plugins_api` to populate the "View version details" modal, and `upgrader_process_complete` to clear the release cache after updating. API responses cached 6 h; rate-limit/error back-off 30 min. Prefers the uploaded release-asset zip over the auto-generated zipball for correct directory structure on unpack.

= 0.10.2 =
* Changed: release workflow trigger broadened to accept both `v*` and bare numeric tags (`'v[0-9]*'` / `'[0-9]*'`). The bare-semver convention (e.g. `0.10.2`) aligns with the WordPress.org Stable-tag style; the previous `'v*'`-only pattern silently dropped the `0.10.1` tag push without firing the release job.

= 0.10.1 =
* Fixed: CI / phpcompat (8.0 / 8.2 / 8.3) jobs were failing at the PHPCS step. After v0.10.0 corrected the workflow YAML, pre-existing WPCS violations under `tests/*` and minor alignment / inline-comment issues in `class-wpmar-runner.php` surfaced.
* Fixed: `includes/class-wpmar-runner.php` — three `=` alignment warnings auto-fixed; backup-section toggle comment rewritten so it satisfies `Squiz.Commenting.InlineComment.InvalidEndChar`.
* Changed: `phpcs.xml.dist` — `tests/*` excluded from WPCS scanning (PHPUnit tests follow PHPUnit conventions). Production sources under `includes/` continue to be enforced.

= 0.10.0 =
* Fixed: theme/plugin version comparison now uses `version_compare()`; when the installed version is newer than the WordPress.org directory response (likely stale API payload), prints `データが正しく取得できませんでした。` instead of mislabelling as "update available".
* Fixed: removed duplicate "non-official plugin" message in administrator mail (checksum prose + version-info fallback were both firing).
* Fixed: checksum-mismatch file list lines are now indented one level deeper (`　　　　`) under the plugin block.
* Fixed: `.github/workflows/ci.yml` indentation (tabs → spaces); GitHub Actions could not parse the workflow and reported "No jobs were run". `fail-fast: false` added to the matrix.
* Changed: `# 【バックアップ状況】` section is hidden from administrator mail (backup status reporting is not yet implemented). Collection / rendering code retained for future activation.
* Added: `.github/workflows/release.yml` — on `v*` tag push, verifies the tag matches the plugin header version, runs `composer install --no-dev`, builds `wp-maintenance-audit-reporter.<version>.zip` (excludes `.git` / `.github` / `tests` / `phpunit.xml.dist` / `phpcs.xml.dist`), extracts release notes from CHANGELOG, and publishes a GitHub Release with the zip attached.
* Tests: 4 new unit tests for `WPMAR_Runner::directory_version_status()`.

= 0.9.0 =
* Security: nonce check now runs before capability check in both admin settings handlers (CSRF fix).
* Security: `..` components in file paths are rejected before upload-relative path construction (path traversal fix).
* Security: `is_email()` validation added to string branch of QA mail override in notifier.
* Fixed: timezone input validated against `timezone_identifiers_list()`; invalid values fall back to `Asia/Tokyo`.
* Fixed: SSL probe uses verified connection first, falls back to unverified only when the initial attempt fails (e.g. expired cert); result notes when verification was bypassed.
* Fixed: `readfile()` return value checked; `wp_die()` on failure in PDF stream handler.
* Fixed: network admin success notice validates `$_GET` value equals `'1'` (not just existence).
* Changed: data collector wraps custom `call_user_func()` in `try/catch (Throwable)` to prevent one bad collector from aborting the run.
* Changed: cron error logging also triggers on `WP_DEBUG_LOG` (not only `WP_DEBUG`).
* Changed: activator delegates host detection to `WPMAR_Domain_Gate::current_host()`.
* CI: `composer audit --no-dev` step added to detect known-vulnerable dependencies.
* Tests: 28 new unit tests covering settings helpers, timezone whitelist, domain gate host/path matching, and network gate settings merge.

= 0.8.0 =
* Multisite network rollup: network-activate, enable rollup under Network Admin → Maintenance Audit; all target blogs audited via `switch_to_blog`; one merged report and one mail dispatch from the main site.
* Network settings (`wpmar_network_settings` sitemeta): schedule, mail, output, retention, site filters, domain fallback / path prefix.
* Domain gate: optional `allowed_path_prefix` for subdirectory multisite (network settings + per-site fallback).
* WP-CLI: `wp maintenance-audit run --network`.
* Network admin UI: settings, dry run, manual rollup, link to main-site reports.
* Site UI: disables manual runs when network rollup is active; notice with link to network settings.

= 0.7.0 =
* Settings: **「スナップショットを保存する（差分比較用）」** for manual **今すぐ実行** — toggles persisting `wpmar_snapshots`; diff still uses latest stored vs current scan when off. WP-Cron / WP-CLI unchanged (always persist).
* Settings: optional **テストメール上書き先** — on **今すぐ実行**, sends extra **client** and **admin** copies (two sends when both sides are needed) when the field is filled; duplicates skipped if the address already appears in `client_to` / `admin_to`. Removed separate **テストメール付き実行** button.

= 0.6.0 =
* Mail: client **HTML** email from client Markdown (Parsedown); plaintext `AltBody`; maintenance-scripts-style subjects; administrator **structured plaintext** body (replacing raw JSON).
* Report: **stale plugins** section from WordPress.org `last_updated` (180d/365d). Removed fixed “auto-generated summary…” line from client copy.

= 0.5.0-dev =
* Hooks: `wpmar_report_sections`, `wpmar_notification_channels`, `wpmar_backup_providers`.
* Optional DB table-size sampling (defaults off): top tables via `information_schema` when enabled in settings.
* `examples/` snippets for Slack, generic JSON webhook, and backup Markdown lines.

= 0.4.1-dev =
* CLI: `maintenance-audit export` accepts `--file=<path>` for markdown, json, and pdf (recommended for pdf when another plugin prints bootstrap notices before our command runs).

= 0.4.0-dev =
* PDF (client-facing): optional mPDF/Parsedown render to uploads/wpmar/pdf on audit runs; settings toggle.
* ZIP: bulk download selected reports (administrator-facing Markdown + client-facing PDF files).
* Admin/CLI: per-report Markdown **(administrator-facing)** and PDF **(client-facing)** download endpoints; CLI `export --format=pdf`.

= 0.3.0-dev =
* Security ops: TLS cert probe (optional), PHP EOL map, WP/PHP/MySQL hints, administrator last-activity (session tokens), wp-config permission check, production debug warnings.
* Settings: SSL check toggle, admin stale threshold.
* Reports: security data in dataset, client email section, `summary_json` security fields; server block includes SCRIPT_DEBUG.

= 0.2.0-dev =
* Checksums: core + plugin verification, admin exclusions, locale fallback for empty manifest maps.
* Settings: retention months (0 / 12 / 24), core/plugin checksum exclude fields.
* Runner: retention purge after persisting a report; administrator-facing / client-facing Markdown and checksum context in bodies as implemented.
* Admin: top-level Maintenance Audit menu; report list table (pagination, delete + bulk delete, transient flash notices); legacy `wpmar_msg` URL cleanup.
* Quality: PHPCS (WPCS) and PHPUnit scaffolding via Composer.

= 0.1.0-dev =
* Initial scaffolding: activation, tables, uninstall cleanup.

== License ==

GPLv2 or later. See LICENSE.
