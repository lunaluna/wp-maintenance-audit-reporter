<?php
/**
 * Persisted audit rows rendered by Runner + CLI (`{$wpdb->prefix}wpmar_reports`).
 *
 * Long columns (`body_md`, `body_client_md`, `summary_json`) stay raw - callers decide escaping at output time.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD façade for finalized report artefacts.
 */
class WPMAR_Report_Repository {

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
		$this->table = $wpdb->prefix . 'wpmar_reports';
	}

	/**
	 * Saves a finalized report blob.
	 *
	 * @param array<string,mixed> $columns Column map keyed by schema field.
	 * @return int|null ID.
	 */
	public function insert( array $columns ) {
		$defaults = array(
			'created_at'     => gmdate( 'Y-m-d H:i:s' ),
			'status'         => 'success',
			'triggered_by'   => 'cron',
			'domain_matched' => 1,
			'mail_sent'      => 0,
			'change_count'   => 0,
			'duration_sec'   => 0,
			'summary_json'   => '{}',
			'body_md'        => '',
			'body_client_md' => '',
			'md_file_path'   => '',
			'pdf_file_path'  => '',
		);

		$row = wp_parse_args( $columns, $defaults );

		// Accept either associative arrays (converted here) or pre-encoded JSON strings.
		if ( is_array( $row['summary_json'] ) ) {
			$summary = wp_json_encode( $row['summary_json'] );
		} elseif ( is_string( $row['summary_json'] ) ) {
			$summary = $row['summary_json'];
		} else {
			$summary = '{}';
		}

		$ok = $this->db->insert(
			$this->table,
			array(
				'created_at'     => sanitize_text_field( $row['created_at'] ),
				'status'         => sanitize_key( $row['status'] ),
				'triggered_by'   => sanitize_key( $row['triggered_by'] ),
				'domain_matched' => absint( $row['domain_matched'] ),
				'mail_sent'      => absint( $row['mail_sent'] ),
				'change_count'   => absint( $row['change_count'] ),
				'duration_sec'   => absint( $row['duration_sec'] ),
				'summary_json'   => $summary,
				'body_md'        => $row['body_md'],
				'body_client_md' => $row['body_client_md'],
				'md_file_path'   => sanitize_text_field( $row['md_file_path'] ),
				'pdf_file_path'  => sanitize_text_field( $row['pdf_file_path'] ),
			),
			array(
				'%s',
				'%s',
				'%s',
				'%d',
				'%d',
				'%d',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);

		if ( false === $ok ) {
			return null;
		}

		// Normalise insert identifier (some drivers return numeric strings).
		return (int) $this->db->insert_id;
	}

	/**
	 * Fetches keyed row associative or null when missing.
	 *
	 * @param int $id Report ID.
	 * @return array<string,mixed>|null
	 */
	public function find( $id ) {
		// Intentionally selects * so CLI `export --format=json` can stream the full row.
		$row = $this->db->get_row(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table slug from prefix literal.
				"SELECT * FROM `{$this->table}` WHERE id=%d LIMIT 1",
				absint( $id )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Persists the uploads-relative PDF path after the artefact is rendered.
	 *
	 * @param int    $id                Report primary key.
	 * @param string $pdf_relative_path Fragment relative to the uploads base directory.
	 * @return bool
	 */
	public function update_pdf_file_path( $id, $pdf_relative_path ) {
		$id = absint( $id );
		if ( $id <= 0 ) {
			return false;
		}

		$path = sanitize_text_field( (string) $pdf_relative_path );

		$ok = $this->db->update(
			$this->table,
			array(
				'pdf_file_path' => $path,
			),
			array(
				'id' => $id,
			),
			array(
				'%s',
			),
			array(
				'%d',
			)
		);

		return false !== $ok;
	}

	/**
	 * Deletes a single report row.
	 *
	 * @param int $id Report PK.
	 * @return bool
	 */
	public function delete_row( $id ) {
		$row = $this->find( $id );
		if ( null === $row ) {
			return false;
		}

		if ( isset( $row['md_file_path'] ) && is_string( $row['md_file_path'] ) ) {
			WPMAR_MD_Writer::delete_if_upload_relative( $row['md_file_path'] );
		}

		if ( isset( $row['pdf_file_path'] ) && is_string( $row['pdf_file_path'] ) ) {
			WPMAR_MD_Writer::delete_if_upload_relative( $row['pdf_file_path'] );
		}

		$deleted = $this->db->delete(
			$this->table,
			array(
				'id' => absint( $id ),
			),
			array(
				'%d',
			)
		);

		return ( false !== $deleted && $deleted > 0 );
	}

	/**
	 * Counts persisted reports.
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
	 * Returns rows ordered by newest PK with pagination metadata.
	 *
	 * @param int $per_page Rows per page (clamped).
	 * @param int $page     One-based page index.
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function list_page( $per_page, $page ) {
		$per_page = max( 1, min( 200, absint( $per_page ) ) );
		$page     = max( 1, absint( $page ) );
		$offset   = ( $page - 1 ) * $per_page;

		$total = $this->count_all();

		$items = $this->db->get_results(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table literal.
				'SELECT id,created_at,status,triggered_by,domain_matched,mail_sent,change_count,duration_sec,md_file_path '
					. "FROM `{$this->table}` ORDER BY id DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => absint( $total ),
		);
	}

	/**
	 * Deletes rows (and Markdown peers) older than the retention window.
	 *
	 * @param int $months Retention length; non-positive values disable purging.
	 * @return int Number of rows removed.
	 */
	public function purge_older_than_months( $months ) {
		$months = absint( $months );
		if ( $months <= 0 ) {
			return 0;
		}

		$cutoff_ts = strtotime( '-' . $months . ' months', time() );
		if ( false === $cutoff_ts ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', (int) $cutoff_ts );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table literal.
		$sql = "SELECT id FROM `{$this->table}` WHERE created_at < %s ORDER BY id ASC";

		$ids = $this->db->get_col( $this->db->prepare( $sql, $cutoff ) );

		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return 0;
		}

		$removed = 0;
		foreach ( $ids as $maybe_id ) {
			$rid = absint( $maybe_id );
			if ( $rid <= 0 ) {
				continue;
			}

			if ( $this->delete_row( $rid ) ) {
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Returns recent chronological rows keyed desc.
	 *
	 * @param int $limit Max rows returned.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_recent( $limit = 50 ) {
		$limit = absint( $limit );
		if ( $limit <= 0 ) {
			return array();
		}

		// Avoid loading multi-kilobyte `body_md` rows when operators only need indexes.
		$rows = $this->db->get_results(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table slug from documented prefix literal.
				'SELECT id,created_at,status,triggered_by,domain_matched,mail_sent,change_count,duration_sec,md_file_path '
					. "FROM `{$this->table}` ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
