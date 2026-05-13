=== WP Maintenance Audit Reporter ===
Contributors: lunaluna
Tags: maintenance, report, security, backup, audit
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.3.0-dev
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scheduled maintenance reports for WordPress (core, themes, plugins, checksums, deltas, optional email, persisted reports, WP-CLI). Japanese documentation: readme-ja.txt (bundled with the plugin).

== Description ==

Development build **v0.3.x** adds operational security signals (TLS, PHP EOL hints, stack recommendations, admin session recency, wp-config permissions, production debug flags) on top of v0.2 checksums and reporting.

* **Scheduling** — Monthly WP-Cron anchor plus optional server cron via WP-CLI.
* **Inventory & deltas** — Core, themes, and plugins; change detection between snapshots.
* **Checksums** — Core and plugin verification against WordPress.org manifests; configurable exclude lists; locale fallback when the API returns no checksum map for the site language.
* **Domain gate** — Skip snapshot/report side effects when the host does not match the configured allowlist (e.g. staging).
* **Outputs** — Verbose Markdown file (uploads) and optional paired HTML emails (client + operator).
* **Report storage** — Database table plus companion Markdown paths; **retention** (no auto-delete / 12 / 24 months) purges older rows and files after successful runs.
* **Admin UI** — Top-level **Maintenance Audit** menu (`admin.php` screens) with **設定・実行** (schedule, mail, exclusions, retention, runs) and **レポート** (list table, 20 items per page, detail view, row + bulk delete with a custom confirm dialog; success notices use one-shot transients, not sticky query arguments).

Use WP-CLI for unattended runs and CI-style checks where available.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Optional: from the plugin directory, run `composer install` if you need PHPCS/PHPUnit tooling (see README.md).

== Frequently Asked Questions ==

= Is this production-ready? =

Not yet. Treat as development until a stable release is tagged.

= Where did the Settings submenu go? =

From v0.2 onward the UI lives under a dedicated **Maintenance Audit** top-level admin menu (submenus **設定・実行** and **レポート**). URLs use `wp-admin/admin.php?page=…` instead of `options-general.php?page=…`.

== Changelog ==

= 0.3.0-dev =
* Security ops: TLS cert probe (optional), PHP EOL map, WP/PHP/MySQL hints, administrator last-activity (session tokens), wp-config permission check, production debug warnings.
* Settings: SSL check toggle, admin stale threshold.
* Reports: security data in dataset, client email section, `summary_json` security fields; server block includes SCRIPT_DEBUG.

= 0.2.0-dev =
* Checksums: core + plugin verification, admin exclusions, locale fallback for empty manifest maps.
* Settings: retention months (0 / 12 / 24), core/plugin checksum exclude fields.
* Runner: retention purge after persisting a report; Markdown/checksum context in client/admin bodies as implemented.
* Admin: top-level Maintenance Audit menu; report list table (pagination, delete + bulk delete, custom modal, transient flash notices); legacy `wpmar_msg` URL cleanup.
* Quality: PHPCS (WPCS) and PHPUnit scaffolding via Composer.

= 0.1.0-dev =
* Initial scaffolding: activation, tables, uninstall cleanup.
