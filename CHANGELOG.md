# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Documentation

- **README 4種に診断ログ（動作ログ）の使い方を追記** — 「今すぐ実行」パネルのステップ表示・失敗時のログDLリンクの説明、およびログ行フォーマット・ステップの流れ・「最後の行が停止箇所」という読み方・ログの取得方法（DL手順・保存場所・保持件数20件・25分無応答ジョブの自動失敗化）を README.md / README-ja.md / readme.txt / readme-ja.txt の使い方セクションに追加。コード変更なし。

## [1.1.1] - 2026-07-09

### Changed

- **Report user-information section rendered as a table** — The 【ユーザー情報】 section listed privileged users as tab-separated lines, which the client PDF (Markdown → Parsedown → mPDF) collapsed into hard-to-read unaligned text. Both the client and operator report bodies now emit the list as a GFM pipe table (ID / ユーザー名 / 表示名 / メールアドレス / 権限 / 登録日), which the existing PDF stylesheet renders as a bordered table. Literal `|` characters in user fields are escaped so free-text display names cannot break the table layout. No data collection or PDF-writer changes.

## [1.1.0] - 2026-07-09

### Added

- **Diagnostics: per-job step logging** — Audit runs now write an unbuffered, per-job log (`wp-content/uploads/wpmar/logs/`, one line flushed per phase) so a run that stalls or dies mid-execution (OOM, host timeout) can be diagnosed from its last recorded step instead of leaving no trace. Covers the async job dispatcher, the full run pipeline (gather/diff/persist/render/mail/PDF/retention), the slowest sub-phases (checksums, security ops), multisite per-site segments, and the synchronous `wp wpmar audit run --sync` CLI path. A shutdown handler captures fatal errors (including `E_USER_ERROR`) that bypass the normal try/catch, logging the failure and releasing the run lock. Log files are capacity-limited to the 20 most recent runs.
- **Diagnostics: stale-job auto-recovery** — A job stuck in `running` because its process was killed hard enough that no handler ever ran (e.g. `SIGKILL`, OOM killer) is now automatically flipped to `failed` once its heartbeat goes stale (25+ minutes), checked opportunistically whenever the job-status REST endpoint or Reports screen is accessed. The `{prefix}wpmar_jobs` table gains `step` and `log_path` columns.
- **Reports screen — 診断ログ (Diagnostics) section** — Lists recent jobs that have a log file (status, last step, updated time) with an on-screen tail preview (last ~200 lines) and a capability + per-job-nonce-gated download link. The job-status polling panel (settings screen) now also shows the current step and, on failure, a log download link.

## [1.0.0] - 2026-07-05

First stable release. Promoted from the `1.0.0-RC` series with no functional changes to the audit/report feature set (scheduled auditing, multisite rollup, checksums, security ops, mail/PDF/CLI output, report storage, GitHub Releases updater). Tested up to WordPress 7.0.1. This release also lands the security hardening below.

### Security

- **PDF library installer — hardened archive handling (RCE fix)** — The on-demand PDF library installer no longer extracts the downloaded/uploaded `vendor-pdf.zip` directly into the plugin directory with `ZipArchive::extractTo()`, and no longer `require_once`s a PHP file straight out of the freshly-extracted archive. Archives are now unpacked into an isolated staging directory with per-entry validation — absolute paths, `..` traversal, symlinks, and any top-level entry other than `vendor/` or `fonts/` are rejected before anything is written — and only the validated directories are moved into place. Combined, this closes an arbitrary-code-execution / zip-slip vector where a crafted upload could plant or execute PHP inside (or outside) the plugin tree. The freshly-installed library is loaded on the normal admin-page reload rather than executed in the upload request. `WPMAR_PDF_Writer` font/library loading is unchanged.
- **PDF library installer — capability raised to `install_plugins`** — The three installer AJAX handlers (`wpmar_install_pdf_library`, `wpmar_pdf_preflight`, `wpmar_pdf_manual_upload`) and the settings-panel install UI now require `install_plugins` instead of `manage_options`. This matches the true impact (installing executable library code), closes a multisite privilege-escalation path (a subsite administrator has `manage_options` but not `install_plugins`), and makes the installer honour `DISALLOW_FILE_MODS`.
- **PDF library installer — upload validation** — The manual upload now verifies the file is a genuine PHP HTTP upload (`is_uploaded_file()`) and enforces an 80 MB size cap (the official bundle is ~30 MB), in addition to the existing extension and `PK` magic-byte checks. Extraction is also guarded against decompression bombs via a 300 MB uncompressed-size cap.
- **PDF library installer — optional checksum pinning** — The installer verifies the archive's SHA-256 against a pinned digest when one is provided via the `WPMAR_PDF_VENDOR_ZIP_SHA256` constant or the `wpmar_pdf_vendor_zip_sha256` filter; extraction is aborted on mismatch. No digest is pinned by default (behaviour unchanged for existing installs). The release pipeline now publishes `vendor-pdf.zip.sha256` alongside the bundle so the digest can be pinned.
- **Capability-before-nonce ordering (defense-in-depth)** — `WPMAR_Admin_Menu::handle_post`, `WPMAR_Network_Admin_Menu::handle_post`, and `WPMAR_Reports_Page::maybe_stream_bulk_zip` now perform the capability check before nonce verification (request identification → capability → nonce), matching WordPress convention. Both checks always ran before any side effect, so this is a consistency hardening rather than an exploitable fix.
- **Uploads path symlink resolution (defense-in-depth)** — `WPMAR_MD_Writer::absolute_path_from_upload_relative()` and `delete_if_upload_relative()` now resolve symlinks with `realpath()` and confirm the target stays within the uploads root, in addition to the existing `..` rejection and string-prefix check. A symlink placed inside the uploads directory can no longer be followed out of it. Not-yet-written paths are still permitted.
- **Read-only report-download GET (defense-in-depth)** — `WPMAR_Reports_Page::maybe_stream_report_download()` no longer writes to the database from the GET request. When a report has no persisted PDF, the on-the-fly copy is rendered to a temporary file, streamed, then removed — the download performs no durable state change. Audit-run-time PDF persistence (`WPMAR_Runner` / CLI) is unchanged.

## [1.0.0-RC14] - 2026-07-01

### Changed

- **PDF embedded font — BIZ UDGothic → Noto Sans JP** — The bundled PDF font has been replaced from BIZ UDGothic (Regular + Bold) to Noto Sans JP (Regular + Bold). Because mPDF cannot embed CFF/OpenType (postscript) outlines and Google distributes Noto Sans JP only as a single variable TTF (no distinct bold weight), the release build now instances the weight axis into static Regular (400) and Bold (700) TrueType fonts with fontTools (`bin/build-vendor-pdf-zip.sh` and `.github/workflows/release.yml`). Full glyph coverage is kept — mPDF subsets each generated PDF, so arbitrary Japanese (site/plugin names) still renders without missing glyphs. `WPMAR_PDF_Writer` now registers `notosansjp` (`NotoSansJP-Regular.ttf` / `NotoSansJP-Bold.ttf`) with the same `sun-exta` fallback when the fonts are absent.

### Migration

- **Re-install prompt when the bundled font is stale** — Fonts ship inside the on-demand `vendor-pdf.zip`, which a plugin update does not re-download (the upgrade hooks preserve the existing `fonts/`). Installs carrying the previous BIZ UDGothic bundle would otherwise silently fall back to `sun-exta`. The PDF library settings panel now detects this (mPDF present but the expected Noto fonts missing via `WPMAR_PDF_Installer::fonts_present()`) and shows a "再インストールが必要" state that re-downloads the current `vendor-pdf.zip`. `maybe_cleanup_legacy_fonts()` additionally removes the superseded `BIZUDGothic-Regular.ttf` / `BIZUDGothic-Bold.ttf`.

## [1.0.0-RC13] - 2026-06-29

### Changed

- **Client-facing reports now show theme/plugin display names instead of slugs** — In the client email and PDF, the change-history section ("変更履歴") and the file-integrity (checksum) section now render human-readable display names (e.g. `Snow Monkey`, `Advanced Query Loop`) instead of slugs (`snow-monkey`, `advanced-query-loop`). Snapshot data stays slug-keyed for compact diffing; the conversion happens only at the output layer. Operator-facing email and the Markdown export keep slugs unchanged. A new `WPMAR_Runner::build_display_name_maps()` helper derives slug→display-name maps from the live inventory (theme `name` / plugin `title`), `difference_summary()` now emits two changelog bodies (slug for operators, display name for clients), and `render_checksum_client_section()` accepts a slug→display-name map. When a display name is unavailable (e.g. a removed plugin no longer in the inventory) it falls back to the slug.

## [1.0.0-RC12] - 2026-06-27

### Changed

- **Dry run is now asynchronous** — "ドライラン" (single-site and network) is enqueued through Action Scheduler like "今すぐ実行" and returns immediately, addressing the CloudFront 504 timeout when the data-collection phase itself (not PDF) is the slow part. When Action Scheduler is unavailable the run falls back to the previous synchronous path with its inline preview.
- **Mode-aware job polling** — `WPMAR_Admin_Menu::render_job_flash()` / `render_job_status_panel()` take a `mode` argument ('full' | 'dry'). The flash notice, panel heading, and completion text adapt to the mode (a `data-wpmar-job-mode` attribute drives the poller). On completion a dry-run job renders its compact `dry_brevity` summary instead of download links; a full run shows the report/preview/download links as before. New localized strings `pollDoneDry` / `flashDoneDry`.
- **Leaner REST payload for dry runs** — `WPMAR_Jobs_REST` returns only the compact `dry_brevity` summary for dry-run jobs and drops the bulky `dry_preview` dataset.
- **`vendor-pdf.zip` no longer bundles Action Scheduler** — `bin/build-vendor-pdf-zip.sh` removes `vendor/woocommerce` before packaging, so the on-demand PDF bundle ships only mPDF + Parsedown (+ deps). Action Scheduler ships solely in the plugin package under `lib/`, avoiding double-shipping.

### Fixed

- **"New version available" notice persisting after updating to the latest version** — `check_for_update()` now unsets any stale `response` entry when the installed version is current (in addition to recording `no_update`), and `after_update()` clears the `update_plugins` site transient so the dashboard notice disappears immediately instead of lingering until the next throttled update check.

## [1.0.0-RC11] - 2026-06-27

### Fixed

- **Dashboard one-click update failing with "パッケージをインストールできませんでした。" (package could not be installed)** — `WPMAR_GitHub_Updater::extract_zip_url()` selected the first release asset whose content type was a zip. Because a release carries more than one zip asset (the on-demand `vendor-pdf.zip` alongside the plugin zip) and the GitHub API does not guarantee asset order — `vendor-pdf.zip` is in fact returned first — WordPress tried to install `vendor-pdf.zip` (mPDF/fonts only, no plugin header) and failed. Manual installation of the plugin zip worked because it targets the correct archive directly. The asset is now matched by name (must start with the plugin slug `wp-maintenance-audit-reporter` and end in `.zip`), so the plugin zip is always selected regardless of asset order; the `zipball_url` fallback is unchanged. The plugin slug is now shared via a `PLUGIN_SLUG` class constant.

## [1.0.0-RC10] - 2026-06-26

### Added

- **Asynchronous audit jobs (Action Scheduler)** — "今すぐ実行" (single-site and network) now enqueues a background job and returns immediately, eliminating the CloudFront 504 gateway timeout on long audits. Adds `WPMAR_Job_Dispatcher` (`enqueue_audit_job()` / `run_audit_job()`), a `{$wpdb->prefix}wpmar_jobs` tracking table with `WPMAR_Jobs_Repository` (queued → running → done|failed), and bundles Action Scheduler in `lib/action-scheduler/`. The library is loaded at plugin-file inclusion time (before `plugins_loaded`) so its queue API initialises; managed via Composer (`woocommerce/action-scheduler`) and copied into `lib/` at build time.
- **Job-status REST endpoint** — `GET /wpmar/v1/jobs/<id>` (requires `manage_options`) returns the job's lifecycle state and, on completion, the report URL plus nonce-signed Markdown/PDF download links.
- **Admin polling UI** — After "今すぐ実行", a top flash notice and a "レポート生成ジョブ" panel poll the REST endpoint (~2.5 s) showing queued → running → completed, then render preview/download links. The job id is carried via a post/redirect/get parameter so the panel survives page reloads.
- **WP-CLI `wp wpmar audit run --sync`** — Synchronous, CloudFront-bypassing fallback (`[--dry-run] [--network] [--no-snapshot]`) for production debugging and manual operation. The existing `wp maintenance-audit run` command is unchanged.
- **Unit tests** — `WPMAR_Jobs_Repository` id/scope sanitisers and `WPMAR_Job_Dispatcher` (Action-Scheduler-unavailable degradation; `run_audit_job()` unknown-id / non-queued idempotency guards).

### Changed

- **Monthly WP-Cron audit** — `WPMAR_Scheduler::handle_event()` now enqueues the audit through Action Scheduler (synchronous fallback when the library is absent) and reschedules the monthly chain immediately, so cadence is preserved regardless of when the queued job runs.
- **Network "今すぐ実行"** — Migrated from the `wpmar_run_network_audit_manual` single-event path to the unified Action Scheduler job system with the shared polling UI. The legacy single-event path is retained as a fallback when Action Scheduler is unavailable.
- On completion the queued flash notice text flips to "レポートが生成されました。" (error notice on failure).

### Note

- Action Scheduler's `actionscheduler_*` tables are intentionally left intact on uninstall, as the library may be shared with other plugins (e.g. WooCommerce).

## [1.0.0-RC9] - 2026-06-18

### Fixed

- **Checksum settings — "プラグイン除外" label** — Renamed to "プラグイン除外パス" to match the existing "コア除外パス" label.

### Added

- **Directory exclusions in checksum exclude lists** — Both core and plugin exclude lists now support directory prefixes. Append `/` or `/*` to exclude all files under a directory (e.g. `wp-admin/` or `wp-admin/*` for core; `akismet:views/` for a plugin). Previously only exact file paths were matched. The `normalize_path_set` helper has been replaced by `build_exclude_set` (returns separate `exact` and `dirs` buckets) and `is_excluded` (exact match + prefix match). The settings page description has been updated to document the new syntax.

## [1.0.0-RC8] - 2026-06-12

### Added

- **WP-CLI `--same-setting` flag (network)** — `wp maintenance-audit run --network --same-setting` audits the main site only instead of all target sites. Useful when all sites in the network share identical plugins, themes, and configuration.
- **WP-CLI `--id=<blog_id>` flag (network)** — `wp maintenance-audit run --network --id=2` audits a single specified blog ID only. Takes precedence over `--same-setting` when both flags are provided. An error is raised if the blog ID does not exist on the network.
- **Network admin — "実行範囲" run-scope selector** — A radio-button group above the snapshot checkbox in the network settings page lets operators choose the audit scope for both "ドライラン" and "今すぐ実行": (1) すべての対象サイト（デフォルト）, (2) 親サイトのみ（`--same-setting` 相当）, (3) 特定のサイトのみ（`--id=<blog_id>` 相当、blog ID 数値入力付き）. Invalid or non-existent blog IDs are validated before execution with an error notice.

### Fixed

- **`WPMAR_Network_Runner::resolve_blog_ids()` — nonexistent blog ID guard** — When `target_blog_id` is set to a blog ID that does not exist (e.g. via a direct runner call or a stale WP-Cron payload), `resolve_blog_ids()` now returns an empty array instead of passing the ghost ID to `switch_to_blog()`. The run completes safely with zero segments audited.

## [1.0.0-RC7] - 2026-06-12

### Changed

- **Output file naming — domain, audience, and date** — Markdown and PDF artefacts now embed the site domain, audience label, and date in the filename. Administrator-facing Markdown: `wpmar-report-{domain}-admin-{Ymd}-{His}.md`; client-facing PDF: `wpmar-report-{domain}-client-{Ymd}-{id}.pdf`. Network rollup follows the same pattern with the `wpmar-network-report-` prefix. Previously all artefacts used `wpmar-report-{YmdHis}.md` / `wpmar-report-{id}.pdf` with no domain or audience distinction.
- **PDF embedded font — Noto Sans JP → BIZ UDGothic** — Replaced the Noto Sans JP variable font (single file; no font-weight distinction in mPDF) with BIZ UDGothic Regular + Bold (two separate TTF files). mPDF can now render Regular and Bold weights correctly in exported PDFs. The legacy `NotoSansJP.ttf` is removed automatically on the next plugin load via `WPMAR_PDF_Installer::maybe_cleanup_legacy_fonts()`.

### Fixed

- **`vendor/` preserved across plugin updates** — `WPMAR_PDF_Installer` hooks into `upgrader_source_selection` and `upgrader_process_complete`. When an update is detected to be this plugin (matched by the incoming package's folder + main file, so it works for the manual ZIP-upload _install_ flow whose `hook_extra` omits the `plugin` key, the dashboard "update now" flow, and WP-CLI / auto-update), any existing `vendor/` is moved to `wp-content/wpmar-vendor-backup/` before WordPress removes the plugin directory, then restored once the new files are in place. The hooks register in every context (not just admin), and a self-heal step restores an orphaned backup on the next load if an update was interrupted mid-copy. This eliminates the need to re-install the PDF library after each plugin update.

## [1.0.0-RC6] - 2026-06-11

### Added

- **Network settings — ステータス section** — Added "直近の完了時刻 (UTC 保存)" and "WP-CLI" items to the network admin status panel, matching the single-site settings page.
- **Network settings — タイムゾーン hint** — Added description text under the timezone field ("例: Asia/Tokyo。PHP が解釈できる識別子を指定してください。") to match the single-site page.
- **Network settings — ドメインゲート callout** — The "許可ホスト" row now shows the detected site host and a match/mismatch/empty-state feedback block, identical to the single-site implementation.
- **Network settings — メール通知 From fields** — Split the single "From" row (two unlabelled inputs) into separate "送信元メールアドレス（オプション）" and "送信元表示名（オプション）" labelled rows, matching the single-site layout.
- **Network settings — 出力・保持 split** — Replaced the single "出力・保持" panel with three distinct panels: "保持期間" (with description), "レポートをファイルとして自動保存" (Markdown and PDF rows with descriptions and PDF-missing warning), and "PDF ライブラリ（mPDF）" (renders `WPMAR_PDF_Installer::render_panel()`).
- **Network settings — 検証ツール description** — Added the full QA-mailbox description text to the "検証ツール" panel, matching the single-site page.
- **Network settings — スナップショット descriptions** — Added both snapshot-behaviour description spans under "スナップショットを保存する（差分比較用）", matching the single-site page.
- **Network settings — WP-CLI run notice** — Added a description below the action buttons with the `wp maintenance-audit run --network` command and an explanation of the background-queue behaviour.
- **Network settings — `DISABLE_WP_CRON` notice** — When `DISABLE_WP_CRON` is `true`, a red `notice-error` banner appears at the top of both the network and single-site settings pages, explaining that scheduled and manual execution are both non-functional and directing operators to WP-CLI or an external cron calling `wp cron event run --due-now`.
- **Background execution for "今すぐ実行" (network)** — The network "今すぐ実行" button now schedules an immediate WP-Cron single event (`wpmar_run_network_audit_manual`) via `wp_schedule_single_event()` + `spawn_cron()` instead of running synchronously, eliminating 504 gateway timeouts on large networks. A new constant `WPMAR_HOOK_NETWORK_MANUAL_RUN` and handler `WPMAR_Scheduler::handle_network_manual_event()` were added.
- **WP-CLI `--no-snapshot` flag** — `wp maintenance-audit run --no-snapshot` (and `--network --no-snapshot`) now skips snapshot persistence. Both `WPMAR_Runner` and `WPMAR_Network_Runner` honour an explicit `persist_snapshots: false` value that takes priority over the CLI trigger's "always persist" default.

### Removed

- **Network settings — 含めるサイト checkboxes** — Removed the "アーカイブ済み", "スパム", "削除済み" checkboxes from the "対象サイト" panel. The "最大サイト数" and "除外する blog ID" fields remain.
- **Network settings — 許可パスプレフィックス field** — Removed the "許可パスプレフィックス（任意）" input and all related logic from `WPMAR_Domain_Gate` and `WPMAR_Network_Settings`. Subdirectory filtering can be achieved via "除外する blog ID".

### Fixed

- **Network settings — busy overlay missing** — The `#wpmar-busy-overlay` element was absent from the network settings page HTML, so the "ドライランを実行しています…" / "今すぐ実行しています…" overlay never appeared. The element is now rendered, matching the single-site page.
- **`WPMAR_Network_Runner` — `add_site_transient()` fatal error** — `add_site_transient()` does not exist in WordPress core. The function call was replaced with `get_site_transient()` (existence check) + `set_site_transient()` (lock set). This resolved a PHP Fatal error when running `wp maintenance-audit run --network` from the CLI.
- **Network "今すぐ実行" — `DISABLE_WP_CRON` behaviour** — When WP-Cron is disabled, the button no longer attempts a synchronous run (which would risk 504). Instead it shows an error notice directing the operator to WP-CLI or per-site manual execution.

## [1.0.0-RC5] - 2026-06-10

### Added

- **Mail send failure logging** — `send_pair()` now registers a scoped `wp_mail_failed` listener that appends timestamped entries to `wp-content/debug.log` when `WP_DEBUG_LOG` is enabled, making previously silent `wp_mail()` transport failures visible for diagnosis.
- **Empty recipient warnings** — When mail is enabled but `client_to` or `admin_to` resolves to no valid addresses after sanitisation, a warning is written to `wp-content/debug.log` so the misconfiguration is discoverable without triggering a send attempt.
- **Empty recipient admin notices** — The plugin settings page now surfaces a `warning`-level notice for each empty recipient list and an `error`-level notice when both are empty, while mail sending is enabled.
- **`WPMAR_PDF_Installer`: pre-flight check** — Before attempting the GitHub download, the installer now validates that the plugin directory is writable and that at least 150 MB of disk space is free. Failures surface actionable error messages: the exact path and a `chmod 755` hint for permission issues; the current free-space value for disk-full situations.
- **`WPMAR_PDF_Installer`: manual ZIP upload fallback** — When the automatic GitHub download fails (network restriction, firewall, etc.), a "手動インストール" panel is revealed in the admin UI. Admins can download `vendor-pdf.zip` directly from the link shown, then upload it through the browser. The server validates the ZIP magic bytes (`PK` header) and extracts it via the same `ZipArchive` / `unzip_file` pipeline. Upload errors such as `upload_max_filesize` exceeded are reported with specific messages.
- **`WPMAR_PDF_Installer`: Markdown fallback note** — The installer panel now informs admins that client-facing reports can still be downloaded as Markdown when the PDF library cannot be installed.
- **`client_md` download type** — `body_client_md` (client-facing Markdown) can now be downloaded directly from the report detail screen as `wpmar-report-{id}-client.md`, independent of the PDF library.
- **PDF library availability awareness in report detail** — The "PDF をダウンロード（クライアント向け）" button is replaced with "Markdown をダウンロード（クライアント向け）" when the PDF library is not installed, ensuring a client-facing export is always accessible.
- **`pdf_enabled` warning in settings** — A warning note is displayed next to the "PDF を uploads に書き出して保存" checkbox when the PDF library is not installed, explaining that the setting has no effect until the library is installed.

### Fixed

- **`.vscode/bin/phpcs` search order** — Homebrew's `phpcs` 4.x is incompatible with WordPress Coding Standard (which requires `^3.x`); the shim now searches Composer-installed `phpcs` (`~/.composer/vendor/bin/phpcs`) before Homebrew to ensure the WordPress standard is found.

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
