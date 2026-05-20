# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

_No pending notes._

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
