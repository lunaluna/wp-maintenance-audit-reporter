# WP Maintenance Audit Reporter

WordPress plugin: scheduled maintenance audits for core, themes, and plugins — **v1.0.0-RC4**.

See [readme.txt](readme.txt) for WordPress.org–style metadata and changelog. **日本語:** [README-ja.md](README-ja.md), [readme-ja.txt](readme-ja.txt).

## What v1.0.0-RC4 fixes

- **`vendor-pdf.zip` 404 on mPDF install** — The download URL was constructed with a `v` prefix (`v1.0.0-RC3`) but release tags are bare semver (`1.0.0-RC3`), causing a 404 when the admin clicked "PDF ライブラリをインストール". Removed the `v` prefix from the URL in `WPMAR_PDF_Installer::get_download_url()`.
- **`build-vendor-pdf-zip.sh` incomplete zip on macOS** — `mktemp -d` returns a symlinked path (`/var/folders/…`) on macOS; `zip` could not resolve files through it, producing a truncated archive. Added `realpath` to resolve the path before use.

## What v1.0.0-RC3 adds (on-demand PDF library install)

- **`WPMAR_PDF_Installer`** — Install the mPDF vendor bundle directly from the plugin settings page. The new **"PDF ライブラリ（mPDF）"** panel shows installation status and offers a one-click install button that downloads `vendor-pdf.zip` from GitHub Releases and extracts it into the plugin's `vendor/` directory via `ZipArchive`. Eliminates the previous requirement to run `composer install` on the server and resolves 30 MB `upload_max_filesize` / `post_max_size` upload failures.
- **`bin/build-vendor-pdf-zip.sh`** — Build script that installs production-only Composer deps in a temp directory and packages them as `vendor-pdf.zip` for upload to GitHub Releases.
- **Release pipeline update** — `release.yml` now excludes `vendor/` from the plugin zip and automatically builds and attaches `vendor-pdf.zip` as a separate release asset.
- **phpcs shim fix** — `.vscode/bin/phpcs` now locates PHP from known paths (`/opt/homebrew/bin/php`, etc.) and always invokes phpcs via `php phpcs_script`, avoiding `env: php: No such file or directory` in the VS Code extension host.

## What v1.0.0-RC2 fixes

- **Fatal error on activation** — `WPMAR_GitHub_Updater` used WordPress runtime constants (`HOUR_IN_SECONDS`, `MINUTE_IN_SECONDS`) in PHP class `const` declarations. PHP evaluates class constants at compile time, before WordPress is loaded, causing a fatal error. Replaced with literal integer defaults (`DEFAULT_CACHE_TTL = 21600`, `DEFAULT_BACKOFF_TTL = 1800`).
- **PHP 7.4 incompatibility** — `str_contains()` is PHP 8.0+. Replaced with `false !== strpos()`.
- **Filterable TTL values** — Cache and back-off durations are now exposed via `apply_filters()` so they can be overridden at runtime without touching plugin source:
  - `wpmar_github_updater_cache_ttl` (default 21600 s / 6 h)
  - `wpmar_github_updater_backoff_ttl` (default 1800 s / 30 min)

## What v1.0.0-RC1 marks (Release candidate)

- **Release candidate** — Promoted from the `0.x` development series after end-to-end testing of all major subsystems. No new features; the full feature set from v0.11.0 is unchanged.

## What v0.11.0 adds (GitHub Releases update checker)

- **`WPMAR_GitHub_Updater`** — The plugin now self-updates directly from GitHub Releases. It hooks into WordPress's standard plugin update pipeline:
  - `pre_set_site_transient_update_plugins` — queries the GitHub API for the latest release and injects update metadata when a newer version is available, enabling the "update available" badge and one-click update in the plugins list.
  - `plugins_api` — supplies version details and release notes to the "View version details" modal.
  - `upgrader_process_complete` — clears the cached release data after this plugin is updated.
- **Transient cache** — GitHub API responses are cached for 6 hours (`wpmar_github_release_cache`). Rate-limited or failed requests back off for 30 minutes to avoid hammering the API.
- **Release asset preferred** — Uses the zip attached to the GitHub Release (built by `release.yml`) rather than the auto-generated zipball, so the inner directory name matches the plugin directory and WordPress's upgrader unpacks cleanly without requiring a rename step.

## What v0.10.2 adds (Release trigger)

- **Bare semver tags accepted** — `.github/workflows/release.yml` now triggers on both `v*` and bare numeric tags (`'v[0-9]*'` / `'[0-9]*'`). The project convention is bare semver (e.g. `0.10.2`), matching the WordPress.org Stable-tag style.

## What v0.10.1 adds (CI green)

- **CI / phpcompat unblocked** — After v0.10.0's tab→space YAML fix, GitHub Actions could parse the workflow and started failing at PHPCS on PHP 8.0 / 8.2 / 8.3. v0.10.1 fixes those: alignment / inline-comment violations in `includes/class-wpmar-runner.php` are corrected, and `phpcs.xml.dist` excludes `tests/*` since PHPUnit tests follow PHPUnit conventions rather than WPCS doc-block requirements.

## What v0.10 adds (Report fixes & release pipeline)

- **`version_compare()` semantics** — Theme/plugin "latest version" comparison no longer relies on raw string inequality. When the installed version is **newer** than the WordPress.org directory response (likely a stale or partial API payload), the administrator-facing report prints `データが正しく取得できませんでした。` instead of mislabelling the row as "update available".
- **De-duplicated "non-official plugin" message** — Administrator mail no longer emits both the checksum prose and the version-info fallback; a single `%s は非公式か、既に公開終了している可能性があります。` line is shown.
- **Checksum file list indent** — Changed-file lines under "の以下のファイルに変更が見つかりました:" are now indented one wide-space level deeper (`　　　　`).
- **Backup section hidden** — `# 【バックアップ状況】` is no longer emitted in the administrator mail body because backup status reporting is not yet implemented. Rendering and collection code is retained for future activation.
- **CI workflow parse fix** — `.github/workflows/ci.yml` indentation switched from tabs to spaces (YAML 1.2 disallows tabs); GitHub Actions previously failed with "No jobs were run". `fail-fast: false` added to the matrix.
- **Release pipeline** — New `.github/workflows/release.yml` triggered on `v*` tag push (or manual `workflow_dispatch`). The job verifies the tag matches the plugin header `Version:`, runs `composer install --no-dev --optimize-autoloader`, builds `wp-maintenance-audit-reporter.<version>.zip` (excluding `.git` / `.github` / `tests` / `phpunit.xml.dist` / `phpcs.xml.dist`), extracts the matching `## [version]` section from `CHANGELOG.md` as release notes, and publishes the GitHub Release with the zip attached.

### Release procedure

```bash
# 1. Bump version in wp-maintenance-audit-reporter.php, WPMAR_VERSION, composer.json, readme*.txt, README*.md
git commit -am "release: 1.0.0-RC4"
git push origin main

# 2. Tag and push (this triggers release.yml). Bare semver matches Stable-tag style:
git tag 1.0.0-RC4
git push origin 1.0.0-RC4
# (v-prefixed tags like v1.0.0-RC4 are also accepted.)
```

## What v0.9 adds (Security & reliability)

- **Nonce-before-capability** — `check_admin_referer()` runs before `current_user_can()` in both admin settings handlers.
- **Path traversal fix** — `WPMAR_MD_Writer` rejects relative paths containing `..` before constructing upload-relative file paths.
- **Timezone whitelist** — `WPMAR_Settings` validates submitted timezone strings against PHP's `timezone_identifiers_list()`; invalid or empty values fall back to `Asia/Tokyo`.
- **SSL two-pass** — `WPMAR_Check_Security_Ops` tries a verified TLS connection first; falls back to unverified only when the initial attempt fails (e.g. expired cert), and marks the result accordingly.
- **Isolated collector errors** — Data collector wraps `call_user_func()` in `try/catch (Throwable)` so a fatal error in one custom collector does not abort the run.
- **`is_email()` in notifier** — String QA-override branch now validates before adding the address.
- **CI audit** — `composer audit --no-dev` added to CI to flag known-vulnerable dependencies.
- **Unit tests** — 28 new tests: `SettingsTest` (settings helpers, timezone whitelist, retention, schedule clamping) and `DomainGateTest` (host/path matching, network fallback).

## What v0.8 adds (Multisite)

- **Network rollup** — Network-activate the plugin, then enable rollup under **Network Admin → Maintenance Audit**. All target sites are audited via `switch_to_blog`; **one client-facing** and **one administrator-facing** merged report is stored on the **main site** (`wpmar_reports`), with a single mail dispatch.
- **Site filters** — Exclude blog IDs, cap `max_sites`, optionally include archived/spam/deleted blogs.
- **Domain gate** — Per-site host check after blog switch; network **allowed host** fallback and optional **path prefix** for subdirectory installs.
- **CLI** — `wp maintenance-audit run --network`.

## What v0.7 adds

- **Manual snapshot persist** — On **設定・実行**, **「スナップショットを保存する（差分比較用）」** applies only to **今すぐ実行**. When checked, each manual run saves canonical inventory rows to `wpmar_snapshots` (and prunes older than two per dimension). When unchecked, the report and diff still use the **current** scan vs the **latest** saved snapshot, but snapshot rows are not updated. **WP-Cron** and **WP-CLI** runs always persist snapshots.
- **Test mailbox** — Optional **テストメール上書き先**: on **今すぐ実行**, when filled, sends an extra **client** copy and an extra **admin** copy to that address (each skipped if the address is already in the corresponding configured list); configured `client_to` / `admin_to` unchanged. No separate “test mail run” button.

## What v0.6 adds

- **Client HTML email** — Same **client-facing Markdown** as PDF/Parsedown; `Content-Type: text/html` when dependencies exist, with a **plaintext alternative** for MUAs that prefer it. Filter: `wpmar_client_mail_html_enabled`.
- **Mail subjects** — Aligned with internal maintenance-scripts conventions (site title + local date).
- **Stakeholder “stale plugin” section** — Flags plugins whose WordPress.org `last_updated` is 180+ / 365+ days old (mirrors shell report ordering).
- **Administrator email** — Structured plaintext (core / themes / plugins / server / backup / users / diff / security / optional DB size / runtime) instead of dumping RAW JSON.

## What v0.4 adds

- **PDF export (client-facing)** — On each audit run when enabled, writes `uploads/wpmar/pdf/*.pdf` using **mPDF** + **Parsedown**. Install the PDF library on-demand via the settings page (no `composer install` needed on the server). PDFs are rendered from stored **client-facing** Markdown (`body_client_md`). The report detail preview shows the **administrator-facing** Markdown body (`body_md`). Toggle under **設定・実行**.
- **ZIP bulk download** — On **レポート**, select rows and use bulk action **ZIP 一括ダウンロード** to fetch **administrator-facing** `.md` files and any saved **client-facing** `.pdf` peers. Row actions and the detail screen expose Markdown **(administrator-facing)** / PDF **(client-facing)** downloads.
- **CLI export** — `wp maintenance-audit export <id> --format=markdown|json|pdf` streams to STDOUT (`markdown` = **administrator-facing** body, `pdf` = **client-facing**); pass `--file=<path>` to write the artefact to disk (recommended for PDF when another plugin emits bootstrap notices on CLI).
- **Admin UX** — Informational notice on **設定・実行** and **レポート** when both report rows and snapshot rows are empty. Row delete and bulk delete run immediately — there is **no** confirmation dialog.

## What v0.3 adds

- **Operational security block** — TLS certificate expiry (when the site uses HTTPS and the SSL check is enabled), static PHP EOL calendar (update dates in `includes/checks/class-wpmar-check-security-ops.php` when PHP.net changes), WordPress/PHP/MySQL “best effort” hints, administrator session recency against a configurable day threshold, `wp-config.php` permission scan for group/world-writable bits, and warnings when `WP_DEBUG` / `SCRIPT_DEBUG` are on in a `production` environment type.

## What v0.2 added (vs 0.1 scaffolding)

- **Checksums** — Core and plugin verification with WordPress.org APIs; exclude lists in settings; fallback when the site locale has no usable checksum manifest.
- **Retention** — Optional automatic removal of reports older than 12 or 24 months (or “keep forever”); runs after successful audits; removes DB rows and uploaded **administrator-facing** Markdown / **client-facing** PDF peers where applicable.
- **Reports admin** — List and detail views (**administrator-facing** Markdown), pagination (20 per page), Markdown **(administrator-facing)** / PDF **(client-facing)** downloads, ZIP bulk export, row and bulk delete (no confirmation prompt), and non-sticky success notices.
- **Admin menu** — Dedicated top-level **Maintenance Audit** entry with **設定・実行** and **レポート**; screens load via `wp-admin/admin.php?page=…`.

Scheduling, domain gate, Markdown/mail output, snapshots, and WP-CLI integration remain part of the overall design; see `readme.txt` for the full feature list. **Markdown** artefacts and exports are **administrator-facing**; **PDF** is **client-facing** unless noted otherwise.

## Development

WordPress/runtime target: **PHP 7.4+**.

Composer dev tooling and **runtime libraries** (mPDF, Parsedown) for PDF and **client HTML mail**: **PHP 8.0+** on CI and local `composer install`. The plugin bootstrap avoids PHP-only syntax beyond 7.4 so sites may stay on PHP 7.4 until you raise the declared minimum later.

WordPress **6.0+**. Tested up to **7.0**.

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

Implemented as **`.github/workflows/release.yml`** (v0.10.0). Trigger: push of a `v*` tag (or manual `workflow_dispatch`).

1. The tag is parsed and asserted to match the `Version:` header of `wp-maintenance-audit-reporter.php`. Mismatch fails the job.
2. **`composer install --no-dev --optimize-autoloader`** is run to install production dependencies.
3. The plugin tree is staged into `wp-maintenance-audit-reporter/`, **excluding** `.git`, `.github`, `tests/`, `vendor/`, `phpunit.xml.dist`, `phpcs.xml.dist`, `.phpunit.result.cache`, and similar dev paths, and zipped as `wp-maintenance-audit-reporter.<version>.zip`.
4. A separate **`vendor-pdf.zip`** is created from the installed `vendor/` directory and attached to the release as an additional asset for on-demand installation via the admin UI.
5. Release notes are extracted from the matching `## [version]` section of `CHANGELOG.md` (falls back to a generic note when absent).
6. `gh release create` publishes the GitHub Release with both zips attached.

Pull-request CI continues to use **`composer install`** (dev deps) for PHPCS / PHPUnit via **`.github/workflows/ci.yml`**.

## License

GPLv2 or later. See [LICENSE](LICENSE).
