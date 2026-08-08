<?php
/**
 * Unbuffered per-job step logging so a stalled audit run reveals its last completed phase.
 *
 * Every line is flushed immediately (no in-memory buffering) because the failure mode this
 * exists for is an abrupt process death (OOM kill, execution timeout) — the log must survive
 * up to the very last line written before the interpreter stopped running.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static façade around a single "current job" log file.
 */
class WPMAR_Logger {

	const LEVEL_DEBUG = 'DEBUG';
	const LEVEL_INFO  = 'INFO';
	const LEVEL_WARN  = 'WARN';
	const LEVEL_ERROR = 'ERROR';

	/** Fixed retention: number of most recent log files kept on disk. */
	const KEEP_LATEST = 20;

	/**
	 * Filename for the persistent per-segment outcome/duration history.
	 *
	 * Unlike `run-*.log` (one file per job, capped at {@see self::KEEP_LATEST} and
	 * meaningless once the job's own DB rows are gone), this one file accumulates across
	 * every network run indefinitely - it is the only place a segment's actual duration
	 * survives, since {@see WPMAR_Network_Segments_Repository::delete_by_run()} deletes
	 * the DB row once its run finishes. Exists so the unmeasured retry/timeout filter
	 * defaults in {@see WPMAR_Job_Dispatcher} (`wpmar_network_segment_stale_minutes`,
	 * `wpmar_network_aggregate_max_wait`, etc.) can eventually be checked against real
	 * observed durations instead of staying permanent guesses. Growth is inherently slow
	 * (one line per site per monthly run, not per request), so no rotation is applied.
	 */
	const SEGMENT_HISTORY_FILE = 'segment-history.log';

	/**
	 * Filename for the persistent single-site run peak-memory/duration history.
	 *
	 * Mirrors {@see self::SEGMENT_HISTORY_FILE} for the single-site
	 * ({@see WPMAR_Runner::run()}) path. The point isn't only this plugin's own footprint -
	 * comparing peak usage against the site's `memory_limit` over time gives a sense of
	 * how much headroom the site has overall (other plugins, theme, traffic), not just
	 * whether this plugin alone is the pressure. Growth is similarly slow (once per
	 * scheduled/manual run, not per request), so no rotation is applied.
	 */
	const RUN_HISTORY_FILE = 'run-history.log';

	/**
	 * Job id for the currently active log context, or '' when none is active.
	 *
	 * @var string
	 */
	protected static $job_id = '';

	/**
	 * Absolute path to the active log file, or '' when none is active.
	 *
	 * @var string
	 */
	protected static $log_file = '';

	/**
	 * Whether the shutdown handler has already been registered for this request.
	 *
	 * @var bool
	 */
	protected static $shutdown_registered = false;

	/**
	 * Starts a logging context for a job (or a job-less run, e.g. direct WP-CLI).
	 *
	 * Creates the log file immediately and registers a shutdown handler that captures
	 * fatal errors — the only way to record anything when the process is killed outright
	 * is to have already written every prior step, which is why writes are unbuffered.
	 *
	 * @param string $job_id Job id from {@see WPMAR_Jobs_Repository}, or an arbitrary
	 *                       label (e.g. `cli-...`) for job-less contexts.
	 * @return string `private:`-prefixed path to the log file (see {@see WPMAR_Private_Storage}), or '' on failure.
	 */
	public static function begin_job( $job_id ) {
		$job_id = self::sanitize_label( $job_id );
		if ( '' === $job_id ) {
			return '';
		}

		$dir = self::logs_dir();
		if ( is_wp_error( $dir ) ) {
			return '';
		}

		$token    = WPMAR_Private_Storage::generate_token();
		$filename = sprintf( 'run-%s-%s-%s.log', gmdate( 'Ymd-His' ), $job_id, $token );
		$absolute = $dir . $filename;

		self::$job_id   = $job_id;
		self::$log_file = $absolute;

		self::log( self::LEVEL_INFO, 'job started' );

		if ( ! self::$shutdown_registered ) {
			register_shutdown_function( array( __CLASS__, 'handle_shutdown' ) );
			self::$shutdown_registered = true;
		}

		return WPMAR_Private_Storage::relative_for_storage( $absolute );
	}

	/**
	 * Records a phase/step boundary. This is the line a stuck job is diagnosed from.
	 *
	 * @param string              $name    Short machine-readable step name, e.g. `gather:checksums`.
	 * @param array<string,mixed> $context Optional structured context (counts, durations, memory).
	 * @return void
	 */
	public static function step( $name, array $context = array() ) {
		self::log( self::LEVEL_INFO, 'step: ' . (string) $name, $context );
		self::maybe_warn_high_memory();

		if ( '' !== self::$job_id ) {
			$repo = new WPMAR_Jobs_Repository();
			$repo->mark_step( self::$job_id, (string) $name );
		}
	}

	/**
	 * Emits one WARN line the first time a step observes memory_get_usage(true) crossing
	 * 80% of `memory_limit`. Checked from every {@see self::step()} call (not just the
	 * handful that already log `mem` in their context) so an OOM is visible from whichever
	 * phase happened to be running, not only the phases that thought to measure it.
	 *
	 * @return void
	 */
	protected static function maybe_warn_high_memory() {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $limit <= 0 ) {
			return; // "-1"/unset means unlimited - no ceiling to warn against.
		}

		$used = memory_get_usage( true );
		if ( $used < $limit * 0.8 ) {
			return;
		}

		self::log(
			self::LEVEL_WARN,
			sprintf( 'memory usage at %d%% of memory_limit', (int) round( ( $used / $limit ) * 100 ) ),
			array(
				'used'  => size_format( $used ),
				'limit' => size_format( $limit ),
			)
		);
	}

	/**
	 * Appends one log line. No-op when no job context is active.
	 *
	 * @param string              $level   One of the LEVEL_* constants.
	 * @param string              $message Human-readable message (no secrets).
	 * @param array<string,mixed> $context Optional structured context, redacted before encoding.
	 * @return void
	 */
	public static function log( $level, $message, array $context = array() ) {
		if ( '' === self::$log_file ) {
			return;
		}

		$line = sprintf(
			'[%s] [%s] [job:%s] %s',
			gmdate( 'c' ),
			(string) $level,
			self::$job_id,
			self::sanitize_message( (string) $message )
		);

		$context = self::redact_context( $context );
		if ( ! empty( $context ) ) {
			$encoded = wp_json_encode( $context );
			if ( is_string( $encoded ) ) {
				$line .= ' ' . $encoded;
			}
		}

		$line .= "\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- unbuffered append under wp_upload_dir with a controlled, per-job filename.
		file_put_contents( self::$log_file, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Appends one line recording a finished network segment's outcome and duration.
	 *
	 * Independent of the per-job log context ({@see self::begin_job()}/{@see self::log()}):
	 * segments run as their own Action Scheduler action/process with no job context of
	 * their own, and this data needs to survive past the run - which a per-job file
	 * (deleted down to {@see self::KEEP_LATEST} copies) is the wrong place for anyway,
	 * since the DB row it would otherwise be reconstructed from is gone once the run's
	 * `wpmar_network_segments` rows are deleted.
	 *
	 * `mem_bytes` is `memory_get_peak_usage(true)` read at the moment this line is written -
	 * for a `done`/Throwable-`failed` segment that's the same PHP process that just ran
	 * the site's own audit, so the peak reflects that segment's real worst-case footprint.
	 * For a segment force-failed by the *aggregate's* own stale-heartbeat sweep, it instead
	 * reflects the aggregate process's own peak, not the original (already-dead) segment
	 * process's peak - a dead process's peak can't be recovered after the fact, so this is
	 * the closest available proxy rather than a claim about that specific run.
	 *
	 * @param string $run_id       Parent job id.
	 * @param int    $blog_id      Target blog id.
	 * @param string $status       `done` or `failed`.
	 * @param int    $duration_sec Wall-clock seconds the segment took (best-effort; 0 if unknown).
	 * @param int    $attempts     Retry count already spent on this segment before this outcome.
	 * @param int    $mem_bytes    `memory_get_peak_usage(true)` at the time of this outcome.
	 * @return void
	 */
	public static function log_segment_outcome( $run_id, $blog_id, $status, $duration_sec, $attempts, $mem_bytes = 0 ) {
		$dir = self::logs_dir();
		if ( is_wp_error( $dir ) ) {
			return;
		}

		$line = sprintf(
			"[%s] run=%s blog=%d status=%s duration_sec=%d attempts=%d mem=%s\n",
			gmdate( 'c' ),
			self::sanitize_label( $run_id ),
			absint( $blog_id ),
			sanitize_key( (string) $status ),
			absint( $duration_sec ),
			absint( $attempts ),
			size_format( absint( $mem_bytes ) )
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- unbuffered append under wp_upload_dir with a controlled, fixed filename.
		file_put_contents( $dir . self::SEGMENT_HISTORY_FILE, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Appends one line recording a finished single-site run's peak memory usage and
	 * duration, alongside the site's own `memory_limit` for direct comparison.
	 *
	 * Uses the active job context ({@see self::$job_id}, set by {@see self::begin_job()})
	 * the same implicit way {@see self::log()} already does - a job-less caller (e.g. the
	 * admin dry-run fallback that never calls `begin_job()`) simply logs an empty job id,
	 * same as that path's per-job log lines already would if it had a log file open.
	 *
	 * @param string $status       `done` or `failed`.
	 * @param int    $duration_sec Wall-clock seconds the run took.
	 * @param int    $peak_bytes   `memory_get_peak_usage(true)` at the time of this outcome.
	 * @return void
	 */
	public static function log_run_outcome( $status, $duration_sec, $peak_bytes ) {
		$dir = self::logs_dir();
		if ( is_wp_error( $dir ) ) {
			return;
		}

		$limit_bytes = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		$limit_label = $limit_bytes > 0 ? size_format( $limit_bytes ) : __( '無制限', 'wp-maintenance-audit-reporter' );

		$line = sprintf(
			"[%s] job=%s status=%s duration_sec=%d peak_mem=%s memory_limit=%s\n",
			gmdate( 'c' ),
			self::$job_id,
			sanitize_key( (string) $status ),
			absint( $duration_sec ),
			size_format( absint( $peak_bytes ) ),
			$limit_label
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- unbuffered append under wp_upload_dir with a controlled, fixed filename.
		file_put_contents( $dir . self::RUN_HISTORY_FILE, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Closes the current logging context. Called from the dispatcher's `finally`.
	 *
	 * @return void
	 */
	public static function end_job() {
		if ( '' !== self::$log_file ) {
			self::log( self::LEVEL_INFO, 'job ended' );
		}

		self::$job_id   = '';
		self::$log_file = '';
	}

	/**
	 * Shutdown handler: captures fatal errors that bypass try/catch entirely.
	 *
	 * If a job is still marked `running` at shutdown, the try/catch in the dispatcher
	 * never got to run its `catch`/`finally` — meaning something killed the process
	 * outright. This records what PHP itself last reported and force-fails the job so
	 * it does not sit as `running` forever.
	 *
	 * @return void
	 */
	public static function handle_shutdown() {
		if ( '' === self::$job_id ) {
			return;
		}

		$error       = error_get_last();
		$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR, E_USER_ERROR );

		if ( is_array( $error ) && in_array( $error['type'], $fatal_types, true ) ) {
			self::log(
				self::LEVEL_ERROR,
				sprintf( 'FATAL: %s @%s:%d', self::sanitize_message( (string) $error['message'] ), (string) $error['file'], (int) $error['line'] )
			);
		}

		$repo = new WPMAR_Jobs_Repository();
		$job  = $repo->find( self::$job_id );
		if ( is_array( $job ) && WPMAR_Jobs_Repository::STATUS_RUNNING === $job['status'] ) {
			$repo->mark_failed( self::$job_id, __( '処理が異常終了しました(致命的エラー、またはプロセスの強制終了)。ログを参照してください。', 'wp-maintenance-audit-reporter' ) );

			// Only the lock matching this job's own scope: releasing the other one here could
			// clobber a legitimately in-progress run of the opposite scope on the same request.
			$scope = isset( $job['scope'] ) ? (string) $job['scope'] : 'single';
			if ( 'network' === $scope ) {
				delete_site_transient( WPMAR_Network_Runner::LOCK_TRANSIENT );
			} else {
				delete_transient( 'wpmar_run_lock' );
			}
		}
	}

	/**
	 * Deletes the oldest log files beyond the fixed retention count.
	 *
	 * @param int $keep Number of most recent files to retain.
	 * @return void
	 */
	public static function purge_keep_latest( $keep = self::KEEP_LATEST ) {
		$dir = self::logs_dir();
		if ( is_wp_error( $dir ) ) {
			return;
		}

		$files = glob( $dir . 'run-*.log' );
		if ( ! is_array( $files ) || count( $files ) <= $keep ) {
			return;
		}

		usort(
			$files,
			static function ( $a, $b ) {
				return filemtime( $b ) <=> filemtime( $a );
			}
		);

		foreach ( array_slice( $files, $keep ) as $stale ) {
			wp_delete_file( $stale );
		}
	}

	/**
	 * Ensures the protected logs directory exists and returns its absolute path.
	 *
	 * @return string|WP_Error Trailing-slashed absolute path, or WP_Error on failure.
	 */
	public static function logs_dir() {
		return WPMAR_Private_Storage::logs_dir();
	}

	/**
	 * Restricts a job id / label to a filesystem- and log-line-safe token.
	 *
	 * @param string $label Raw label.
	 * @return string
	 */
	protected static function sanitize_label( $label ) {
		$label = strtolower( (string) $label );
		$label = preg_replace( '/[^a-z0-9.-]/', '', $label );

		return is_string( $label ) ? substr( $label, 0, 40 ) : '';
	}

	/**
	 * Strips tags/newlines and truncates a free-text message before it is written to disk.
	 *
	 * @param string $message Raw message.
	 * @return string
	 */
	protected static function sanitize_message( $message ) {
		$message = wp_strip_all_tags( $message );
		$message = preg_replace( '/[\r\n]+/', ' ', $message );

		return is_string( $message ) ? mb_substr( $message, 0, 500 ) : '';
	}

	/**
	 * Removes values whose key looks like it could hold a secret before logging context.
	 *
	 * Defense in depth: callers should not pass whole settings/credential arrays to begin
	 * with, but this keeps an accidental include from ever reaching disk.
	 *
	 * @param array<string,mixed> $context Raw context.
	 * @return array<string,mixed>
	 */
	protected static function redact_context( array $context ) {
		$redacted = array();

		foreach ( $context as $key => $value ) {
			if ( is_string( $key ) && preg_match( '/pass|secret|key|token|auth/i', $key ) ) {
				$redacted[ $key ] = '[redacted]';
				continue;
			}

			if ( is_scalar( $value ) || null === $value ) {
				$redacted[ $key ] = $value;
			}
		}

		return $redacted;
	}
}
