=== WP Maintenance Audit Reporter ===
Contributors: lunaluna_dev
Tags: maintenance, report, security, backup, audit
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scheduled maintenance reports for WordPress (core, themes, plugins, checksums, deltas, optional email, persisted reports, WP-CLI). Japanese documentation: readme-ja.txt (bundled with the plugin).

== Description ==

**v0.9.0** hardens security and improves reliability: nonce verification order fixed in admin handlers; path traversal prevention in file storage; timezone input whitelisted against `timezone_identifiers_list()`; SSL probe uses two-pass approach (verified first, unverified fallback for expired certs); data collector errors isolated per-collector. 28 new unit tests added. See Changelog for full details. **v0.8.0** adds **Multisite network rollup**: network-activate the plugin, then enable rollup under **Network Admin → Maintenance Audit** to audit all target sites via `switch_to_blog`, merge reports, and dispatch one mail pair from the main site. **v0.7.0** adds **「スナップショットを保存する（差分比較用）」** on **設定・実行** for **今すぐ実行** (manual): when checked, manual runs persist snapshot rows for longitudinal diffs; when unchecked, the report still compares the live scan to the latest saved snapshot without overwriting `wpmar_snapshots`. Optional **テストメール上書き先** on **今すぐ実行** sends **up to two extra mails** (duplicate **client** + duplicate **admin**) when filled — normal `client_to` / `admin_to` deliveries are unchanged; skips a duplicate when the QA address already appears in the corresponding list. **WP-Cron** and **WP-CLI** always persist snapshots.

* **Mail (client)** — HTML body when Parsedown is present (`composer install`); plain-text alternative for legacy clients. Filter `wpmar_client_mail_html_enabled` to force plaintext only.
* **PDF (client-facing, optional)** — Persists `uploads/wpmar/pdf/*.pdf` on audit runs when enabled; built from stored **client-facing** Markdown (`body_client_md`). Requires `composer install` for mPDF/Parsedown at runtime.
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
3. From the plugin directory, run **`composer install --no-dev`** before distribution so `vendor/` includes **Parsedown/mPDF** (required for PDF export and client HTML mail). Developers may use full `composer install` for PHPCS/PHPUnit (see README.md).

== Frequently Asked Questions ==

= Is this production-ready? =

Not yet. Treat as development until a stable release is tagged.

= Where did the Settings submenu go? =

From v0.2 onward the UI lives under a dedicated **Maintenance Audit** top-level admin menu (submenus **設定・実行** and **レポート**). URLs use `wp-admin/admin.php?page=…` instead of `options-general.php?page=…`.

== Changelog ==

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
