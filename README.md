# WP Maintenance Audit Reporter

WordPress plugin: scheduled maintenance audits for core, themes, and plugins — **v0.4.1-dev** (active development).

See [readme.txt](readme.txt) for WordPress.org–style metadata and changelog. **日本語:** [README-ja.md](README-ja.md), [readme-ja.txt](readme-ja.txt).

## What v0.4 adds

- **PDF export** — On each full audit, optionally writes `uploads/wpmar/pdf/*.pdf` using **mPDF** + **Parsedown** (`composer install` in the plugin directory). Generation uses stored **client-facing** Markdown (`body_client_md`) for the PDF payload; the report detail screen still shows the operator Markdown body. Toggle under **設定・実行**.
- **ZIP bulk download** — On **レポート**, select rows and use bulk action **ZIP 一括ダウンロード** to fetch `.md` files and any stored `.pdf` peers. Row actions and the detail screen expose Markdown/PDF downloads.
- **CLI export** — `wp maintenance-audit export <id> --format=markdown|json|pdf` streams to STDOUT; pass `--file=<path>` to write the artefact to disk (recommended for PDF when another plugin emits bootstrap notices on CLI).
- **Admin UX** — Informational notice on **設定・実行** and **レポート** when both report rows and snapshot rows are empty. Row delete and bulk delete run immediately — there is **no** confirmation dialog.

## What v0.3 adds

- **Operational security block** — TLS certificate expiry (when the site uses HTTPS and the SSL check is enabled), static PHP EOL calendar (update dates in `includes/checks/class-wpmar-check-security-ops.php` when PHP.net changes), WordPress/PHP/MySQL “best effort” hints, administrator session recency against a configurable day threshold, `wp-config.php` permission scan for group/world-writable bits, and warnings when `WP_DEBUG` / `SCRIPT_DEBUG` are on in a `production` environment type.

## What v0.2 added (vs 0.1 scaffolding)

- **Checksums** — Core and plugin verification with WordPress.org APIs; exclude lists in settings; fallback when the site locale has no usable checksum manifest.
- **Retention** — Optional automatic removal of reports older than 12 or 24 months (or “keep forever”); runs after successful audits; removes DB rows and uploaded Markdown / PDF peers where applicable.
- **Reports admin** — List and detail views (Markdown), pagination (20 per page), Markdown/PDF downloads, ZIP bulk export, row and bulk delete (no confirmation prompt), and non-sticky success notices.
- **Admin menu** — Dedicated top-level **Maintenance Audit** entry with **設定・実行** and **レポート**; screens load via `wp-admin/admin.php?page=…`.

Scheduling, domain gate, Markdown/mail output, snapshots, and WP-CLI integration remain part of the overall design; see `readme.txt` for the full feature list.

## Development

WordPress/runtime target: **PHP 7.4+**.

Composer dev tooling and **runtime PDF libraries** (mPDF, Parsedown): **PHP 8.0+** on CI and local `composer install`. The plugin bootstrap avoids PHP-only syntax beyond 7.4 so sites may stay on PHP 7.4 until you raise the declared minimum later.

WordPress **6.0+**.

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

### Distribution ZIP (GitHub releases — planned)

When this repo is wired to GitHub, a sensible release pipeline is:

1. On tag push (or publishing a Release), run **`composer install --no-dev --optimize-autoloader`** so `vendor/` contains only runtime dependencies (including PDF libraries).
2. Build an archive that **excludes** developer-only paths — for example **`tests/`**, **`.github/`**, **`phpunit.xml.dist`**, **`phpcs.xml.dist`**, and similar — using a **`.distignore`** file (many WordPress plugin deploy workflows honor this convention) or an explicit file list in the workflow.
3. **Attach** the resulting ZIP to the GitHub Release (and/or publish to WordPress.org SVN when applicable).

Pull-request CI can keep using **`composer install`** (with dev dependencies) for PHPCS and PHPUnit; only the **release** job needs **`--no-dev`**.

## License

GPLv2 or later. See [LICENSE](LICENSE).
