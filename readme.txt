=== WP Maintenance Audit Reporter ===
Contributors: lunaluna
Tags: maintenance, report, security, backup, audit
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scheduled maintenance reports for WordPress (core, themes, plugins, deltas, optional email, WP-CLI).

== Description ==

This plugin is under active development (v0.1). It will provide:

* Monthly scheduling (WP-Cron plus optional server cron via WP-CLI)
* Core, theme, and plugin inventory and change detection
* Checksum verification (planned)
* Markdown report output and mail notifications
* Domain gate to avoid running on staging URLs

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress

== Frequently Asked Questions ==

= Is this production-ready? =

Not yet. Treat as development until a stable release is tagged.

== Changelog ==

= 0.1.0-dev =
* Initial scaffolding: activation, tables, uninstall cleanup.
