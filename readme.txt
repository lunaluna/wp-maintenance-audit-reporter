=== WP Maintenance Audit Reporter ===
Contributors: lunaluna_dev
Tags: maintenance, report, security, backup, audit
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0-RC12
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scheduled maintenance reports for WordPress (core, themes, plugins, checksums, deltas, optional email, persisted reports, WP-CLI). Japanese documentation: readme-ja.txt (bundled with the plugin).

== Description ==

**v1.0.0-RC3** is the release candidate, promoted from the `0.x` development series after end-to-end testing of all major subsystems. **v0.11.0** added a **GitHub Releases update checker**: the plugin now hooks into WordPress's standard update pipeline so new releases published on GitHub appear as available updates in the dashboard and can be applied via the one-click updater — no WordPress.org listing required. The GitHub API response is cached for 6 hours (`wpmar_github_release_cache` transient). **v0.10.0** fixes administrator-facing report rendering (semver comparison against WordPress.org directory now uses `version_compare`; surfaces `データが正しく取得できませんでした。` when installed > directory; de-duplicates the "non-official plugin" message; deeper indent for checksum diff file lines; hides the unimplemented backup section) and ships a tag-driven release pipeline (`.github/workflows/release.yml`) that builds `wp-maintenance-audit-reporter.<version>.zip` with runtime-only vendors. CI workflow indentation also corrected (tabs → spaces). **v0.9.0** hardens security and improves reliability: nonce verification order fixed in admin handlers; path traversal prevention in file storage; timezone input whitelisted against `timezone_identifiers_list()`; SSL probe uses two-pass approach (verified first, unverified fallback for expired certs); data collector errors isolated per-collector. 28 new unit tests added. See Changelog for full details. **v0.8.0** adds **Multisite network rollup**: network-activate the plugin, then enable rollup under **Network Admin → Maintenance Audit** to audit all target sites via `switch_to_blog`, merge reports, and dispatch one mail pair from the main site. **v0.7.0** adds **「スナップショットを保存する（差分比較用）」** on **設定・実行** for **今すぐ実行** (manual): when checked, manual runs persist snapshot rows for longitudinal diffs; when unchecked, the report still compares the live scan to the latest saved snapshot without overwriting `wpmar_snapshots`. Optional **テストメール上書き先** on **今すぐ実行** sends **up to two extra mails** (duplicate **client** + duplicate **admin**) when filled — normal `client_to` / `admin_to` deliveries are unchanged; skips a duplicate when the QA address already appears in the corresponding list. **WP-Cron** and **WP-CLI** always persist snapshots.

* **Mail (client)** — HTML body when Parsedown is present (`composer install`); plain-text alternative for legacy clients. Filter `wpmar_client_mail_html_enabled` to force plaintext only.
* **PDF (client-facing, optional)** — Persists `uploads/wpmar/pdf/*.pdf` on audit runs when enabled; built from stored **client-facing** Markdown (`body_client_md`). Install the PDF library on-demand via the **"PDF ライブラリ（mPDF）"** panel in the settings page.
* **ZIP bulk download** — From the report list, export selected rows as a ZIP of **administrator-facing** `.md` files plus any saved **client-facing** `.pdf` peers.
* **CLI export** — `wp maintenance-audit export <id> --format=markdown|json|pdf`; `markdown` streams the **administrator-facing** body, `pdf` the **client-facing** artefact. Optional `--file=<path>` writes to disk instead of STDOUT (recommended for PDF when another plugin prints bootstrap notices).
* **Empty storage notice** — On **設定・実行** and **レポート**, an info notice when there are no report rows and no snapshot rows yet.
* **Manual snapshot save (v0.7)** — Checkbox **「スナップショットを保存する（差分比較用）」** gates DB updates for **今すぐ実行** only; changelog still compares latest stored snapshot to this run when unchecked. **WP-Cron** / **WP-CLI** always persist.
* **Test mailbox (v0.7)** — Optional **テストメール上書き先** on **今すぐ実行**: duplicate **client-facing** mail and duplicate **admin** mail to that address when it is not already in the respective configured list (does not replace configured recipients).

* **Scheduling** — Monthly WP-Cron anchor plus optional server cron via WP-CLI.
* **Inventory & deltas** — Core, themes, and plugins; change detection between snapshots.
* **Checksums** — Core and plugin verification against WordPress.org manifests; configurable exclude lists; locale fallback when the API returns no checksum map for the site language.
* **Domain gate** — Skip snapshot/report side effects when the host does not match the configured allowlist (e.g. staging).
* **Outputs** — Verbose **administrator-facing** Markdown (saved under uploads) and optional paired emails (**client-facing** HTML or text + **administrator-facing** plaintext).
* **Report storage** — Database table plus companion Markdown paths; **retention** (no auto-delete / 12 / 24 months) purges older rows and files after successful runs.
* **Admin UI** — Top-level **Maintenance Audit** menu (`admin.php` screens) with **設定・実行** (schedule, mail, exclusions, retention, runs) and **レポート** (list table, 20 items per page, detail view, Markdown **(administrator-facing)** / PDF **(client-facing)** download, ZIP bulk export, row + bulk delete without confirmation dialog; success notices use one-shot transients, not sticky query arguments).

Use WP-CLI for unattended runs and CI-style checks where available.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. If PDF output is needed, click **"PDF ライブラリをインストール"** in the **"PDF ライブラリ（mPDF）"** panel on the settings page. The plugin downloads and extracts `vendor-pdf.zip` (~94 MB) from GitHub Releases automatically.

== Git Management ==

If you manage this plugin in a project under Git version control, it is recommended to add the following two directories to your `.gitignore`, as they are generated on demand and should not be committed:

  wp-content/plugins/wp-maintenance-audit-reporter/fonts/
  wp-content/plugins/wp-maintenance-audit-reporter/vendor/

`fonts/` is the font cache written by mPDF during PDF generation. `vendor/` is the on-demand install target for the PDF library (mPDF).

== Frequently Asked Questions ==

= Is this production-ready? =

v1.0.0-RC12 is the release candidate. Treat as stable for testing; the final 1.0.0 tag will follow.

= Where did the Settings submenu go? =

From v0.2 onward the UI lives under a dedicated **Maintenance Audit** top-level admin menu (submenus **設定・実行** and **レポート**). URLs use `wp-admin/admin.php?page=…` instead of `options-general.php?page=…`.

== Changelog ==

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
