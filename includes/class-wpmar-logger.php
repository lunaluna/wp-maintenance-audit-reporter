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

	/** Subdirectory inside `wp_upload_dir()['basedir']`. */
	const UPLOAD_SUBDIR = 'wpmar/logs';

	/** Fixed retention: number of most recent log files kept on disk. */
	const KEEP_LATEST = 20;

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
	 * @return string Uploads-relative path to the log file, or '' on failure.
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

		$token    = wp_generate_password( 16, false, false );
		$filename = sprintf( 'run-%s-%s-%s.log', gmdate( 'Ymd-His' ), $job_id, $token );
		$absolute = $dir . $filename;

		self::$job_id   = $job_id;
		self::$log_file = $absolute;

		self::log( self::LEVEL_INFO, 'job started' );

		if ( ! self::$shutdown_registered ) {
			register_shutdown_function( array( __CLASS__, 'handle_shutdown' ) );
			self::$shutdown_registered = true;
		}

		return self::relative_from_absolute( $absolute );
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

		if ( '' !== self::$job_id ) {
			$repo = new WPMAR_Jobs_Repository();
			$repo->mark_step( self::$job_id, (string) $name );
		}
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
			delete_transient( 'wpmar_run_lock' );
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
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'wpmar_log_upload_base', esc_html( $uploads['error'] ) );
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::UPLOAD_SUBDIR;
		wp_mkdir_p( $dir );

		if ( ! is_dir( $dir ) ) {
			return new WP_Error( 'wpmar_log_mkdir_fail', __( 'Unable to create log directory.', 'wp-maintenance-audit-reporter' ) );
		}

		self::seed_protection_files( trailingslashit( $dir ) );

		return trailingslashit( $dir );
	}

	/**
	 * Writes `.htaccess` (Apache) and an empty `index.php` guard into the logs directory.
	 *
	 * Defense in depth only: the primary protection is the unguessable random token in
	 * every filename, since local/dev environments frequently run nginx where `.htaccess`
	 * has no effect at all.
	 *
	 * @param string $dir Trailing-slashed absolute directory path.
	 * @return void
	 */
	protected static function seed_protection_files( $dir ) {
		$htaccess = $dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- one-time guard file inside our own protected directory.
			file_put_contents( $htaccess, $rules );
		}

		$index = $dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- one-time guard file inside our own protected directory.
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Maps an absolute log path back to an uploads-relative fragment for DB storage.
	 *
	 * @param string $absolute Absolute path under `wp_upload_dir()['basedir']`.
	 * @return string
	 */
	protected static function relative_from_absolute( $absolute ) {
		$uploads  = wp_upload_dir();
		$relative = str_replace( trailingslashit( $uploads['basedir'] ), '', $absolute );

		return is_string( $relative ) ? $relative : '';
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
