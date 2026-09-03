# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.5.6] - 2026-09-03

### Fixed

- **`should_persist_snapshots()` couldn't tell "caller didn't say" apart from "caller explicitly opted out."** `run()` / `WPMAR_Network_Runner::run()` backfilled a `false` default via `wp_parse_args()` whenever `persist_snapshots` was omitted, which tripped the "explicit false always opts out" branch before the "cron/cli always save" branch could ever run — the exact bug fixed for the WP-Cron call site alone in 1.5.0/1.5.1 (`c9b345e`), except the underlying method still silently skips persistence for *any* future caller that forgets to pass the key, with no warning or log line. `persist_snapshots` is now tri-state (`null` = trigger-derived default, explicit `false` = opt-out, anything else = honoured as-is); every existing call site already passes the key explicitly, so no one's observed behaviour changes — only the unsafe default when a key is missing does.

### Added

- The report a diff was compared against, and whether this run actually refreshed it, are now recorded and shown: `summary_json` gains `baseline` (per-dimension `captured_at` of the snapshot each diff compared against, or `null`) and `snapshots_persisted`; the admin-facing report body gains "比較基準:" / "今回の実行でスナップショットを更新:" lines under "前回スナップショットからの差分"; network rollup reports get the same in both the merged admin body and `summary_json`'s `per_blog` entries (via two new columns on `{$wpdb->prefix}wpmar_network_segments`, `baseline_json` and `snapshots_persisted`, needed because the async per-site job path round-trips through that table instead of keeping the in-memory result). `WPMAR_Snapshot_Repository::latest_row()` (envelope-preserving `latest()`) backs all of this. The site-level and network "システム機能" snapshot section (1.5.3) also gains an always-visible "基準の鮮度（最終保存日時）" list, with a warning when the oldest dimension's baseline is more than 60 days old (unmeasured provisional threshold — one monthly-cycle's grace).

## [1.5.5] - 2026-09-03

### Added

- The PDF library (mPDF + fonts, ~94 MB) now installs to `wp-content/wpmar-pdf-lib/`, outside the plugin directory, instead of the plugin's own `vendor/`/`fonts/`. An existing in-plugin install is migrated there automatically on first load after updating, guarded by `is_dir()`/`is_file()` checks only (no DB flag), so an interrupted migration self-heals on the next load rather than getting stuck. The location is filterable via `wpmar_pdf_lib_dir` (default `WP_CONTENT_DIR . '/wpmar-pdf-lib/'`); uninstalling the plugin deletes it too, unless the new `wpmar_pdf_lib_delete_on_uninstall` filter returns `false`.
- When `wp-content` isn't writable, both a fresh install and the automatic migration fall back to the plugin directory instead of failing, matching pre-1.5.5 behaviour.

### Fixed

- **The PDF library could disappear entirely when the plugin was updated while deactivated.** The existing `vendor/`-backup safeguard (`upgrader_source_selection` / `upgrader_process_complete`) only ever ran while the plugin was active — WP-CLI, the dashboard "Update Now", and a zip overwrite were all covered, but an update applied while the plugin was deactivated (PHP never loads, so the hooks never register) silently wiped `vendor/`/`fonts/` with no way to recover them. Moving the library outside the plugin directory removes the underlying cause instead of adding another special case to the backup/restore hooks, which remain in place for one more cycle as a fallback for sites that couldn't migrate (e.g. a read-only `wp-content`) and for development checkouts.

### Changed

- The PDF library settings panel now states where the library is actually installed and notes that a plugin update no longer requires reinstalling it.

## [1.5.4] - 2026-09-03

### Added

- Network rollup reports can now narrow which sites' data appears in the merged report — "all sites" (unchanged default), "main site only", or "main site + selected child sites" — via a new "レポート出力範囲" (Report output scope) setting on the network admin screen. This is a *report* filter only: every target site is still audited and its `wpmar_snapshots` diff baseline still updates regardless of scope, so a site excluded from the report keeps a fresh baseline and rejoins the report later without a months-wide changelog. Implemented as a single filter (`WPMAR_Network_Runner::filter_segments_for_report()`) applied inside `finalize_rollup()`, the one convergence point every execution path (admin dry run, admin "run now", WP-Cron, WP-CLI `--network`, the async job dispatcher) already shares, so no path can miss it. A scope that resolves to zero segments (e.g. a selected blog ID that no longer exists) falls back to the full, unfiltered set rather than shipping an empty report, and logs a warning. The rollup's `summary_json` keeps `blog_ids` (every audited site) and adds `report_blog_ids` (only the sites shown in the report) as a separate key, so `sites_audited` keeps its existing meaning.

### Changed

- **"Run now" on the network admin screen always audits every target site now.** The existing run-scope radio (all sites / main site only / a specific site) previously applied to "Run now" as well as dry runs; narrowing "Run now" left the sites it skipped with a stale snapshot baseline once the run completed, since only sites that actually ran `run_site_segment()` got a fresh snapshot. Dry runs don't have this problem (they never persist snapshots), so the selector — relabelled "ドライランの対象サイト" (dry run target sites) — is now dry-run-only. A scoped manual run is still available via WP-CLI's `--same-setting` / `--id=<blog_id>`.

## [1.5.3] - 2026-09-02

### Added

- New snapshot preview on both the site-level and network "システム機能" (System Tools) screens. The diff-baseline snapshots (`{$wpdb->prefix}wpmar_snapshots`) have always been stored but never visible anywhere short of a direct `wp db query`; a "スナップショットをプレビュー" button now expands the latest 2 generations of core/themes/plugins/users as read-only Markdown inside a `<pre>` (no Parsedown dependency — the PDF library, the only thing that ships Parsedown, is an optional on-demand install). On multisite, the site-level section is restricted to super admins: plugin/theme snapshots are network-shared and the users snapshot carries plain-text email addresses, neither of which a subsite admin's `manage_options` should expose. The network admin screen adds a site picker (`WPMAR_Network::target_blog_ids()`'s own allow-list; an arbitrary blog id is rejected) to view any one site's snapshots, reading exactly one site's tables per request.
- `WPMAR_Snapshot_Repository::recent()` / `types()` / `table_exists()`: `latest()` discarded `id`/`captured_at`, which the new preview needs to show *when* each generation was captured; `types()` lets a future dimension appear in the preview without a code change; `table_exists()` lets the network cross-site view tell "never audited" apart from "table not yet created on this blog" (not hypothetical — `upgrade_database_if_needed()` only runs once a request hits a given blog).

## [1.5.2.1] - 2026-09-02

### Added

- `wp wpmar audit run --network` now prompts for confirmation (`WP_CLI::confirm()`) before a synchronous run (no `--async`, dry-run or not) — this loops every target site in a single PHP process (`WPMAR_Network_Runner`'s `switch_to_blog()` loop, no per-site job splitting) and can exceed a host's execution/cron timeout on large networks. `--yes` bypasses it as usual; `--same-setting` and `--id=<blog_id>` (single-site scope) are unaffected.

## [1.5.2] - 2026-09-02

### Added

- Core update copy now distinguishes "security patch applied, just an old major" from "known-vulnerable" using wp.org's stable-check API (`https://api.wordpress.org/core/stable-check/1.0/`), instead of a single generic "an update is available" message for both. Applies to the admin body, the client body, and PDF/mail (both derive from the client body). Adds a `wp_insecure` machine-readable summary code and a dedicated warning-count slot.

### Changed

- `warning_count` now increases by 2 (not 1) for a site running a core version with unpatched known vulnerabilities — "behind" and "known-vulnerable" are tracked as separate signals.

## [1.5.1] - 2026-08-21

### Fixed

- `--no-snapshot` never worked on either `wp maintenance-audit run` or `wp wpmar audit run` — WP-CLI's argument parser rejected it with `Error: unknown --snapshot parameter` because only the negative form was declared. Renamed to a positive flag, `--skip-snapshot`.
- `wp wpmar storage migrate --no-revert` (and `--no-dry-run`, `--no-network`) were silently read as `true` by `isset( $assoc_flags[...] )`, so `--no-revert` without `--dry-run` actually reverted storage instead of migrating it. All CLI bool/int flag reads now go through the new `WPMAR_CLI_Flags` helper, which honours WP-CLI's `--no-<flag>` negation.
- Admin-facing WP-CLI hints (loopback notice, settings pages, network admin) still referenced the removed `wp maintenance-audit run` command and the no-longer-required `--sync` flag.

### Changed

- Consolidated the two WP-CLI command namespaces into one: `wp wpmar audit` (run/test), `wp wpmar report` (list/delete/export), and `wp wpmar storage` (migrate). The legacy `wp maintenance-audit` namespace is removed.
- `wp wpmar audit run` is synchronous by default; `--sync` is now a backward-compatible no-op and `--async` opt-in enqueues the run on the Action Scheduler queue instead.
- `--same-setting` and `--id=<blog_id>` (previously legacy-only) and the `test` subcommand are now available under `wp wpmar audit`.

## [1.5.0] - 2026-08-20

### Fixed

- **The WP-Cron monthly run never persisted snapshots.** `WPMAR_Scheduler::handle_event()` built its `run()`/`run()`-network call args without a `persist_snapshots` key; `run()`'s `wp_parse_args()` backfilled a default `false`, which `should_persist_snapshots()` treated as an explicit opt-out before ever reaching its "cron always saves" branch. Every scheduled monthly audit ran and reported normally but silently never updated the diff baseline — WP-CLI and the admin-screen checkbox were unaffected, since both always pass the key explicitly. Fixed in commit `c9b345e` by having the scheduler pass `persist_snapshots: true` explicitly. *(Documented retroactively in 1.5.6 — this fix predates this file's `[1.5.0]`/`[1.5.1]` entries above, which didn't mention it. If your site went through one or more monthly cycles on a version older than 1.5.1, the affected months' reports compared against a stale baseline; the next cycle after upgrading self-corrects.)*

### Changed

- Self-update mechanism now runs on the shared `l2d-wp-github-update-lib` library (`1.1.0`, vendored into `lib/l2d-updater/` via `git subtree`) instead of the plugin-local `WPMAR_GitHub_Updater` implementation. The documented cache transient key (`wpmar_github_release_cache`) and filter names (`wpmar_github_updater_cache_ttl`, `wpmar_github_updater_backoff_ttl`) are passed explicitly to the library and continue to work unchanged.
- The "Description" section in the update details modal now comes from this plugin's own header `Description:` line (via the library's default behaviour) instead of a hardcoded string.
- `release.yml` now publishes as a GitHub Release **draft**; a maintainer must run `gh release edit <tag> --draft=false` after verifying the assets before any site can pick up the release via auto-update. Added to close off a real risk: sites running `forced-auto-update-controller` alongside this plugin would otherwise pick up a freshly published release unattended.
- `bin/build-zip.sh` now hard-fails if `lib/l2d-updater/loader.php` or `class-l2d-github-updater.php` is missing from the staged tree (the main plugin file `require`s the loader, so a missing copy would be a fatal error on every site).
- `phpcs.xml.dist` now excludes `lib/*` (the vendored library copy is not linted against this plugin's coding standards).

### Added

- `wpmar_github_updater_enabled` filter (kill switch): returning `false` disables the update checker entirely, e.g. as an emergency rollback lever.

### Fixed

- The Action Scheduler bundling step in `bin/build-zip.sh` no longer ships a stray `.claude/` directory that had been accidentally left inside the local `vendor/woocommerce/action-scheduler/` copy (unrelated to this migration, found while verifying the distributed-zip file list).

## [1.4.1] - 2026-08-18

### Fixed

- **GitHub-Releases update checker no longer falls back to the auto-generated zipball** — `WPMAR_GitHub_Updater::extract_zip_url()` fell back to `$body['zipball_url']` when no release asset matched the plugin slug by name. That zipball's inner directory is `owner-repo-<sha>/`, not the plugin's own directory name, so installing it would rename the plugin directory and deactivate the plugin. The fallback is removed; a release with no matching asset now correctly reports "no update available" (fail closed) instead of risking a broken install.
- **Update-availability comparison no longer trusts the `WPMAR_VERSION` constant alone** — `check_for_update()` compared the latest GitHub release against the `WPMAR_VERSION` constant, which can drift from the plugin header's `Version:` (the CI version check only ever covered the header, not this constant). A drifted constant could leave a stale "update available" notice in place after updating, or suppress a real update notice. The comparison now uses `$transient->checked[WPMAR_PLUGIN_BASENAME]` — the version WordPress core itself read from the plugin header — with `WPMAR_VERSION` kept only as a fallback and for the User-Agent string. Also added an `is_object( $transient )` guard, and the tag-name normalization now uses `preg_replace( '/^v/i', ... )` instead of `ltrim( $tag, 'v' )`, which trims by character set rather than by prefix.
- **Update-checker cache is now a site-wide transient, matching `Network: true`** — The cache (`wpmar_github_release_cache`) used `get_transient()`/`set_transient()` (per-blog), while the `update_plugins` transient it feeds is itself network-wide. On a multisite network this meant one GitHub API request per sub-site instead of one per network, unnecessarily consuming the unauthenticated rate limit (60 req/h). Switched to `get_site_transient()`/`set_site_transient()`/`delete_site_transient()` throughout; `uninstall.php` already covered the site-transient key pattern, so no change was needed there.
- **"Requires PHP" / "Tested up to" in the update details modal no longer hardcoded** — `plugin_info()` and `build_plugin_update_object()` each hardcoded `'6.0'` / `'7.4'` / `''` instead of reading the plugin header, so they could silently drift from the real `Requires at least:` / `Tested up to:` / `Requires PHP:` values (and the empty `tested` value meant the "Compatible up to" line never rendered in the modal). Both now read from the header via a shared `plugin_requirements()` helper using `get_file_data()`.
- **Distributed plugin zip no longer risks bundling dev/build files** — `.github/workflows/release.yml` and `bin/build-zip.sh` each maintained their own exclusion list, and the two had already drifted: CI's list omitted `bin/` (so the release zip bundled the build scripts themselves), while the local script's list omitted `fonts/` and `vendor-pdf.zip.sha256` (so a local build run after generating the bundled PDF fonts could ship them in the main zip). Introduced `.distignore` as the single source of truth for both; `release.yml`'s "Assemble plugin zip" step now calls `bash bin/build-zip.sh` directly instead of duplicating its logic.

### Changed

- **CI version check now covers 5 locations instead of 1** — `release.yml` previously verified only the release tag against the plugin header's `Version:`. It now also verifies the `WPMAR_VERSION` constant, `readme.txt`'s and `readme-ja.txt`'s `Stable tag:`, and `composer.json`'s `"version"`, failing the release job if any one of them drifts from the tag.

## [1.4.0] - 2026-08-09

### Added

- **wp.org plugin/theme metadata now cached (`wpmar_wporg_cache_ttl` filter, default 12h)** — `WPMAR_WPOrg_Client::fetch_plugin_information()`/`fetch_theme_information()` previously called `wp_remote_get()` unconditionally on every run. Results are now cached via `set_site_transient()`/`get_site_transient()` under `wpmar_wporg_plugin_{slug}`/`wpmar_wporg_theme_{slug}` keys — a network-wide cache (not per-blog), so on a multisite audit the first site to ask for a given slug primes it and every other site's segment hits cache instead of re-querying wp.org. Cache hit/miss counts are logged on the `gather:inventory-done` step for visibility. The 12h default TTL is an unmeasured estimate of wp.org metadata churn, not a benchmarked value — adjust via the filter if the logged hit rate suggests otherwise.
- **Explicit memory release before PDF generation** — `WPMAR_Runner::run()` and `run_site_segment()` now drop the heaviest parts of `$dataset` (`checksums`, `plugins.org`, `themes.org`) via a shared `release_heavy_dataset_memory()` helper and call `gc_collect_cycles()` once the rendered Markdown bodies no longer need the raw payload — most notably right before mPDF (memory-hungry) runs, and before a multisite site-segment result is handed back to accumulate in `$segments[]`.
- **Memory-usage WARN log line** — `WPMAR_Logger::step()` now checks `memory_get_usage(true)` against 80% of `memory_limit` on every step and logs one `WARN` line if crossed, so a run trending toward OOM is visible from whichever phase happened to be running (not only the handful of steps that already logged memory explicitly).
- **Network rollups now split into one independent async job per site** — A real (non-dry) network-scope audit job no longer loops every target site inside one Action Scheduler action. `WPMAR_Job_Dispatcher::run_audit_job()` now dispatches: it creates one `queued` row per target blog in a new `{$wpdb->prefix}wpmar_network_segments` table (`queued`→`running`→`done`/`failed`, an `attempts` counter for the retry work below, and only the rendered `client_body`/`admin_body` — never the raw per-site dataset), queues one `wpmar/run_network_site_segment` action per blog (each running `WPMAR_Runner::run_site_segment()` for exactly one site, in its own process), then one `wpmar/run_network_aggregate` action that waits for every segment to reach `done`/`failed` before finalizing the report. This is the fix for the structural memory ceiling of the old design: peak memory used to grow with site count and one site's OOM took the whole run down; now peak memory per action stays flat regardless of network size, and an OOM in one site's segment only fails that one segment. A `failed` (or timed-out) segment gets a "このサイトはエラーのため取得できませんでした" note in the merged report instead of its body, so a report still goes out for every site that finished. `wpmar_jobs` also gains an `attempts` column for the retry work below. Dry network runs (preview only) are unaffected — they still run synchronously, same as before. The old single-action fallback path (`WPMAR_Network_Runner::run()` called directly, used when Action Scheduler itself is unavailable or from WP-Cron/WP-CLI) is unchanged and unaffected — it never goes through the dispatcher.
- **Automatic retry for transient job/segment failures, capped at a fixed attempt count** — Targets recoverable failures (a passing server-load spike, a momentary wp.org timeout), not the structural "this site's data volume always OOMs" case the per-site split above already solves. `WPMAR_Jobs_Repository::mark_failed()` and `WPMAR_Network_Segments_Repository::mark_failed()` now each fire an action (`wpmar_job_marked_failed` / `wpmar_network_segment_marked_failed`) on success — the one point every caller of either method (this dispatcher's catch blocks, `WPMAR_Logger::handle_shutdown()`, both repositories' own stale-heartbeat sweeps, now refactored to go through `mark_failed()` per row instead of a bulk `UPDATE` so the action fires for those too) converges on, so retry policy lives in one listener per scope instead of being duplicated at each call site. `WPMAR_Job_Dispatcher::maybe_retry_job()`/`maybe_retry_segment()` listen for these and, while `attempts` is below `wpmar_job_max_attempts` (default 2 — one retry, shared by both scopes), reset the row to `queued` and `as_schedule_single_action()` a re-run after a backoff (`wpmar_job_retry_delay`, default 15 min, for jobs; `wpmar_segment_retry_delay`, default 5 min, for segments — both unmeasured defaults). Retrying a job resets it to `queued` and re-fires the whole thing from scratch (for a network job, this re-dispatches — segments from the failed attempt are cleared first via `delete_by_run()`); retrying a segment only touches that one `(run_id, blog_id)` row, with zero effect on sibling sites. A segment force-failed by the run's own 90-minute global wait timeout is marked non-retryable (`mark_failed(..., false)`) since finalize runs immediately after and the segment row is deleted once it does. Action-Scheduler-unavailable synchronous paths (dry-run, direct fallback calls) never create the rows these hooks fire from, so they're unaffected — same "wait for the next cycle" behaviour as before.
- **Persistent duration + peak-memory history for calibrating the timing filters above** — `wpmar_network_segments` rows are deleted once their run finishes, so nothing previously recorded how long a segment actually took, or how much memory it needed. `WPMAR_Network_Segments_Repository::mark_done()`/`mark_failed()` now both log one line (run id, blog id, `done`/`failed`, dispatch-to-completion duration, `memory_get_peak_usage(true)`, retry count) to a new `segment-history.log` in the plugin's private logs directory via `WPMAR_Logger::log_segment_outcome()`, and the single-site path (`WPMAR_Runner::run()`) gets the same treatment in a parallel `run-history.log` via `WPMAR_Logger::log_run_outcome()` — that one also records the site's `memory_limit` next to peak usage, so a run can be judged against the site's overall headroom rather than only against this plugin's own footprint. Unlike the per-job `run-*.log` files, these two files accumulate indefinitely (growth is inherently slow — one line per site per monthly run, not per request) and are the intended source of real data for eventually tuning `wpmar_network_segment_stale_minutes`/`wpmar_network_aggregate_max_wait`/the retry delays above away from their unmeasured defaults. Both histories are also rendered (newest entry first) on the matching システム機能 screen below, so reading them does not require file access.
- **New "システム機能" screen (site + network), and an `attempts` column on the existing diagnostics log** — Both reuse the plugin's existing `manage_options`/`manage_network_options` capability constants (no new capability introduced) and the existing admin-post + nonce pattern (new `wpmar_admin_action` branches in `WPMAR_Admin_Menu::handle_post()`/`WPMAR_Network_Admin_Menu::handle_post()`, not a new form-handling mechanism). Site-level (`WPMAR_System_Status_Page`, under the existing top-level menu): shows the shared wp.org cache's entry count with a "キャッシュをクリア" button, and this blog's `wpmar_run_lock` state (active/TTL) with a "強制解除" button for manually recovering a stuck single-site run. Network-level (`WPMAR_Network_System_Status_Page`, under Network Admin): the same wp.org cache clear button (the cache is shared, not per-blog), `wpmar_network_run_lock`'s own state/force-unlock, and — for any network run currently in flight — its `wpmar_network_segments` status counts (`queued`/`running`/`done`/`failed`) plus a table of failed sites with blog ID, site name, attempts, and error message (a completed run's segment rows are already deleted by the time this renders; see the persistent history above for looking back past that point). `WPMAR_Log_Viewer::render_section()`'s diagnostics table also gains an "試行回数" column so a job's current retry count is visible without querying the DB directly. The whole "診断ログ" section moved off the Reports screen to the top of the site-level システム機能 screen (`WPMAR_Log_Viewer`'s page gate and its view/download URLs now target `WPMAR_SYSTEM_STATUS_PAGE_SLUG`, and the admin CSS/JS loads there too), so per-job logs and the persistent run/segment history above are read from one place. Both screens are named "システム機能" rather than "システム状態", which read as too close to the plugin's own status output; the run-lock headings carry a one-line explanation of what the lock does and when force-unlocking is appropriate.

### Fixed

- **Network run lock (`wpmar_network_run_lock`) never released on a fatal error** — `WPMAR_Logger::handle_shutdown()` only ever deleted the single-site `wpmar_run_lock` transient; a network rollup killed by a fatal error (OOM, forced process termination) left `wpmar_network_run_lock` to expire on its own 20-minute TTL instead of being cleared immediately. The shutdown handler now checks the failed job's own `scope` column and releases the matching lock (`wpmar_run_lock` for `single`, `wpmar_network_run_lock` for `network`) — deliberately not both, so a fatal error on one scope can't clobber a legitimately in-progress run of the other scope.
- **Settings screens: the PDF attachment checkbox described the wrong storage path** — Site and network settings both said PDFs are written to `uploads/wpmar/pdf/`, dropping the `wp-content/` prefix that the Markdown checkbox directly above it already spelled out. Text-only fix; the actual storage location is unchanged (and is `wp-content/wpmar-private/pdf/` by default since 1.3.1 anyway).
- **`uninstall.php`: the private storage directory (`wp-content/wpmar-private/`) was never removed** — Uninstall only ever deleted the pre-1.3.1 fallback location (`wp_upload_dir()/wpmar`), so every report, client PDF, and diagnostics log written since 1.3.1 — the exact files the 1.3.1 storage move classified as sensitive — survived removing the plugin. `uninstall.php` now resolves the private base directory the same way `WPMAR_Private_Storage::configured_base_dir()` does (the `WPMAR_PRIVATE_STORAGE_DIR` constant, then the `wpmar_private_storage_dir` filter — both still in effect at uninstall time, unlike the plugin's own classes, which core never loads for `uninstall.php`) and deletes it. On multisite each blog's `site-{blog_id}/` subdirectory is removed inside the existing `get_sites()` + `switch_to_blog()` loop, so a filter whose value depends on the current blog resolves correctly, and the shared parent is then removed only if it is empty — never recursively, since an operator-chosen path may hold unrelated files. A relocated install also gets the well-known default location (`wp-content/wpmar-private`) cleaned, because moving the base directory only changes where *new* files are written and `WPMAR_Private_Storage::resolve()` keeps reading from the old default. Relatedly, `wpmar_uninstall_delete_uploads()` (the fallback location) ran once outside the multisite loop and therefore only ever cleaned the blog the uninstall was triggered from; it now runs per blog as well.
- **`uninstall.php`: multisite sub-site tables and network-wide (sitemeta) settings were never removed** — `wpmar_uninstall_drop_tables()` only ever dropped the current blog's tables, even though `WPMAR_Activator::activate_network()` creates `wpmar_reports`/`wpmar_snapshots`/`wpmar_jobs` on every blog via `get_sites()` + `switch_to_blog()` — sub-site tables (and their `wpmar_%` options) survived uninstall on a network. Separately, `wpmar_network_settings`/`wpmar_last_network_audit_completed_at`/`wpmar_wp_cron_last_fired_at` are written with `update_site_option()` into `$wpdb->sitemeta`, a table the existing `$wpdb->options` LIKE cleanup never reached. `uninstall.php` now mirrors the activator's per-blog loop on `is_multisite()` and adds an explicit `$wpdb->sitemeta` LIKE `wpmar_%` sweep plus `delete_site_option()` calls for the three known keys. The new `wpmar_network_segments` table (added for the network site-segment work below) is now part of the same drop list.

### Changed

- **A network rollup's parent job now stays `running` for longer, until every site's independent segment finishes** — Previously the parent job flipped to `done` as soon as the (synchronous) full site loop returned. Now it waits on every per-site action plus the aggregate action described above, capped by `wpmar_network_aggregate_max_wait` (default 90 min). The admin polling UI's contract (`queued`→`running`→`done`/`failed` on one job id) is unchanged, and the final report is identical in shape — only the wall-clock time to `done` increases, in exchange for the memory-ceiling fix above. If your monitoring alerts on a network job's `running` duration, its expected upper bound just changed.
- **Dev dependency: WordPress Coding Standards bumped to 3.4.1 (CVE-2026-45293)** — WPCS 0.14.1–3.4.0 evaluate the `$ver` argument of `wp_enqueue_script()` and friends through `eval()` in `WordPress.WP.EnqueuedResourceParameters::is_falsy()`, so running PHPCS over untrusted PHP could execute arbitrary code ([GHSA-3pwp-g2mj-5p3v](https://github.com/WordPress/WordPress-Coding-Standards/security/advisories/GHSA-3pwp-g2mj-5p3v), CVSS 8.6). This repository's `phpcs.xml.dist` uses the affected `WordPress` ruleset. `require-dev` now floors `wp-coding-standards/wpcs` at `^3.4.1` and `squizlabs/php_codesniffer` at `^3.13.5` (PHPCS 4.x is not supported by WPCS 3.x). **No impact on the distributed plugin**: `vendor/` is gitignored and excluded from the release zip, which is built with `composer install --no-dev` — WPCS never ships to users. The `composer audit` step in CI was failing on this advisory and is green again.
- **Dev dependency: PHP_CodeSniffer floored at 3.13.6 (CVE-2026-67434)** — PHP_CodeSniffer below 3.13.6 (and 4.0.0–4.0.1) passes unescaped input to a shell command, so running PHPCS could execute arbitrary OS commands ([GHSA-hmqg-cxww-wqhq](https://github.com/PHPCSStandards/PHP_CodeSniffer/security/advisories/GHSA-hmqg-cxww-wqhq), high severity, published 2026-08-05). `require-dev` now floors `squizlabs/php_codesniffer` at `^3.13.6` (still 3.x — PHPCS 4.x is not supported by WPCS 3.x) and `composer.lock` moves 3.13.5 → 3.13.6. **No impact on the distributed plugin**, for the same reason as the WPCS advisory above: `vendor/` is gitignored and the release zip is built with `composer install --no-dev`, so PHPCS never ships to users. The `composer audit` step in CI was failing on this advisory and is green again.

## [1.3.1] - 2026-07-27

### Security

- **Report/PDF/log storage moved to a protected private directory (unauthenticated disclosure fix)** — Generated reports, client PDFs, and diagnostics logs lived under `wp_upload_dir()/wpmar/` with no `.htaccess`/`index.php` protection outside the logs subdirectory, and filenames built from little more than a domain and timestamp — a few dozen to a few hundred requests could locate and download a report containing administrator email addresses, a full core/theme/plugin version inventory (i.e. a map of unpatched known vulnerabilities), checksum-mismatch findings, and server path/permission details. New artefacts now write to `wp-content/wpmar-private/` by default (`reports/`, `pdf/`, `logs/`, `tmp/` subdirectories), overridable via the `WPMAR_PRIVATE_STORAGE_DIR` constant or `wpmar_private_storage_dir` filter to move storage outside the document root entirely. Every directory gets an auto-generated `.htaccess` (`Require all denied` / `Deny from all`) plus `index.php`, and every filename carries a 20-character random token — the one defense that does not depend on the web server type. Multisite splits the base directory by `site-{blog_id}/`, since `wp-content` (unlike `wp_upload_dir()`) is shared network-wide. When the private directory isn't writable, the plugin falls back to an equally token-protected `wp-content/uploads/wpmar/` and shows an admin notice. Existing v1.3.0 files are migrated automatically in the background after upgrading (`WPMAR_Storage_Migrator`; batched, idempotent, resumable, with per-file rollback if a paired DB update fails); progress shows on the plugin's admin screens, and old files are not deleted until migration completes. `wp wpmar storage migrate [--dry-run] [--network] [--batch=<n>]` drives or previews it manually, and `wp wpmar storage migrate --revert` reverses it (for downgrading back to a pre-1.3.1 release) while keeping the existing filename/token and adding the `.htaccess`/`index.php` protection v1.3.0 never had.
- **PDF rendering: Parsedown safe mode unified across the PDF and HTML-email paths** — `WPMAR_PDF_Writer::write_pdf_from_markdown()` instantiated `\Parsedown` directly, skipping the safe mode already applied to the HTML-email path, so raw HTML (`<script>`, `<annotation>`, a remote `<img src>`) embedded in an attacker-controlled plugin/theme/display-name string reached mPDF unsanitized. Both paths now go through a single `markdown_to_safe_html_fragment()`, which also strips `<img>` tags after conversion (Markdown's own `![]()` syntax still emits one even under safe mode), and the mPDF config now explicitly sets `allow_local_file_access` to `false`.
- **`vendor-pdf.zip` checksum verification enabled by default** — Previously the installer only verified the downloaded/uploaded PDF-library archive's SHA-256 if an operator manually pinned one via the `WPMAR_PDF_VENDOR_ZIP_SHA256` constant or `wpmar_pdf_vendor_zip_sha256` filter; unset by default, a ~30 MB executable PHP library was extracted into the writable plugin directory over TLS with no other integrity check. The release workflow now embeds the digest for that exact release inside the plugin package itself, and the installer checks it automatically — the constant/filter still take precedence for a custom build, and a source checkout (no bundled digest) keeps today's no-op behaviour.
- **`Update URI` header added** — Prevents a same-slug plugin on WordPress.org from silently overriding this plugin's own GitHub-Releases-based updater, per the header WordPress 5.8 introduced for exactly this case.
- **`X-Content-Type-Options: nosniff` on every download response** — Previously only the diagnostics-log download set this header; Markdown, client-Markdown, PDF, and ZIP downloads now all go through one shared `WPMAR_Download_Headers` helper so the header can't be forgotten on a new download endpoint.
- **PDF-installer Ajax handlers: capability checked before nonce** — `handle_ajax()`, `handle_preflight_ajax()`, and `handle_manual_upload_ajax()` checked the nonce before the `install_plugins` capability, the reverse of the order used everywhere else in the plugin since the 1.0.0 hardening; all three now check capability first.
- **`SECURITY.md` added** — Documents the supported-version policy, the private GitHub Security Advisories reporting channel, and response-time targets.
- **CI hardening** — The CI workflow now declares `permissions: contents: read` (previously unset), pins `actions/checkout` and `shivammathur/setup-php` to a commit SHA instead of a floating major-version tag, and runs `composer audit` against dev dependencies in addition to the existing `--no-dev` check.

## [1.3.0] - 2026-07-14

### Added

- **Glob patterns in checksum exclude lists** — `WPMAR_Check_Checksums::build_exclude_set()` / `is_excluded()` now recognize entries containing `*`, `?`, or `[` as `fnmatch()` glob patterns (matched against the lowercased, normalized relative path), in addition to the existing exact-match and directory-prefix (`/`, `/*` suffix) forms. A pattern like `wordfence:*/.htaccess` excludes that repeating filename at any nesting depth in one line, instead of requiring one entry per directory. Falls back to a `preg_match()`-based equivalent (`*` → `.*`, `?` → `.`) on environments without `fnmatch()`.

## [1.2.0] - 2026-07-14

### Added

- **Basic auth (blocked-loopback) support for manual runs** — Sites behind HTTP Basic authentication reject the loopback requests WP-Cron / Action Scheduler depend on, so async audit jobs previously sat in `queued` forever. A new loopback detector (`WPMAR_Loopback_Detector`) probes `admin-ajax.php` the same way core's Site Health does and caches the verdict for 12 hours (per-site transient, with a re-check button). Jobs enqueued while blocked are flagged (`loopback_blocked` column on `{prefix}wpmar_jobs`, applied via the existing dbDelta upgrade), and the job-status REST endpoint (`GET /wpmar/v1/jobs/{id}`) then drains the Action Scheduler queue in-process while the admin page polls — batch size pinned to 1, 15-second budget per poll (filterable via `wpmar_inline_runner_time_limit`), transient mutex against concurrent pollers. Manual report generation therefore completes as long as the admin page stays open; environments with working loopbacks are entirely unaffected. Scheduled monthly generation under Basic auth is explicitly **not** supported — use server cron + `wp wpmar audit run --sync` instead.
- **Loopback-blocked admin warnings** — The plugin's screens (single-site and network admin) show a warning notice with a re-check button when loopback is blocked; the schedule settings sections (single-site and network) gain an inline note that monthly auto-reports cannot run, recommending server cron + WP-CLI; the job polling panel tells the operator to keep the page open while a blocked job progresses and warns when a job stays `queued` for ~2 minutes without progress.

### Documentation

- **README (4 variants): Basic auth section** — New "Sites behind HTTP Basic authentication / Basic 認証環境での利用について" section in README.md / README-ja.md / readme.txt / readme-ja.txt covering what does not work (scheduled generation), what works (manual generation while the page polls), and the recommended server-cron + `wp wpmar audit run --sync` setup with a multisite `--url` example.

## [1.1.1] - 2026-07-09

### Changed

- **Report user-information section rendered as a table** — The 【ユーザー情報】 section listed privileged users as tab-separated lines, which the client PDF (Markdown → Parsedown → mPDF) collapsed into hard-to-read unaligned text. Both the client and operator report bodies now emit the list as a GFM pipe table (ID / ユーザー名 / 表示名 / メールアドレス / 権限 / 登録日), which the existing PDF stylesheet renders as a bordered table. Literal `|` characters in user fields are escaped so free-text display names cannot break the table layout. No data collection or PDF-writer changes.

### Documentation

- **README 4種に診断ログ（動作ログ）の使い方を追記** — 「今すぐ実行」パネルのステップ表示・失敗時のログDLリンクの説明、およびログ行フォーマット・ステップの流れ・「最後の行が停止箇所」という読み方・ログの取得方法（DL手順・保存場所・保持件数20件・25分無応答ジョブの自動失敗化）を README.md / README-ja.md / readme.txt / readme-ja.txt の使い方セクションに追加。コード変更なし。

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
