<?php
/**
 * Per-blog network audit segment rows (`{$wpdb->prefix}wpmar_network_segments`).
 *
 * One row per (run_id, blog_id) tracks a single site's independent async job within a
 * network rollup: queued -> running -> done|failed. Only the rendered Markdown bodies
 * and a handful of display fields are stored - the raw per-site dataset (checksums,
 * wp.org intel) is never persisted here, since the aggregate step only ever reads
 * `client_body`/`admin_body`. Rows for a run are deleted once the aggregate finishes,
 * so this table never grows past "sites currently mid-run".
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD façade for network site-segment state.
 */
class WPMAR_Network_Segments_Repository {

	/**
	 * Recognised lifecycle states (mirrors {@see WPMAR_Jobs_Repository}).
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
		$this->table = $wpdb->prefix . 'wpmar_network_segments';
	}

	/**
	 * Inserts one `queued` row per target blog for a run.
	 *
	 * @param string         $run_id   Parent job id this batch belongs to.
	 * @param array<int,int> $blog_ids Target blog ids.
	 * @return int Number of rows successfully inserted.
	 */
	public function create_queued_batch( $run_id, array $blog_ids ) {
		$run_id = self::sanitize_run_id( $run_id );
		if ( '' === $run_id ) {
			return 0;
		}

		$now     = gmdate( 'Y-m-d H:i:s' );
		$created = 0;

		foreach ( $blog_ids as $blog_id ) {
			$blog_id = absint( $blog_id );
			if ( 0 === $blog_id ) {
				continue;
			}

			$ok = $this->db->insert(
				$this->table,
				array(
					'run_id'           => $run_id,
					'blog_id'          => $blog_id,
					'status'           => self::STATUS_QUEUED,
					'attempts'         => 0,
					'domain_gate_ok'   => 0,
					'site_name'        => '',
					'home_url'         => '',
					'changelog_counts' => 0,
					'client_body'      => '',
					'admin_body'       => '',
					'error'            => '',
					'created_at'       => $now,
					'updated_at'       => $now,
				),
				array( '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( false !== $ok ) {
				++$created;
			}
		}

		return $created;
	}

	/**
	 * Marks one segment as running.
	 *
	 * @param string $run_id  Parent job id.
	 * @param int    $blog_id Target blog id.
	 * @return bool
	 */
	public function mark_running( $run_id, $blog_id ) {
		return $this->update_fields(
			$run_id,
			$blog_id,
			array( 'status' => self::STATUS_RUNNING ),
			array( '%s' )
		);
	}

	/**
	 * Marks one segment done, storing the rendered bodies and display fields.
	 *
	 * Deliberately does not accept (and therefore cannot persist) the raw per-site
	 * `dataset` - only the fields {@see WPMAR_Runner::run_site_segment()} exposes for
	 * rendering/merging are ever written here.
	 *
	 * @param string              $run_id  Parent job id.
	 * @param int                 $blog_id Target blog id.
	 * @param array<string,mixed> $segment Keys: site_name, home_url, domain_gate_ok, changelog_counts, client_body, admin_body.
	 * @return bool
	 */
	public function mark_done( $run_id, $blog_id, array $segment ) {
		return $this->update_fields(
			$run_id,
			$blog_id,
			array(
				'status'           => self::STATUS_DONE,
				'domain_gate_ok'   => empty( $segment['domain_gate_ok'] ) ? 0 : 1,
				'site_name'        => isset( $segment['site_name'] ) ? sanitize_text_field( (string) $segment['site_name'] ) : '',
				'home_url'         => isset( $segment['home_url'] ) ? esc_url_raw( (string) $segment['home_url'] ) : '',
				'changelog_counts' => isset( $segment['changelog_counts'] ) ? absint( $segment['changelog_counts'] ) : 0,
				'client_body'      => isset( $segment['client_body'] ) ? (string) $segment['client_body'] : '',
				'admin_body'       => isset( $segment['admin_body'] ) ? (string) $segment['admin_body'] : '',
				'error'            => '',
			),
			array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Marks one segment failed with a human-readable message.
	 *
	 * @param string $run_id  Parent job id.
	 * @param int    $blog_id Target blog id.
	 * @param string $message Failure detail.
	 * @return bool
	 */
	public function mark_failed( $run_id, $blog_id, $message ) {
		return $this->update_fields(
			$run_id,
			$blog_id,
			array(
				'status' => self::STATUS_FAILED,
				'error'  => (string) $message,
			),
			array( '%s', '%s' )
		);
	}

	/**
	 * Resets a segment to `queued` and bumps `attempts`, ahead of a retry re-schedule.
	 *
	 * @param string $run_id  Parent job id.
	 * @param int    $blog_id Target blog id.
	 * @return bool
	 */
	public function requeue_for_retry( $run_id, $blog_id ) {
		$run_id  = self::sanitize_run_id( $run_id );
		$blog_id = absint( $blog_id );
		if ( '' === $run_id || 0 === $blog_id ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table slug from prefix literal.
		$ok = $this->db->query(
			$this->db->prepare(
				"UPDATE `{$this->table}` SET status=%s, attempts=attempts+1, error=%s, updated_at=%s WHERE run_id=%s AND blog_id=%d",
				self::STATUS_QUEUED,
				'',
				gmdate( 'Y-m-d H:i:s' ),
				$run_id,
				$blog_id
			)
		);

		return false !== $ok;
	}

	/**
	 * Fetches every segment row for a run, ordered by blog_id.
	 *
	 * @param string $run_id Parent job id.
	 * @return array<int,array<string,mixed>>
	 */
	public function find_by_run( $run_id ) {
		$run_id = self::sanitize_run_id( $run_id );
		if ( '' === $run_id ) {
			return array();
		}

		$rows = $this->db->get_results(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table slug from prefix literal.
				"SELECT * FROM `{$this->table}` WHERE run_id=%s ORDER BY blog_id ASC",
				$run_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetches a single segment row, or null when missing.
	 *
	 * @param string $run_id  Parent job id.
	 * @param int    $blog_id Target blog id.
	 * @return array<string,mixed>|null
	 */
	public function find_one( $run_id, $blog_id ) {
		$run_id  = self::sanitize_run_id( $run_id );
		$blog_id = absint( $blog_id );
		if ( '' === $run_id || 0 === $blog_id ) {
			return null;
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table slug from prefix literal.
				"SELECT * FROM `{$this->table}` WHERE run_id=%s AND blog_id=%d LIMIT 1",
				$run_id,
				$blog_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Counts a run's segments per status.
	 *
	 * Always includes all four keys (zero-filled) so callers can branch on `queued`/
	 * `running` presence without an `isset()` check.
	 *
	 * @param string $run_id Parent job id.
	 * @return array{queued:int,running:int,done:int,failed:int}
	 */
	public function counts_by_status( $run_id ) {
		$counts = array(
			self::STATUS_QUEUED  => 0,
			self::STATUS_RUNNING => 0,
			self::STATUS_DONE    => 0,
			self::STATUS_FAILED  => 0,
		);

		$run_id = self::sanitize_run_id( $run_id );
		if ( '' === $run_id ) {
			return $counts;
		}

		$rows = $this->db->get_results(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table slug from prefix literal.
				"SELECT status, COUNT(*) as n FROM `{$this->table}` WHERE run_id=%s GROUP BY status",
				$run_id
			),
			ARRAY_A
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( isset( $row['status'], $counts[ $row['status'] ] ) ) {
					$counts[ $row['status'] ] = absint( $row['n'] );
				}
			}
		}

		return $counts;
	}

	/**
	 * Force-fails a run's `running` segments whose heartbeat has gone stale.
	 *
	 * Same rationale as {@see WPMAR_Jobs_Repository::sweep_stale_running()}: a hard
	 * process kill never reaches a shutdown handler for that one site's job, so the
	 * aggregate step is the backstop that notices a segment stopped updating.
	 *
	 * @param string $run_id  Parent job id to scope the sweep to.
	 * @param int    $minutes Heartbeat age, in minutes, beyond which a running segment is considered abandoned.
	 * @return int Number of rows flipped to `failed`.
	 */
	public function sweep_stale_running( $run_id, $minutes = 20 ) {
		$run_id  = self::sanitize_run_id( $run_id );
		$minutes = absint( $minutes );
		if ( '' === $run_id || 0 === $minutes ) {
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
				"UPDATE `{$this->table}` SET status=%s, error=%s, updated_at=%s WHERE run_id=%s AND status=%s AND updated_at<%s",
				self::STATUS_FAILED,
				__( 'ハートビート途絶 — プロセスが強制終了された可能性があります(OOM/タイムアウト)。ログを参照してください。', 'wp-maintenance-audit-reporter' ),
				gmdate( 'Y-m-d H:i:s' ),
				$run_id,
				self::STATUS_RUNNING,
				$cutoff
			)
		);

		return is_numeric( $updated ) ? (int) $updated : 0;
	}

	/**
	 * Deletes every segment row for a run, once the aggregate step has consumed them.
	 *
	 * @param string $run_id Parent job id.
	 * @return int Number of rows removed.
	 */
	public function delete_by_run( $run_id ) {
		$run_id = self::sanitize_run_id( $run_id );
		if ( '' === $run_id ) {
			return 0;
		}

		$deleted = $this->db->query(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table slug from prefix literal.
				"DELETE FROM `{$this->table}` WHERE run_id=%s",
				$run_id
			)
		);

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/**
	 * Applies a partial column update plus a refreshed `updated_at`, keyed by (run_id, blog_id).
	 *
	 * @param string              $run_id  Parent job id.
	 * @param int                 $blog_id Target blog id.
	 * @param array<string,mixed> $fields  Column => value map (excluding updated_at).
	 * @param array<int,string>   $formats Placeholder formats matching $fields order.
	 * @return bool
	 */
	protected function update_fields( $run_id, $blog_id, array $fields, array $formats ) {
		$run_id  = self::sanitize_run_id( $run_id );
		$blog_id = absint( $blog_id );
		if ( '' === $run_id || 0 === $blog_id ) {
			return false;
		}

		$fields['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$formats[]            = '%s';

		$ok = $this->db->update(
			$this->table,
			$fields,
			array(
				'run_id'  => $run_id,
				'blog_id' => $blog_id,
			),
			$formats,
			array( '%s', '%d' )
		);

		return false !== $ok;
	}

	/**
	 * Normalises a run id to the same `[a-z0-9.]` alphabet {@see WPMAR_Jobs_Repository}
	 * uses for job ids, since a run id is always a parent job id on loan.
	 *
	 * @param string $run_id Raw id.
	 * @return string Sanitised id (possibly empty).
	 */
	public static function sanitize_run_id( $run_id ) {
		$run_id = strtolower( (string) $run_id );
		$run_id = preg_replace( '/[^a-z0-9.]/', '', $run_id );

		return is_string( $run_id ) ? substr( $run_id, 0, 40 ) : '';
	}
}
