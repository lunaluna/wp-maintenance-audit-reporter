<?php
/**
 * JSON snapshot rows used by {@see WPMAR_Runner} for longitudinal diffs.
 *
 * One logical "bucket" per snapshot_type (core/themes/plugins/users); each save appends a row.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inserts typed JSON blobs and prunes older-than-N rows per type.
 */
class WPMAR_Snapshot_Repository {

	/**
	 * Global database handle (shared across requests).
	 *
	 * @var wpdb
	 */
	protected $db;

	/**
	 * Prefixed physical table name (`{$wpdb->prefix}wpmar_snapshots`).
	 *
	 * @var string
	 */
	protected $table;

	/**
	 * Builds repository.
	 */
	public function __construct() {
		global $wpdb;

		$this->db    = $wpdb;
		$this->table = $wpdb->prefix . 'wpmar_snapshots';
	}

	/**
	 * Persists JSON payload for a snapshot type.
	 *
	 * @param string              $type    core|themes|plugins|users.
	 * @param array<string,mixed> $payload JSON-encodable associative data.
	 * @return int|null Insert id.
	 */
	public function save( $type, array $payload ) {
		// Non-encodable payloads should never reach here; bail early to signal runner issues.
		$encoded = wp_json_encode( $payload );
		if ( false === $encoded ) {
			return null;
		}

		$result = $this->db->insert(
			$this->table,
			array(
				'captured_at'   => gmdate( 'Y-m-d H:i:s' ),
				'snapshot_type' => sanitize_key( $type ),
				'snapshot_json' => $encoded,
			),
			array(
				'%s',
				'%s',
				'%s',
			)
		);

		if ( false === $result ) {
			return null;
		}

		return (int) $this->db->insert_id;
	}

	/**
	 * Loads most recent decoded payload.
	 *
	 * @param string $type Snapshot grouping key.
	 * @return array<string,mixed>|null
	 */
	public function latest( $type ) {
		// Ordering by both `captured_at` and `id` keeps behaviour deterministic if two rows share timestamps.
		$sql = $this->db->prepare(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from prefix + known suffix.
			"SELECT snapshot_json FROM {$this->table} WHERE snapshot_type=%s ORDER BY captured_at DESC, id DESC LIMIT 1",
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			sanitize_key( $type )
		);

		$row = $this->db->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) || empty( $row['snapshot_json'] ) ) {
			return null;
		}

		// json_decode failures yield null; caller interprets that as "no usable prior snapshot".
		$decoded = json_decode( $row['snapshot_json'], true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Deletes snapshots beyond the newest `$keep_latest` entries.
	 *
	 * @param string $type Type group.
	 * @param int    $keep Preserve this many newest snapshots.
	 * @return int Count removed (best-effort).
	 */
	public function prune_keep( $type, $keep = 2 ) {
		$type = sanitize_key( $type );

		// Step 1 - collect the `$keep` newest ids we want to retain (ordered DESC for stability).
		$id_rows = $this->db->get_col(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- interpolated table name validated.
				"SELECT id FROM {$this->table} WHERE snapshot_type=%s ORDER BY captured_at DESC, id DESC LIMIT %d",
				$type,
				absint( $keep )
			)
		);

		if ( ! is_array( $id_rows ) || empty( $id_rows ) ) {
			return 0;
		}

		$id_list      = array_map( 'absint', $id_rows );
		$placeholders = implode( ',', array_fill( 0, count( $id_list ), '%d' ) );
		$params       = array_merge( array( $type ), $id_list );

		// Step 2 - delete sibling rows whose ids were not present in the keep-list.
		$sql = $this->db->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table interpolation with dynamic IN list.
			"DELETE FROM {$this->table} WHERE snapshot_type=%s AND id NOT IN ({$placeholders})",
			$params
		);

		$affected = $this->db->query( $sql );

		return is_numeric( $affected ) ? (int) $affected : 0;
	}
}
