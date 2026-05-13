# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Plugin bootstrap, activator/deactivator, custom tables, full uninstall script.
- Repository tooling: PHPCS (WPCS), PHPUnit, GitHub Actions skeleton.

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
