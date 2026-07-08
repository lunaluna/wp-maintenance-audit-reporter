<?php
/**
 * Async audit job rows (`{$wpdb->prefix}wpmar_jobs`).
 *
 * Tracks the lifecycle (queued → running → done|failed) of audits dispatched to
 * Action Scheduler, mapping a string job id to its execution arguments and the
 * artefacts produced (report id, Markdown/PDF paths) so the admin polling UI can
 * present a download link once the background run finishes.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD façade for async job state.
 */
class WPMAR_Jobs_Repository {

	/**
	 * Recognised lifecycle states.
	 */
	const STATUS_QUEUED  = 'queued';
	const STATUS_RUNNING = 'running';
	const STATUS_DONE    = 'done';
	const STATUS_FAILED  = 'failed';

	/**
	 * WordPress DB abstraction injected per instance.
	 *
	 * @var wpdb
	 */
	protected $db;

	/**
	 * Fully-qualified table name including `$wpdb->prefix`.
	 *
	 * @var string
	 */
	protected $table;

	/**
	 * Builds repository with merged prefix.
	 */
	public function __construct() {
		global $wpdb;

		$this->db    = $wpdb;
		$this->table = $wpdb->prefix . 'wpmar_jobs';
	}

	/**
	 * Inserts a freshly-queued job.
	 *
	 * @param string              $id    Caller-generated job id (uniqid-style, [a-z0-9.]+).
	 * @param array<string,mixed> $args  Run arguments forwarded to the runner.
	 * @param string              $scope `single` or `network`.
	 * @return bool True on insert.
	 */
	public function create( $id, array $args, $scope = 'single' ) {
		$id = self::sanitize_id( $id );
		if ( '' === $id ) {
			return false;
		}

		$args_json = wp_json_encode( $args );
		if ( ! is_string( $args_json ) ) {
			$args_json = '{}';
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		$ok = $this->db->insert(
			$this->table,
			array(
				'id'          => $id,
				'status'      => self::STATUS_QUEUED,
				'scope'       => self::sanitize_scope( $scope ),
				'args_json'   => $args_json,
				'result_json' => '',
				'error'       => '',
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return false !== $ok;
	}

	/**
	 * Fetches a job row associative or null when missing.
	 *
	 * @param string $id Job id.
	 * @return array<string,mixed>|null
	 */
	public function find( $id ) {
		$id = self::sanitize_id( $id );
		if ( '' === $id ) {
			return null;
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table slug from prefix literal.
				"SELECT * FROM `{$this->table}` WHERE id=%s LIMIT 1",
				$id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Marks a job as running.
	 *
	 * @param string $id Job id.
	 * @return bool
	 */
	public function mark_running( $id ) {
		return $this->update_fields(
			$id,
			array( 'status' => self::STATUS_RUNNING ),
			array( '%s' )
		);
	}

	/**
	 * Marks a job done and stores the result envelope.
	 *
	 * @param string              $id     Job id.
	 * @param array<string,mixed> $result Result payload (report id, file paths, etc.).
	 * @return bool
	 */
	public function mark_done( $id, array $result ) {
		$result_json = wp_json_encode( $result );
		if ( ! is_string( $result_json ) ) {
			$result_json = '{}';
		}

		return $this->update_fields(
			$id,
			array(
				'status'      => self::STATUS_DONE,
				'result_json' => $result_json,
			),
			array( '%s', '%s' )
		);
	}

	/**
	 * Marks a job failed with a human-readable message.
	 *
	 * @param string $id      Job id.
	 * @param string $message Failure detail.
	 * @return bool
	 */
	public function mark_failed( $id, $message ) {
		return $this->update_fields(
			$id,
			array(
				'status' => self::STATUS_FAILED,
				'error'  => (string) $message,
			),
			array( '%s', '%s' )
		);
	}

	/**
	 * Records the current phase name and refreshes the heartbeat (`updated_at`).
	 *
	 * This is the primary diagnostic for a stalled run: the last step written here is
	 * the last thing the process completed before it stopped responding.
	 *
	 * @param string $id   Job id.
	 * @param string $step Short machine-readable step name, e.g. `gather:checksums`.
	 * @return bool
	 */
	public function mark_step( $id, $step ) {
		return $this->update_fields(
			$id,
			array( 'step' => substr( (string) $step, 0, 100 ) ),
			array( '%s' )
		);
	}

	/**
	 * Stores the uploads-relative path to this job's log file.
	 *
	 * @param string $id       Job id.
	 * @param string $relative Uploads-relative log file path.
	 * @return bool
	 */
	public function set_log_path( $id, $relative ) {
		return $this->update_fields(
			$id,
			array( 'log_path' => substr( (string) $relative, 0, 255 ) ),
			array( '%s' )
		);
	}

	/**
	 * Force-fails `running` jobs whose heartbeat has gone stale.
	 *
	 * Covers the case a shutdown handler cannot: a hard kill (SIGKILL, OOM killer)
	 * never runs PHP shutdown functions, so nothing ever flips the job out of
	 * `running`. This sweep is the backstop — called opportunistically whenever
	 * someone is looking at job state (REST poll, reports page load).
	 *
	 * @param int $minutes Heartbeat age, in minutes, beyond which a running job is
	 *                     considered abandoned. Should exceed the runner's own mutex TTL.
	 * @return int Number of rows flipped to `failed`.
	 */
	public function sweep_stale_running( $minutes = 25 ) {
		$minutes = absint( $minutes );
		if ( 0 === $minutes ) {
			return 0;
		}

		$cutoff_ts = strtotime( '-' . $minutes . ' minutes', time() );
		if ( false === $cutoff_ts ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', (int) $cutoff_ts );

		$updated = $this->db->query(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table slug from prefix literal.
				"UPDATE `{$this->table}` SET status=%s, error=%s, updated_at=%s WHERE status=%s AND updated_at<%s",
				self::STATUS_FAILED,
				__( 'ハートビート途絶 — プロセスが強制終了された可能性があります(OOM/タイムアウト)。ログを参照してください。', 'wp-maintenance-audit-reporter' ),
				gmdate( 'Y-m-d H:i:s' ),
				self::STATUS_RUNNING,
				$cutoff
			)
		);

		return is_numeric( $updated ) ? (int) $updated : 0;
	}

	/**
	 * Lists the most recent jobs that have an associated log file.
	 *
	 * Backs the Diagnostics section on the reports screen.
	 *
	 * @param int $limit Maximum rows to return.
	 * @return array<int,array<string,mixed>>
	 */
	public function find_recent_with_log( $limit = 20 ) {
		$limit = max( 1, min( 100, absint( $limit ) ) );

		$rows = $this->db->get_results(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table literal.
				"SELECT id, status, scope, step, log_path, created_at, updated_at FROM `{$this->table}` WHERE log_path IS NOT NULL AND log_path != '' ORDER BY created_at DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Counts persisted jobs.
	 *
	 * @return int
	 */
	public function count_all() {
		$total = $this->db->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table literal.
			"SELECT COUNT(*) FROM `{$this->table}`"
		);

		return absint( $total );
	}

	/**
	 * Deletes job rows older than the given number of days.
	 *
	 * Job rows are lightweight bookkeeping (terminal artefacts live in the reports
	 * table), so a simple time-based sweep keeps the table from growing unbounded.
	 *
	 * @param int $days Retention length; non-positive values disable purging.
	 * @return int Number of rows removed.
	 */
	public function purge_older_than_days( $days ) {
		$days = absint( $days );
		if ( $days <= 0 ) {
			return 0;
		}

		$cutoff_ts = strtotime( '-' . $days . ' days', time() );
		if ( false === $cutoff_ts ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', (int) $cutoff_ts );

		$log_paths = $this->db->get_col(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table literal.
				"SELECT log_path FROM `{$this->table}` WHERE created_at < %s AND log_path IS NOT NULL AND log_path != ''",
				$cutoff
			)
		);

		foreach ( (array) $log_paths as $log_path ) {
			WPMAR_MD_Writer::delete_if_upload_relative( (string) $log_path );
		}

		$deleted = $this->db->query(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table literal.
				"DELETE FROM `{$this->table}` WHERE created_at < %s",
				$cutoff
			)
		);

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/**
	 * Applies a partial column update plus a refreshed `updated_at`.
	 *
	 * @param string              $id      Job id.
	 * @param array<string,mixed> $fields  Column => value map (excluding updated_at).
	 * @param array<int,string>   $formats Placeholder formats matching $fields order.
	 * @return bool
	 */
	protected function update_fields( $id, array $fields, array $formats ) {
		$id = self::sanitize_id( $id );
		if ( '' === $id ) {
			return false;
		}

		$fields['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$formats[]            = '%s';

		$ok = $this->db->update(
			$this->table,
			$fields,
			array( 'id' => $id ),
			$formats,
			array( '%s' )
		);

		return false !== $ok;
	}

	/**
	 * Normalises a job id to the `[a-z0-9.]` alphabet used by the REST route.
	 *
	 * @param string $id Raw id.
	 * @return string Sanitised id (possibly empty).
	 */
	public static function sanitize_id( $id ) {
		$id = strtolower( (string) $id );
		$id = preg_replace( '/[^a-z0-9.]/', '', $id );

		return is_string( $id ) ? substr( $id, 0, 40 ) : '';
	}

	/**
	 * Constrains scope to a known value.
	 *
	 * @param string $scope Raw scope.
	 * @return string `single` or `network`.
	 */
	public static function sanitize_scope( $scope ) {
		$scope = sanitize_key( (string) $scope );

		return 'network' === $scope ? 'network' : 'single';
	}
}
