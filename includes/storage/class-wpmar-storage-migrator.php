<?php
/**
 * Moves legacy (v1.3.0) report/PDF/log files into {@see WPMAR_Private_Storage}, and back.
 *
 * Runs in small, resumable batches so it survives an interrupted process (killed
 * mid-run, PHP timeout, etc.) — progress is tracked in the `wpmar_storage_migration`
 * option, deliberately kept separate from `wpmar_db_version` (see class docblock on
 * {@see WPMAR_Activator::upgrade_database_if_needed()}): that option is stamped
 * immediately after `dbDelta()` regardless of what else happens during the same
 * upgrade, so piggy-backing file migration on it would let a failed migration look
 * "complete".
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batch-driven migrator between the legacy uploads layout and {@see WPMAR_Private_Storage}.
 */
class WPMAR_Storage_Migrator {

	/** Option name for migration progress. */
	const OPTION = 'wpmar_storage_migration';

	/** Default rows processed per batch. */
	const DEFAULT_BATCH_SIZE = 20;

	/** Async/opportunistic hook name. */
	const ASYNC_HOOK = 'wpmar_migrate_storage';

	/** Max notes retained (most recent). */
	const MAX_NOTES = 20;

	/**
	 * Wires background progression: an Action Scheduler chain when available, and an
	 * opportunistic single-batch advance on admin page loads as a fallback for sites
	 * without Action Scheduler or with loopback requests blocked (e.g. Basic auth).
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( self::ASYNC_HOOK, array( __CLASS__, 'handle_scheduled_batch' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_kickoff_async' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_advance_on_admin_init' ) );
	}

	/**
	 * Enqueues the first Action Scheduler batch once, when migration is pending and
	 * no chain is already running.
	 *
	 * @return void
	 */
	public static function maybe_kickoff_async() {
		if ( ! wpmar_action_scheduler_available() ) {
			return;
		}

		$state = self::get_state();
		if ( 'pending' !== $state['state'] ) {
			return;
		}

		as_enqueue_async_action( self::ASYNC_HOOK, array(), 'wpmar' );
	}

	/**
	 * Action Scheduler handler: runs one batch, then re-enqueues itself until terminal.
	 *
	 * @return void
	 */
	public static function handle_scheduled_batch() {
		$state = self::run_batch( 'migrate', self::DEFAULT_BATCH_SIZE );

		if ( wpmar_action_scheduler_available() && ! in_array( $state['state'], array( 'done', 'failed' ), true ) ) {
			as_enqueue_async_action( self::ASYNC_HOOK, array(), 'wpmar' );
		}
	}

	/**
	 * Advances one batch per admin page load — the fallback for sites without Action
	 * Scheduler or where async/loopback requests don't reach the server at all.
	 *
	 * Never runs while an operator-initiated `--revert` is in progress (a background
	 * tick must not race an explicit, deliberate downgrade operation).
	 *
	 * @return void
	 */
	public static function maybe_advance_on_admin_init() {
		$state = self::get_state();

		if ( 'revert' === $state['direction'] && 'running' === $state['state'] ) {
			return;
		}

		if ( in_array( $state['state'], array( 'done', 'failed', 'reverted' ), true ) ) {
			return;
		}

		self::run_batch( 'migrate', self::DEFAULT_BATCH_SIZE );
	}

	/**
	 * Current migration progress, seeded with defaults on first read.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_state() {
		$state = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $state ) ? $state : array(), self::default_state() );
	}

	/**
	 * The default (fresh/no-progress) state shape.
	 *
	 * @return array<string,mixed>
	 */
	protected static function default_state() {
		return array(
			'state'      => 'pending',
			'direction'  => 'migrate',
			'phase'      => 'reports',
			'cursor'     => '',
			'migrated'   => 0,
			'failed'     => 0,
			'total_rows' => 0,
			'started_at' => '',
			'notes'      => array(),
		);
	}

	/**
	 * Persists progress.
	 *
	 * @param array<string,mixed> $state Progress to persist.
	 * @return void
	 */
	protected static function save_state( array $state ) {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Resets a `failed` migration back to `pending` so it can be retried.
	 *
	 * @return void
	 */
	public static function reset_failed() {
		$state = self::get_state();
		if ( 'failed' === $state['state'] ) {
			$state['state'] = 'pending';
			self::save_state( $state );
		}
	}

	/**
	 * Total row count across both tables, snapshotted once at the start of a run for
	 * the admin-notice progress display (not an "eligible" count — that requires a
	 * per-row file-existence check better suited to {@see self::dry_run_summary()}).
	 *
	 * @return int
	 */
	protected static function count_all_rows() {
		global $wpdb;
		$db = $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table literals, no user input.
		$reports = (int) $db->get_var( "SELECT COUNT(*) FROM `{$db->prefix}wpmar_reports`" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table literals, no user input.
		$jobs = (int) $db->get_var( "SELECT COUNT(*) FROM `{$db->prefix}wpmar_jobs`" );

		return $reports + $jobs;
	}

	/**
	 * Processes one batch (up to $batch_size rows in the current phase).
	 *
	 * Idempotent and resumable: safe to call again after an interrupted run picks up
	 * from the persisted cursor. Switching `$direction` from the last completed run
	 * restarts progress from the top of that (new) direction.
	 *
	 * @param string $direction  'migrate' or 'revert'.
	 * @param int    $batch_size Rows to process in this call.
	 * @return array<string,mixed> Updated state.
	 */
	public static function run_batch( $direction = 'migrate', $batch_size = self::DEFAULT_BATCH_SIZE ) {
		$direction  = 'revert' === $direction ? 'revert' : 'migrate';
		$batch_size = max( 1, (int) $batch_size );
		$state      = self::get_state();

		if ( 'migrate' === $direction && 'done' === $state['state'] ) {
			return $state;
		}
		if ( 'revert' === $direction && 'reverted' === $state['state'] ) {
			return $state;
		}
		if ( 'failed' === $state['state'] ) {
			return $state;
		}

		// A fresh kickoff happens either on a direction switch (e.g. 'migrate' done,
		// then '--revert' requested) or on the very first call ever (state 'pending'
		// with its default direction, which happens to equal the requested one).
		$is_direction_switch = ( $state['direction'] !== $direction );
		$is_fresh_start      = $is_direction_switch || 'pending' === $state['state'];

		if ( $is_direction_switch ) {
			$state              = self::default_state();
			$state['direction'] = $direction;
		}

		if ( $is_fresh_start ) {
			$state['state']      = 'running';
			$state['started_at'] = self::now();
			$state['total_rows'] = self::count_all_rows();
		}

		$base = WPMAR_Private_Storage::base_dir();
		if ( is_wp_error( $base ) ) {
			$state['state'] = 'failed';
			self::add_note( $state, 'private storage base directory unavailable: ' . $base->get_error_message() );
			self::save_state( $state );

			return $state;
		}

		switch ( $state['phase'] ) {
			case 'jobs':
				$state = self::process_jobs_phase( $state, $direction, $batch_size );
				break;
			case 'cleanup':
				$state = self::process_cleanup_phase( $state, $direction );
				break;
			case 'reports':
			default:
				$state = self::process_reports_phase( $state, $direction, $batch_size );
				break;
		}

		self::save_state( $state );

		return $state;
	}

	/**
	 * Runs batches back-to-back (synchronously, in this process) until a terminal
	 * state is reached. Used by the WP-CLI command.
	 *
	 * @param string $direction  'migrate' or 'revert'.
	 * @param int    $batch_size Rows per batch.
	 * @return array<string,mixed> Final state.
	 */
	public static function run_all( $direction = 'migrate', $batch_size = self::DEFAULT_BATCH_SIZE ) {
		$state = self::get_state();

		// Generous but finite: a runaway-loop backstop, not a real limit on row count
		// (each iteration advances by $batch_size rows within the current phase).
		for ( $i = 0; $i < 100000; $i++ ) {
			$state = self::run_batch( $direction, $batch_size );

			$terminal = in_array( $state['state'], array( 'done', 'reverted', 'failed' ), true );
			if ( $terminal ) {
				break;
			}
		}

		return $state;
	}

	/**
	 * Read-only preview: counts eligible rows and previews resulting paths without
	 * moving any file or touching the database.
	 *
	 * @param string $direction 'migrate' or 'revert'.
	 * @return array<string,mixed>
	 */
	public static function dry_run_summary( $direction = 'migrate' ) {
		global $wpdb;
		$db        = $wpdb;
		$direction = 'revert' === $direction ? 'revert' : 'migrate';

		$reports_table = $db->prefix . 'wpmar_reports';
		$jobs_table    = $db->prefix . 'wpmar_jobs';

		$summary = array(
			'direction'        => $direction,
			'reports_total'    => 0,
			'reports_eligible' => 0,
			'jobs_total'       => 0,
			'jobs_eligible'    => 0,
			'samples'          => array(),
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table literal, no user input.
		$reports = $db->get_results( "SELECT id, md_file_path, pdf_file_path FROM `{$reports_table}` ORDER BY id ASC", ARRAY_A );
		foreach ( (array) $reports as $row ) {
			++$summary['reports_total'];

			$md = self::preview_column( $row['md_file_path'], $direction, '' );
			if ( null !== $md ) {
				++$summary['reports_eligible'];
				self::add_sample( $summary['samples'], $md );
			}

			$pdf = self::preview_column( $row['pdf_file_path'], $direction, 'pdf' );
			if ( null !== $pdf ) {
				++$summary['reports_eligible'];
				self::add_sample( $summary['samples'], $pdf );
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table literal, no user input.
		$jobs = $db->get_results( "SELECT id, log_path FROM `{$jobs_table}` ORDER BY id ASC", ARRAY_A );
		foreach ( (array) $jobs as $row ) {
			++$summary['jobs_total'];

			$log = self::preview_column( $row['log_path'], $direction, 'logs' );
			if ( null !== $log ) {
				++$summary['jobs_eligible'];
				self::add_sample( $summary['samples'], $log );
			}
		}

		return $summary;
	}

	/**
	 * Appends a preview sample, capped at 5 entries.
	 *
	 * @param array<int,array<string,string>> $samples Accumulator, capped at 5 entries.
	 * @param array<string,string>            $entry   One old => new pair.
	 * @return void
	 */
	protected static function add_sample( array &$samples, array $entry ) {
		if ( count( $samples ) < 5 ) {
			$samples[] = $entry;
		}
	}

	/**
	 * Read-only preview of what {@see self::migrate_column()}/{@see self::revert_column()}
	 * would do to one column value, without moving anything.
	 *
	 * @param string $stored        Current DB value.
	 * @param string $direction     'migrate' or 'revert'.
	 * @param string $legacy_subdir Legacy uploads/wpmar/ subdirectory ('' for the md root, 'pdf', or 'logs').
	 * @return array{old:string,new:string}|null
	 */
	protected static function preview_column( $stored, $direction, $legacy_subdir ) {
		$stored = is_string( $stored ) ? trim( $stored ) : '';
		$is_new = 0 === strpos( $stored, WPMAR_Private_Storage::PREFIX );

		if ( 'migrate' === $direction ) {
			if ( '' === $stored || $is_new ) {
				return null;
			}
		} elseif ( '' === $stored || ! $is_new ) {
			return null;
		}

		$old_abs = WPMAR_Private_Storage::resolve( $stored );
		if ( '' === $old_abs || ! is_file( $old_abs ) ) {
			return null;
		}

		if ( 'migrate' === $direction ) {
			$ext      = pathinfo( $old_abs, PATHINFO_EXTENSION );
			$basename = pathinfo( $old_abs, PATHINFO_FILENAME );
			$preview  = $basename . '-{token}' . ( '' !== $ext ? '.' . $ext : '' );
		} else {
			$preview = 'wpmar' . ( '' !== $legacy_subdir ? '/' . $legacy_subdir : '' ) . '/' . basename( $old_abs );
		}

		return array(
			'old' => $stored,
			'new' => $preview,
		);
	}

	/**
	 * Migrates one batch of `{$wpdb->prefix}wpmar_reports` rows (`md_file_path` / `pdf_file_path`).
	 *
	 * @param array<string,mixed> $state      Current progress.
	 * @param string              $direction  'migrate' or 'revert'.
	 * @param int                 $batch_size Rows to process.
	 * @return array<string,mixed> Updated progress.
	 */
	protected static function process_reports_phase( array $state, $direction, $batch_size ) {
		global $wpdb;
		$db    = $wpdb;
		$table = $db->prefix . 'wpmar_reports';

		$rows = $db->get_results(
			$db->prepare(
				"SELECT id, md_file_path, pdf_file_path FROM `{$table}` WHERE id > %d ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table literal.
				(int) $state['cursor'],
				$batch_size
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			$state['phase']  = 'jobs';
			$state['cursor'] = '';

			return $state;
		}

		foreach ( $rows as $row ) {
			$id      = (int) $row['id'];
			$updates = array();
			$moves   = array();

			$md = 'migrate' === $direction
				? self::migrate_column( $row['md_file_path'], 'reports_dir' )
				: self::revert_column( $row['md_file_path'], '' );
			if ( null !== $md ) {
				$updates['md_file_path'] = $md['new_stored'];
				$moves['md_file_path']   = $md;
			}

			$pdf = 'migrate' === $direction
				? self::migrate_column( $row['pdf_file_path'], 'pdf_dir' )
				: self::revert_column( $row['pdf_file_path'], 'pdf' );
			if ( null !== $pdf ) {
				$updates['pdf_file_path'] = $pdf['new_stored'];
				$moves['pdf_file_path']   = $pdf;
			}

			if ( ! empty( $updates ) ) {
				$ok = $db->update( $table, $updates, array( 'id' => $id ), null, array( '%d' ) );
				if ( false === $ok ) {
					foreach ( $moves as $move ) {
						self::move_file( $move['new_abs'], $move['old_abs'] );
					}
					++$state['failed'];
					self::add_note( $state, sprintf( 'report #%d: DB update failed, rolled back', $id ) );
				} else {
					$state['migrated'] += count( $updates );
				}
			}

			$state['cursor'] = $id;
		}

		return $state;
	}

	/**
	 * Migrates one batch of `{$wpdb->prefix}wpmar_jobs` rows (`log_path`).
	 *
	 * @param array<string,mixed> $state      Current progress.
	 * @param string              $direction  'migrate' or 'revert'.
	 * @param int                 $batch_size Rows to process.
	 * @return array<string,mixed> Updated progress.
	 */
	protected static function process_jobs_phase( array $state, $direction, $batch_size ) {
		global $wpdb;
		$db    = $wpdb;
		$table = $db->prefix . 'wpmar_jobs';

		$rows = $db->get_results(
			$db->prepare(
				"SELECT id, log_path FROM `{$table}` WHERE id > %s ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table literal.
				(string) $state['cursor'],
				$batch_size
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			$state['phase']  = 'cleanup';
			$state['cursor'] = '';

			return $state;
		}

		foreach ( $rows as $row ) {
			$id = (string) $row['id'];

			$move = 'migrate' === $direction
				? self::migrate_column( $row['log_path'], 'logs_dir' )
				: self::revert_column( $row['log_path'], 'logs' );

			if ( null !== $move ) {
				$ok = $db->update( $table, array( 'log_path' => $move['new_stored'] ), array( 'id' => $id ), array( '%s' ), array( '%s' ) );
				if ( false === $ok ) {
					self::move_file( $move['new_abs'], $move['old_abs'] );
					++$state['failed'];
					self::add_note( $state, sprintf( 'job %s: DB update failed, rolled back', $id ) );
				} else {
					++$state['migrated'];
				}
			}

			$state['cursor'] = $id;
		}

		return $state;
	}

	/**
	 * Final phase: sweeps known legacy file patterns after migrate (no-op after revert,
	 * since revert already removes each source file it moves).
	 *
	 * @param array<string,mixed> $state     Current progress.
	 * @param string              $direction 'migrate' or 'revert'.
	 * @return array<string,mixed> Updated progress.
	 */
	protected static function process_cleanup_phase( array $state, $direction ) {
		if ( 'migrate' === $direction ) {
			self::cleanup_legacy_directories();
		}

		$state['phase'] = 'complete';
		$state['state'] = 'migrate' === $direction ? 'done' : 'reverted';

		return $state;
	}

	/**
	 * Removes known legacy file patterns from `uploads/wpmar/` and rmdir()s directories
	 * left empty. Never removes a directory that still holds anything else — third-party
	 * or manually placed files must not be deleted by mistake.
	 *
	 * `uploads/wpmar/` is also where the write-fallback ({@see WPMAR_Private_Storage})
	 * writes when the configured base directory is not writable, using the *same*
	 * filename patterns as the pre-1.3.1 legacy layout this sweep targets. A file
	 * written there during a fallback episode is otherwise indistinguishable from
	 * real legacy cruft by name alone, so every match is checked against every
	 * current DB row's resolved path first — still-referenced files are skipped.
	 *
	 * @return void
	 */
	protected static function cleanup_legacy_directories() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return;
		}

		$base       = trailingslashit( trailingslashit( $uploads['basedir'] ) . 'wpmar' );
		$referenced = self::referenced_absolute_paths();

		foreach ( array( 'wpmar-report-*.md', 'pdf/*.pdf', 'logs/run-*.log' ) as $pattern ) {
			$matches = glob( $base . $pattern );
			if ( is_array( $matches ) ) {
				foreach ( $matches as $file ) {
					if ( is_file( $file ) && ! isset( $referenced[ wp_normalize_path( $file ) ] ) ) {
						wp_delete_file( $file );
					}
				}
			}
		}

		$tmp_matches = glob( $base . 'tmp/mpdf-*' );
		if ( is_array( $tmp_matches ) ) {
			foreach ( $tmp_matches as $entry ) {
				if ( is_dir( $entry ) ) {
					self::remove_dir_recursive( $entry );
				} elseif ( is_file( $entry ) ) {
					wp_delete_file( $entry );
				}
			}
		}

		foreach ( array( 'tmp', 'logs', 'pdf', '' ) as $rel ) {
			$dir = '' !== $rel ? $base . $rel : untrailingslashit( $base );
			if ( is_dir( $dir ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- only succeeds if truly empty; leftover third-party files are deliberately preserved.
				@rmdir( $dir );
			}
		}
	}

	/**
	 * Absolute paths every current `md_file_path` / `pdf_file_path` / `log_path`
	 * value resolves to right now, used by {@see self::cleanup_legacy_directories()}
	 * to avoid deleting a file that is still a live target of some row — including
	 * one written by the uploads/ write fallback, which is indistinguishable from
	 * real legacy leftovers by filename pattern alone.
	 *
	 * @return array<string,true> Normalized absolute path => true.
	 */
	protected static function referenced_absolute_paths() {
		global $wpdb;
		$db = $wpdb;

		$paths = array();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table literal, no user input.
		$reports = $db->get_results( "SELECT md_file_path, pdf_file_path FROM `{$db->prefix}wpmar_reports`", ARRAY_A );
		foreach ( (array) $reports as $row ) {
			foreach ( array( 'md_file_path', 'pdf_file_path' ) as $column ) {
				$abs = WPMAR_Private_Storage::resolve( $row[ $column ] );
				if ( '' !== $abs ) {
					$paths[ wp_normalize_path( $abs ) ] = true;
				}
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table literal, no user input.
		$jobs = $db->get_results( "SELECT log_path FROM `{$db->prefix}wpmar_jobs`", ARRAY_A );
		foreach ( (array) $jobs as $row ) {
			$abs = WPMAR_Private_Storage::resolve( $row['log_path'] );
			if ( '' !== $abs ) {
				$paths[ wp_normalize_path( $abs ) ] = true;
			}
		}

		return $paths;
	}

	/**
	 * Recursively deletes a directory (used only for stray `tmp/mpdf-*` leftovers).
	 *
	 * @param string $dir Absolute directory path.
	 * @return void
	 */
	protected static function remove_dir_recursive( $dir ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- stray mPDF temp-dir cleanup.
				@rmdir( $item->getRealPath() );
			} else {
				wp_delete_file( $item->getRealPath() );
			}
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- stray mPDF temp-dir cleanup.
		@rmdir( $dir );
	}

	/**
	 * Strips any already-appended random tokens from a filename base.
	 *
	 * {@see self::migrate_column()} always appends exactly one fresh token per call,
	 * but a file can pass through it more than once via the documented, supported
	 * `migrate` -> `--revert` -> `migrate` downgrade/upgrade cycle (revert keeps the
	 * filename, including any token, unchanged). Without this, each extra cycle would
	 * append another 20-character token on top of the last, eventually overflowing the
	 * `varchar(255)` `md_file_path`/`pdf_file_path` columns.
	 *
	 * @param string $basename Filename without extension, as returned by pathinfo().
	 * @return string Basename with any trailing `-{20 alphanumeric chars}` run(s) removed.
	 */
	protected static function strip_accumulated_tokens( $basename ) {
		return (string) preg_replace( '/(?:-[A-Za-z0-9]{20})+$/', '', (string) $basename );
	}

	/**
	 * Moves one legacy file into private storage with a freshly generated token.
	 *
	 * @param string $stored     Current (legacy or already-migrated) DB value.
	 * @param string $dir_method One of the `WPMAR_Private_Storage::*_dir()` method names.
	 * @return array{old_abs:string,new_abs:string,new_stored:string}|null Null when there
	 *         is nothing to migrate (empty, already `private:`-prefixed, or file missing).
	 */
	protected static function migrate_column( $stored, $dir_method ) {
		$stored = is_string( $stored ) ? trim( $stored ) : '';
		if ( '' === $stored || 0 === strpos( $stored, WPMAR_Private_Storage::PREFIX ) ) {
			return null;
		}

		$old_abs = WPMAR_Private_Storage::resolve( $stored );
		if ( '' === $old_abs || ! is_file( $old_abs ) ) {
			return null;
		}

		$dir = WPMAR_Private_Storage::$dir_method();
		if ( is_wp_error( $dir ) ) {
			return null;
		}

		$ext      = pathinfo( $old_abs, PATHINFO_EXTENSION );
		$basename = self::strip_accumulated_tokens( pathinfo( $old_abs, PATHINFO_FILENAME ) );
		$new_abs  = $dir . $basename . '-' . WPMAR_Private_Storage::generate_token() . ( '' !== $ext ? '.' . $ext : '' );

		if ( ! self::move_file( $old_abs, $new_abs ) ) {
			return null;
		}

		return array(
			'old_abs'    => $old_abs,
			'new_abs'    => $new_abs,
			'new_stored' => WPMAR_Private_Storage::relative_for_storage( $new_abs ),
		);
	}

	/**
	 * Moves one already-migrated file back to the legacy `uploads/wpmar/` layout,
	 * keeping its existing filename (token included) unchanged.
	 *
	 * @param string $stored        Current (expected `private:`-prefixed) DB value.
	 * @param string $legacy_subdir Legacy subdirectory ('' for the md root, 'pdf', or 'logs').
	 * @return array{old_abs:string,new_abs:string,new_stored:string}|null Null when there
	 *         is nothing to revert (empty, already legacy-format, or file missing).
	 */
	protected static function revert_column( $stored, $legacy_subdir ) {
		$stored = is_string( $stored ) ? trim( $stored ) : '';
		if ( '' === $stored || 0 !== strpos( $stored, WPMAR_Private_Storage::PREFIX ) ) {
			return null;
		}

		$old_abs = WPMAR_Private_Storage::resolve( $stored );
		if ( '' === $old_abs || ! is_file( $old_abs ) ) {
			return null;
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return null;
		}

		$legacy_dir = trailingslashit( trailingslashit( $uploads['basedir'] ) . 'wpmar' . ( '' !== $legacy_subdir ? '/' . $legacy_subdir : '' ) );
		wp_mkdir_p( $legacy_dir );
		if ( ! is_dir( $legacy_dir ) ) {
			return null;
		}

		// v1.3.0's uploads_base_dir() never seeded these; add them on the way back down
		// so a downgrade doesn't reintroduce the unauthenticated-disclosure issue (S-1).
		WPMAR_Private_Storage::seed_protection_files( $legacy_dir );

		$new_abs = $legacy_dir . basename( $old_abs );

		if ( ! self::move_file( $old_abs, $new_abs ) ) {
			return null;
		}

		$relative = str_replace( trailingslashit( $uploads['basedir'] ), '', $new_abs );

		return array(
			'old_abs'    => $old_abs,
			'new_abs'    => $new_abs,
			'new_stored' => is_string( $relative ) ? $relative : '',
		);
	}

	/**
	 * Moves a file, falling back to copy+verify+delete when rename() fails (e.g. across
	 * filesystems — relevant when `WPMAR_PRIVATE_STORAGE_DIR` points outside the WP install).
	 *
	 * @param string $old_abs Absolute source path.
	 * @param string $new_abs Absolute destination path.
	 * @return bool
	 */
	protected static function move_file( $old_abs, $new_abs ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- cross-directory move within our own managed paths; copy+verify fallback below.
		if ( @rename( $old_abs, $new_abs ) ) {
			return true;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_copy -- rename() failed (e.g. cross-filesystem); size-verified before the source is removed.
		if ( ! @copy( $old_abs, $new_abs ) ) {
			return false;
		}

		if ( filesize( $old_abs ) !== filesize( $new_abs ) ) {
			wp_delete_file( $new_abs );

			return false;
		}

		wp_delete_file( $old_abs );

		return true;
	}

	/**
	 * Appends a note, capped at {@see self::MAX_NOTES} (oldest dropped first).
	 *
	 * @param array<string,mixed> $state   Progress, modified in place.
	 * @param string              $message Note text.
	 * @return void
	 */
	protected static function add_note( array &$state, $message ) {
		$state['notes'][] = self::now() . ' ' . $message;
		if ( count( $state['notes'] ) > self::MAX_NOTES ) {
			$state['notes'] = array_slice( $state['notes'], -1 * self::MAX_NOTES );
		}
	}

	/**
	 * The current time, for `started_at`/note timestamps.
	 *
	 * @return string Current UTC time, MySQL datetime format.
	 */
	protected static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
