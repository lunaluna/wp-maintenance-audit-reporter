# WP Maintenance Audit Reporter

WordPress plugin: scheduled maintenance audits for core, themes, and plugins — **v1.0.0-RC14**.

See [readme.txt](readme.txt) for WordPress.org–style metadata and changelog. **日本語:** [README-ja.md](README-ja.md), [readme-ja.txt](readme-ja.txt).

## Git Management

If you manage this plugin in a project under Git version control, it is recommended to add the following two directories to your `.gitignore`, as they are generated on demand and should not be committed:

```gitignore
wp-content/plugins/wp-maintenance-audit-reporter/fonts/
wp-content/plugins/wp-maintenance-audit-reporter/vendor/
```

`fonts/` holds the bundled PDF fonts (Noto Sans JP Regular/Bold, extracted from `vendor-pdf.zip`) together with the font-metric cache mPDF writes during generation. `vendor/` is the on-demand install target for the PDF library (mPDF).

## What v1.0.0-RC14 changes (PDF font switched from BIZ UDGothic to Noto Sans JP)

- **PDF embedded font — BIZ UDGothic → Noto Sans JP** — The bundled PDF font is now Noto Sans JP (Regular + Bold) instead of BIZ UDGothic. mPDF cannot embed CFF/OpenType (postscript) outlines and Google distributes Noto Sans JP only as a variable TTF (no distinct bold weight), so the release build (`bin/build-vendor-pdf-zip.sh` and `.github/workflows/release.yml`) instances the weight axis into static Regular (400) / Bold (700) TrueType fonts with fontTools. Full glyph coverage is kept — mPDF subsets each generated PDF, so arbitrary Japanese (site/plugin names) still renders without missing glyphs. `WPMAR_PDF_Writer` registers `notosansjp` (`NotoSansJP-Regular.ttf` / `NotoSansJP-Bold.ttf`) and falls back to `sun-exta` when the fonts are absent.
- **Re-install prompt for stale bundles** — Fonts ship inside the on-demand `vendor-pdf.zip`, which a plugin update does not re-download (the upgrade hooks preserve the existing `fonts/`). Installs still carrying the BIZ UDGothic bundle would otherwise fall back to `sun-exta`; the PDF library settings panel now detects the missing Noto fonts (`WPMAR_PDF_Installer::fonts_present()`) and shows a re-install prompt that fetches the current bundle. `maybe_cleanup_legacy_fonts()` also removes the superseded `BIZUDGothic-Regular.ttf` / `BIZUDGothic-Bold.ttf`.

## What v1.0.0-RC13 changes (client reports show theme/plugin display names instead of slugs)

- **Client-facing reports now show display names instead of slugs** — The change-history section and the file-integrity (checksum) section in the client email and PDF now render human-readable display names (e.g. `Snow Monkey`, `Advanced Query Loop`) instead of slugs (`snow-monkey`, `advanced-query-loop`). Snapshot data stays slug-keyed for compact diffing; the conversion happens only at the output layer, so operator-facing email and the Markdown export keep slugs unchanged. A new `WPMAR_Runner::build_display_name_maps()` helper derives slug→display-name maps from the live inventory (theme `name` / plugin `title`); `difference_summary()` emits two changelog bodies (slug for operators, display name for clients); and `render_checksum_client_section()` takes a slug→display-name map. When a display name is unavailable (e.g. a removed plugin no longer in the inventory) it falls back to the slug.

## What v1.0.0-RC12 changes (dry run is asynchronous too; mode-aware polling; vendor-pdf.zip excludes Action Scheduler)

- **Dry run is now asynchronous** — "ドライラン" on both the single-site and network screens is enqueued through Action Scheduler like "今すぐ実行" and returns immediately. This addresses the CloudFront 504 timeout in cases where the data-collection phase itself (not PDF rendering) is the slow part. When Action Scheduler is unavailable, the run falls back to the previous synchronous path with its inline preview.
- **Mode-aware job polling** — `WPMAR_Admin_Menu::render_job_flash()` / `render_job_status_panel()` take a `mode` argument (`full` | `dry`); the flash notice, panel heading, and completion text adapt accordingly (via a `data-wpmar-job-mode` attribute). On completion a dry-run job renders its compact `dry_brevity` summary instead of download links, while a full run shows the report/preview/download links as before.
- **Leaner REST payload for dry runs** — `WPMAR_Jobs_REST` returns only the compact `dry_brevity` summary for dry-run jobs and drops the bulky `dry_preview` dataset.
- **`vendor-pdf.zip` no longer bundles Action Scheduler** — `bin/build-vendor-pdf-zip.sh` removes `vendor/woocommerce` before packaging, so the on-demand PDF bundle ships only mPDF + Parsedown (+ deps). Action Scheduler ships solely in the plugin package under `lib/`, avoiding double-shipping.
- **Fixed: "new version available" notice persisting after updating** — `check_for_update()` now clears any stale `response` entry when the installed version is current, and `after_update()` clears the `update_plugins` transient, so the dashboard notice disappears immediately once you are on the latest version.

## What v1.0.0-RC11 fixes (dashboard one-click update selecting the wrong release asset)

- **Dashboard "update now" failing with "パッケージをインストールできませんでした。"** — The GitHub updater picked the first zip release asset, which could be the sibling `vendor-pdf.zip` (mPDF/fonts, not a valid plugin) rather than the plugin zip, since the GitHub API does not guarantee asset order. WordPress then failed to install it (manual upload of the plugin zip still worked). `WPMAR_GitHub_Updater::extract_zip_url()` now matches the asset by name — it must start with the plugin slug `wp-maintenance-audit-reporter` and end in `.zip` — so the correct plugin zip is always chosen regardless of asset order. The `zipball_url` fallback is unchanged.

## What v1.0.0-RC10 adds (asynchronous audit jobs; 504 fix for "今すぐ実行"; polling UI; CLI --sync)

- **Asynchronous audit jobs (Action Scheduler)** — "今すぐ実行" on both the single-site and network screens now enqueues a background job and returns in a few hundred ms, eliminating the CloudFront 504 gateway timeout that long synchronous audits triggered. Adds `WPMAR_Job_Dispatcher` (`enqueue_audit_job()` / `run_audit_job()`), a `{$wpdb->prefix}wpmar_jobs` tracking table (`WPMAR_Jobs_Repository`), and bundles Action Scheduler in `lib/action-scheduler/` (loaded before `plugins_loaded`, managed via Composer and copied into `lib/` at build).
- **Job-status REST endpoint** — `GET /wpmar/v1/jobs/<id>` (capability-gated) returns lifecycle state and, on completion, the report URL + nonce-signed Markdown/PDF download links.
- **Admin polling UI** — A top flash notice plus a "レポート生成ジョブ" panel poll the endpoint (~2.5 s): queued → running → completed, then preview/download links. The flash flips to "レポートが生成されました。" on completion (error notice on failure). The job id is carried via a PRG redirect so the panel survives reloads.
- **WP-CLI `wp wpmar audit run --sync`** — Synchronous, CloudFront-bypassing fallback (`--dry-run` / `--network` / `--no-snapshot`). Existing `wp maintenance-audit run` unchanged.
- **Monthly cron + network run unified on Action Scheduler** — `WPMAR_Scheduler::handle_event()` enqueues via Action Scheduler (synchronous fallback) and reschedules the monthly chain immediately; network "今すぐ実行" uses the same job system (legacy single-event retained as fallback).

## What v1.0.0-RC9 fixes (checksum directory exclusions; "プラグイン除外パス" label)

- **Directory exclusions in checksum exclude lists** — Both the core and plugin exclude lists now support directory prefixes. Append `/` or `/*` to exclude all files under a directory (e.g. `wp-admin/` or `wp-admin/*` for core; `akismet:some-dir/` for a plugin). Previously only exact file paths were matched. The internal `normalize_path_set` helper has been replaced by `build_exclude_set` + `is_excluded`. The settings page description has been updated to document the new syntax.
- **Checksum settings label fix** — "プラグイン除外" renamed to "プラグイン除外パス" for consistency with "コア除外パス".

## What v1.0.0-RC8 adds (network run-scope selector UI; CLI --same-setting and --id flags)

- **WP-CLI `--same-setting` flag (network)** — `wp maintenance-audit run --network --same-setting` audits the main site only instead of all target sites. Useful when all network sites share identical plugins, themes, and configuration.
- **WP-CLI `--id=<blog_id>` flag (network)** — `wp maintenance-audit run --network --id=2` audits a single specified blog ID only. Takes precedence over `--same-setting`; raises an error if the blog ID does not exist on the network.
- **Network admin — "実行範囲" run-scope selector** — A radio-button group above the snapshot checkbox exposes the two new CLI flags as a UI control. Applies to both "ドライラン" and "今すぐ実行": (1) すべての対象サイト（デフォルト）, (2) 親サイトのみ (`--same-setting` equivalent), (3) 特定のサイトのみ with a blog ID number input (`--id` equivalent). Invalid or non-existent blog IDs are caught before execution with an error notice.
- **Fixed: `resolve_blog_ids()` ghost-site guard** — `WPMAR_Network_Runner::resolve_blog_ids()` now returns an empty array when `target_blog_id` does not exist, preventing `switch_to_blog()` from being called on a nonexistent site via a stale WP-Cron payload.

## What v1.0.0-RC7 changes (output filename includes domain, audience, and date; PDF library preserved across updates; BIZ UDGothic font)

- **Output file naming — domain, audience, and date** — Markdown and PDF artefacts now embed the site domain, audience label, and date in the filename. Administrator-facing Markdown: `wpmar-report-{domain}-admin-{Ymd}-{His}.md`; client-facing PDF: `wpmar-report-{domain}-client-{Ymd}-{id}.pdf`. Network rollup follows the same pattern with the `wpmar-network-report-` prefix. Previously all artefacts used `wpmar-report-{YmdHis}.md` / `wpmar-report-{id}.pdf` with no domain or audience distinction.
- **`vendor/` preserved across plugin updates** — `WPMAR_PDF_Installer` hooks into `upgrader_source_selection` and `upgrader_process_complete`.
- **PDF font — Noto Sans JP → BIZ UDGothic** — The embedded PDF font has been replaced from the Noto Sans JP variable font (single file; no font-weight distinction) to BIZ UDGothic Regular + Bold (two separate TTF files). mPDF now renders Regular and Bold weights correctly in exported PDFs. The legacy `NotoSansJP.ttf` is removed automatically on the next plugin load. When an update is detected to be this plugin (matched by the incoming package's folder + main file, so it works for the manual ZIP-upload _install_ flow whose `hook_extra` omits the `plugin` key, the dashboard "update now" flow, and WP-CLI / auto-update), any existing `vendor/` is moved to `wp-content/wpmar-vendor-backup/` before WordPress removes the plugin directory, then restored once the new files are in place. The hooks register in every context (not just admin), and a self-heal step restores an orphaned backup on the next load if an update was interrupted mid-copy. This eliminates the need to re-install the PDF library after each plugin update.

## What v1.0.0-RC6 changes (network admin UI overhaul, 504 fix, CLI --no-snapshot)

- **Network settings UI parity** — The network admin page now matches the single-site page in completeness: status panel gains "直近の完了時刻" and "WP-CLI"; timezone field gains description text; "許可ホスト" row shows detected host with match/mismatch feedback; "From" split into labelled "送信元メールアドレス" and "送信元表示名" rows; "出力・保持" split into three panels ("保持期間", "レポートをファイルとして自動保存", "PDF ライブラリ（mPDF）"); "検証ツール" and snapshot checkbox gained description text.
- **Removed: 含めるサイト checkboxes** — The "アーカイブ済み", "スパム", "削除済み" filters are removed from the "対象サイト" panel. Use "除外する blog ID" to exclude specific sites.
- **Removed: 許可パスプレフィックス** — The path-prefix gate field and all related logic in `WPMAR_Domain_Gate` / `WPMAR_Network_Settings` are removed.
- **Background execution for network "今すぐ実行"** — Instead of running synchronously (causing 504 gateway timeouts on large networks), the button now schedules an immediate WP-Cron single event (`wpmar_run_network_audit_manual`) and calls `spawn_cron()`. A new constant `WPMAR_HOOK_NETWORK_MANUAL_RUN` and handler `WPMAR_Scheduler::handle_network_manual_event()` were added. When `DISABLE_WP_CRON` is true, an error notice is shown instead—no synchronous fallback.
- **`DISABLE_WP_CRON` notice** — A red `notice-error` banner appears at the top of both network and single-site settings pages when WP-Cron is disabled, warning that both scheduled and manual runs are non-functional and directing operators to `wp maintenance-audit run --network` or an external cron.
- **WP-CLI `--no-snapshot` flag** — `wp maintenance-audit run --no-snapshot` (also with `--network`) skips snapshot persistence for that run, overriding the CLI trigger's "always persist" default.
- **Fixed: busy overlay missing on network page** — `#wpmar-busy-overlay` was absent from the network settings HTML; the execution overlay now appears on dry run and full run.
- **Fixed: `add_site_transient()` fatal error** — `add_site_transient()` does not exist in WordPress core. Replaced with `get_site_transient()` + `set_site_transient()`, resolving a PHP Fatal error on `wp maintenance-audit run --network`.

## What v1.0.0-RC5 adds (PDF installer fallbacks & client Markdown export)

- **Mail send failure logging** — `send_pair()` registers a scoped `wp_mail_failed` listener. When `WP_DEBUG_LOG` is enabled, any transport failure is appended to `wp-content/debug.log` with the recipient address and PHPMailer error message, ending previously silent failures.
- **Empty recipient warnings** — If mail is enabled but `client_to` or `admin_to` contains no valid addresses after sanitisation, a warning is written to `wp-content/debug.log` to surface the misconfiguration.
- **Empty recipient admin notices** — The settings page now shows a `warning` notice for each empty recipient list and an `error` notice when both are empty while mail sending is enabled.
- **Pre-flight check** — Before starting the GitHub download, the installer validates write permissions and available disk space (≥150 MB). Permission failures show the exact path with a `chmod 755` hint; disk-full failures show the current free-space value.
- **Manual ZIP upload fallback** — When the automatic download fails (firewall, network restriction), a "手動インストール" panel appears in the admin UI. Admins download `vendor-pdf.zip` manually and upload it through the browser; the server validates the ZIP magic bytes and extracts it via the same pipeline. `upload_max_filesize` errors are reported specifically.
- **Markdown fallback note** — The installer panel now explains that client-facing reports remain downloadable as Markdown even when the PDF library cannot be installed.
- **`client_md` download type** — `body_client_md` can be downloaded as `wpmar-report-{id}-client.md` from the report detail screen, independently of the PDF library.
- **PDF availability awareness** — On the report detail screen, the "PDF をダウンロード（クライアント向け）" button is replaced with "Markdown をダウンロード（クライアント向け）" when the PDF library is not installed.
- **`pdf_enabled` warning** — A warning note appears next to the "PDF を uploads に書き出して保存" checkbox when the PDF library is not installed.
- **`.vscode/bin/phpcs` search order fix** — Homebrew's `phpcs` 4.x is incompatible with WordPress Coding Standard (`^3.x` required); the shim now searches Composer-installed `phpcs` before Homebrew.

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
git commit -am "release: 1.0.0-RC7"
git push origin main

# 2. Tag and push (this triggers release.yml). Bare semver matches Stable-tag style:
git tag 1.0.0-RC7
git push origin 1.0.0-RC7
# (v-prefixed tags like v1.0.0-RC7 are also accepted.)
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
