# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

_No pending notes._

## [1.0.0-RC4] - 2026-05-29

### Fixed

- **`vendor-pdf.zip` 404 on mPDF install** — The download URL was constructed with a `v` prefix (`v1.0.0-RC3`) but release tags are bare semver (`1.0.0-RC3`), causing a 404 when the admin clicked "PDF ライブラリをインストール". Removed the `v` prefix from the URL in `WPMAR_PDF_Installer::get_download_url()`.
- **`build-vendor-pdf-zip.sh` incomplete zip on macOS** — `mktemp -d` returns a symlinked path (`/var/folders/…`) on macOS; `zip` could not resolve files through it, producing a truncated archive. Added `realpath` to resolve the path before use.

## [1.0.0-RC3] - 2026-05-28

### Added

- **`WPMAR_PDF_Installer`** — Install the mPDF vendor bundle directly from the plugin settings page via a one-click button that downloads `vendor-pdf.zip` from GitHub Releases and extracts it into `vendor/` using `ZipArchive`.
- **`bin/build-vendor-pdf-zip.sh`** — Build script that installs production-only Composer deps in a temp directory and packages them as `vendor-pdf.zip` for upload to GitHub Releases.
- **Release pipeline update** — `release.yml` now excludes `vendor/` from the plugin zip and automatically builds and attaches `vendor-pdf.zip` as a separate release asset.

## [1.0.0-RC2] - 2026-05-26

### Fixed

- **`WPMAR_GitHub_Updater` fatal error on activation** — Class constants (`const`) in PHP must be compile-time expressions. `HOUR_IN_SECONDS` and `MINUTE_IN_SECONDS` are WordPress runtime constants defined via `define()` and cannot be used in `const` declarations; doing so triggers a fatal error when the file is parsed. Replaced with literal integer defaults (`DEFAULT_CACHE_TTL = 21600`, `DEFAULT_BACKOFF_TTL = 1800`).
- **`str_contains()` incompatibility** — `str_contains()` requires PHP 8.0+; the plugin declares PHP 7.4 as the minimum. Replaced with `false !== strpos()`.

### Changed

- **Filterable TTL values** — Cache and back-off durations are now returned by `get_cache_ttl()` / `get_backoff_ttl()` private static methods that pass through `apply_filters()`, allowing `functions.php` or a mu-plugin to override them at runtime:
  - `wpmar_github_updater_cache_ttl` (default: 21600 s / 6 h)
  - `wpmar_github_updater_backoff_ttl` (default: 1800 s / 30 min)

## [1.0.0-RC1] - 2026-05-26

### Changed

- **Release candidate** — Promoted from the `0.x` development series. No new features; marks the codebase as production-ready following successful end-to-end testing of all major subsystems: scheduled auditing, multisite network rollup, checksums, security ops, mail/PDF/CLI output, report storage, and the GitHub Releases update checker.

## [0.11.0] - 2026-05-26

### Added

- **GitHub Releases update checker** (`WPMAR_GitHub_Updater`) — The plugin now self-updates directly from GitHub Releases without requiring WordPress.org listing.
  - Hooks into `pre_set_site_transient_update_plugins`: fetches the latest release from the GitHub API and injects update metadata into the WordPress update transient when a newer version is available.
  - Hooks into `plugins_api`: supplies plugin details (version, release notes, links) to the "View version details" modal in the plugins list.
  - Hooks into `upgrader_process_complete`: clears the cached release data after this plugin is updated so the next check fetches fresh information.
  - GitHub API responses are cached for **6 hours** via a WordPress transient (`wpmar_github_release_cache`) to stay within the unauthenticated rate limit. Failed or rate-limited requests back off for **30 minutes**.
  - Prefers the explicitly uploaded release asset zip (produced by `release.yml`) over the GitHub auto-generated zipball, ensuring the zip's inner directory name matches the plugin directory and WordPress's upgrader unpacks cleanly.

## [0.10.2] - 2026-05-23

### Changed

- **Release trigger accepts bare semver tags** — `.github/workflows/release.yml` now matches both `v*` and bare numeric tags (`'v[0-9]*'` / `'[0-9]*'`). Convention in this project is **bare** tags (e.g. `0.10.2`) matching the WordPress.org Stable-tag style; the previous `'v*'`-only pattern silently dropped the `0.10.1` tag push without starting the workflow. The version-extraction step (`${TAG#v}`) already handles both forms.

## [0.10.1] - 2026-05-23

### Fixed

- **CI / phpcompat job failing** — `.github/workflows/ci.yml` PHPCS step failed on PHP 8.0 / 8.2 / 8.3 after the v0.10.0 tab→space fix made the workflow actually parse. Pre-existing WPCS violations in `tests/*` (PHPUnit-style doc blocks, camelCase methods) and in `class-wpmar-runner.php` (alignment, inline comment terminator) were the cause.
- **`includes/class-wpmar-runner.php`** — Re-aligned three `=` warnings (auto-fixed by `phpcbf`). Rewrote the backup-section toggle comment as plain description text so it no longer trips `Squiz.Commenting.InlineComment.InvalidEndChar`.

### Changed

- **`phpcs.xml.dist`** — Added `<exclude-pattern>tests/*</exclude-pattern>` so PHPUnit tests are not graded against WordPress Coding Standards (camelCase test methods and short doc blocks are PHPUnit conventions). Production sources under `includes/` continue to be enforced.

## [0.10.0] - 2026-05-23

### Fixed

- **Theme/plugin version comparison** — Switched from raw string inequality to `version_compare()` when comparing installed semver against the WordPress.org directory `version`. When the installed version is **newer** than the directory response (likely a stale or partial API payload), the report now prints `データが正しく取得できませんでした。` instead of mislabelling the row as "update available". Applies to both `render_operator_themes_section()` and `render_operator_plugins_section()` plus the `update_themes` / `update_plugins` transient pre-filters in `collect_pending_theme_update_lines()` / `collect_pending_plugin_update_lines()`.
- **Duplicate "non-official plugin" message** — `render_operator_plugins_section()` no longer emits both the checksum prose (`%s は非公式か、既に公開終了しているプラグインです。`) and the version-info fallback (`このプラグインは非公式か既に公開終了している可能性があります。`). Single unified line `%s は非公式か、既に公開終了している可能性があります。` is shown instead.
- **Checksum mismatch file indent** — Changed-file list lines under "の以下のファイルに変更が見つかりました:" now use 4 wide-space indent (`　　　　`) so they sit one level deeper than the surrounding plugin block.
- **GitHub Actions CI parsing** — `.github/workflows/ci.yml` was indented with tab characters which YAML 1.2 does not permit; the workflow loaded with "No jobs were run". Replaced all indentation with spaces and added `fail-fast: false` to the matrix.

### Changed

- **Backup section hidden** — `# 【バックアップ状況】` is no longer emitted in the administrator-facing mail body because backup status reporting is not yet implemented. `render_operator_backup_section()`, `render_backup_client_section()`, and `gather_backup_providers()` are retained for future re-activation; only the call site in `render_operator_markup()` is commented out.

### Added

- **`.github/workflows/release.yml`** — Tag-driven release pipeline. On `v*` tag push (or manual `workflow_dispatch`):
  - Resolves the tag and asserts it matches `wp-maintenance-audit-reporter.php`'s `Version:` header.
  - Runs `composer install --no-dev --prefer-dist --optimize-autoloader` so runtime libraries (mPDF / Parsedown) are bundled.
  - Builds `wp-maintenance-audit-reporter.<version>.zip`, excluding `.git`, `.github`, `tests/`, `phpunit.xml.dist`, `phpcs.xml.dist`, and similar dev-only paths.
  - Extracts the matching `## [version]` section from `CHANGELOG.md` as release notes (falls back to a generic note when absent).
  - Publishes a GitHub Release via `gh release create` with the zip attached.
- **`tests/DirectoryVersionStatusTest.php`** — 4 unit tests for the new `WPMAR_Runner::directory_version_status()` helper covering `update_available` / `current` / `data_error` / `unknown` branches.

## [0.9.0] - 2026-05-20

### Security

- **Nonce-before-capability order** — `check_admin_referer()` is now called before `current_user_can()` in both `WPMAR_Admin_Menu::handle_post()` and `WPMAR_Network_Admin_Menu::handle_post()`, preventing privilege-level inference on invalid nonce requests.
- **`is_email()` validation in notifier** — String branch of `$qa_override` in `WPMAR_Notifier_Mail` now validates the candidate address with `is_email()` before adding it, matching the array-branch behaviour.
- **Path traversal prevention** — `WPMAR_MD_Writer::absolute_path_from_upload_relative()` and `delete_if_upload_relative()` now reject any `$relative` value containing `..` before path construction (`wp_normalize_path` does not resolve dot-dot segments).

### Fixed

- **Timezone whitelist** — `WPMAR_Settings::merge_form_input()` validates submitted timezone strings against `timezone_identifiers_list()`; invalid or empty values fall back to `Asia/Tokyo`.
- **SSL certificate two-pass** — `WPMAR_Check_Security_Ops::check_ssl_certificate()` now attempts a verified TLS connection first (`verify_peer=true`); falls back to unverified only when the initial connection fails (e.g. expired cert). The result notes when verification was bypassed.
- **`readfile()` return check** — `WPMAR_Reports_Page::maybe_stream_report_download()` now checks the return value of `readfile()` and calls `wp_die()` on failure instead of silently exiting.
- **`$_GET` value strictness** — Network admin success notice now validates `$_GET['wpmar_network_msg'] === '1'` instead of just checking existence.

### Changed

- **`Throwable` in data collector** — `WPMAR_Data_Collector` wraps `call_user_func()` in `try/catch (Throwable $e)` (PHP 7.0+ broad catch) so a fatal-level error in a custom collector does not abort the entire audit run.
- **`WP_DEBUG_LOG` logging** — Cron error handlers in `WPMAR_Scheduler` now also log when `WP_DEBUG_LOG` is true, matching the standard WordPress pattern for log-only environments.
- **Activator host detection** — `WPMAR_Activator::ensure_site_defaults_and_schedule()` delegates to `WPMAR_Domain_Gate::current_host()` instead of duplicating inline `wp_parse_url( home_url() )` logic.
- **CI: `composer audit`** — `.github/workflows/ci.yml` runs `composer audit --no-dev` after install to flag known-vulnerable dependencies.

### Tests

- **`tests/SettingsTest.php`** — 18 unit tests for `clamp_int`, `parse_line_paths`, `parse_email_list`, and `merge_form_input` (timezone whitelist, retention whitelist, schedule clamping).
- **`tests/DomainGateTest.php`** — 10 unit tests for `WPMAR_Domain_Gate::is_allowed()` (host matching, case insensitivity, path prefix gating) and `merge_network_gate_settings()`.
- **`tests/wp-stubs.php`** — Added `wp_unslash`, `sanitize_email`, `is_email`, `sanitize_key`, `wp_parse_url`, `home_url` (configurable per-test via `$GLOBALS['_wpmar_test_home_url']`), and `untrailingslashit` stubs.

## [0.8.0] - 2026-05-20

### Added

- **Multisite network rollup** — Network-activate the plugin (`Network: true`). Enable **ネットワーク集約監査** under **Network Admin → Maintenance Audit** to audit all target blogs via `switch_to_blog`, merge per-site client/admin Markdown into **one report row on the main site**, and send **one mail pair**. Cron is scheduled on the main site only when rollup is enabled.
- **`WPMAR_Network_Settings`** — `wpmar_network_settings` sitemeta for schedule, mail, output, retention, site filters, and domain fallback/path prefix.
- **`WPMAR_Network_Runner`** — Orchestrates per-site `run_site_segment()` + merged delivery; `summary_json.network_rollup` with `per_blog` metadata.
- **Domain gate path prefix** — Optional `allowed_path_prefix` for subdirectory multisite (network settings + per-site merge fallback).
- **WP-CLI** — `wp maintenance-audit run --network` (requires network audit enabled).
- **Network admin UI** — Settings, dry run, manual rollup run, link to main-site reports.

### Changed

- Subsite **設定・実行** disables manual runs when network rollup is active (notice + link to network settings).

## [0.7.0] - 2026-05-19

### Added

- **Manual snapshot persist (diff baseline)** — **設定・実行** checkbox **「スナップショットを保存する（差分比較用）」** for **今すぐ実行**. When enabled, manual runs write canonical inventory to `wpmar_snapshots` (with per-dimension prune keeping two newest rows). When disabled, the report and `difference_summary` still compare **latest stored snapshot** vs **this run’s gather()**; only persistence is skipped. **WP-Cron** and **WP-CLI** invocations continue to always persist snapshots (`should_persist_snapshots`).
- **Test mailbox (client + admin copies)** — Optional **テストメール上書き先** on **今すぐ実行** sends duplicate **client** and **admin** mails when the address field is non-empty (skips each send if the address is already in that role’s list); **テストメール付き実行** admin button removed (`mail_qa_extra` in `WPMAR_Notifier_Mail::send_pair`).

## [0.6.0] - 2026-05-19

### Added

- **Client HTML email** — When **Parsedown** is available (`composer install` runtime `vendor/`), stakeholder mail is sent as `text/html` converted from the same **client-facing Markdown** as PDF exports; PHPMailer **plain-text alternative** (`AltBody`) keeps a readable fallback. Filter: `wpmar_client_mail_html_enabled`.
- **`WPMAR_PDF_Writer::markdown_to_html_fragment()`** — Markdown → HTML fragment for email (mPDF not required).

### Changed

- **Mail subjects** — Client/admin subjects follow the maintenance-scripts pattern (`[Site]様 …` / `[Site] …` with site-local `Y-m-d`).
- **Client Markdown body** — Removed the fixed “auto-generated summary…” line from the stakeholder copy.
- **Stale plugins block** — Client report adds a **【現在更新が滞っているプラグイン】** section using WordPress.org `last_updated` (180+ / 365+ days), aligned with `maintenance-scripts`.
- **Administrator mail body** — Replaces the raw JSON dump with a structured plaintext layout modeled on `/.maintenance/inc/mainte.sh` (`ADMIN_MAIL_BODY`): core, themes, plugins, server, backup, users, snapshot diff, security, optional DB size, execution time, runtime; `wpmar_report_sections` extras still appended.

## [0.5.0-dev] - 2026-05-14
- **Hooks**: `wpmar_report_sections` (Markdown extras for client/admin bodies), `wpmar_notification_channels` (callable channels after mail), `wpmar_backup_providers` (Markdown/callable summaries merged into audits).
- **Performance probes** (defaults OFF via settings): home URL timing, capped external HEAD checks seeded from homepage HTML, optional `information_schema` table-size snapshot (surfaced client summary + RAW JSON payload).
- **Dispatcher** `WPMAR_Notifier_Dispatcher` wiring post-report deliveries for extra channels while keeping core `wp_mail` pair intact.
- **Examples**: `examples/wpmar-v05-slack-webhook-sample.php`, `examples/wpmar-v05-generic-json-webhook-sample.php`, `examples/wpmar-v05-backup-provider-sample.php` (manual copy instructions in headers).
- **Tests**: PHPUnit coverage for Markdown extra helper stitch + defaults shape.

## [0.4.1-dev] - 2026-05-13

### Added

- CLI: `maintenance-audit export` に `--file=<path>`（markdown / json / pdf）。

### Changed

- レポート一覧の削除確認ダイアログを削除。
- **設定・実行** / **レポート** に、レポート行・スナップショット行がともに無いときの案内。
- ドキュメント（README / readme）と Stable tag を **0.4.1-dev** に整合。

## [0.4.0-dev] - 2026-05-14

### Added

- **PDF 出力** — 監査を実行したときにレポート本文から PDF を `uploads/wpmar/pdf/` へ保存（mPDF + Parsedown、Composer の実行時依存）。設定で ON/OFF。
- **ZIP 一括ダウンロード** — レポート一覧の一括操作で選択行の `.md` / `.pdf` を ZIP で取得。行アクションおよび詳細から Markdown / PDF を個別取得。

### Notes

- サーバーに ZipArchive 拡張が必要です。
- PDF 未生成時は本文からのオンデマンド生成を試みます（依存ライブラリが揃っている場合）。

## [0.3.0-dev] - 2026-05-14

### Added

- **Security & operations audit** (`WPMAR_Check_Security_Ops`): TLS certificate expiry (HTTPS sites), PHP branch EOL calendar, lightweight WP/PHP/MySQL recommendations, administrator session recency, `wp-config.php` permission posture, production `WP_DEBUG` / `SCRIPT_DEBUG` warnings.
- Settings: enable/disable SSL probe, administrator “stale login” threshold (days).
- Client and operator reports: `security` payload in dataset; `summary_json` includes `security_warning_count`, `security_summary`, `security_codes`; stakeholder email adds **運用・セキュリティ** section.
- Server intel: `SCRIPT_DEBUG` exposed alongside `WP_DEBUG` in gathered server array.

### Notes

- PHP EOL dates are maintained in `class-wpmar-check-security-ops.php`; refresh when PHP.net schedules change.

## [0.1.0-dev] - YYYY-MM-DD

- Scaffolding only.
