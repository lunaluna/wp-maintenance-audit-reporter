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
