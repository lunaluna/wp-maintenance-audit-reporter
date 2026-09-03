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
	 * Counts all snapshot rows (all types).
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
	 * Thin wrapper around {@see self::latest_row()} kept for backward compatibility
	 * (external code may already call this) - the diff engine only needs the payload,
	 * not the envelope.
	 *
	 * @param string $type Snapshot grouping key.
	 * @return array<string,mixed>|null
	 */
	public function latest( $type ) {
		$row = $this->latest_row( $type );

		return null === $row ? null : $row['payload'];
	}

	/**
	 * Loads the most recent row (id + captured_at + decoded payload) for one type.
	 *
	 * {@see self::latest()} drops id/captured_at because the diff engine only needs the
	 * payload; callers that need to report *when* the comparison basis was captured
	 * (e.g. baseline freshness in the report body) need the envelope too.
	 *
	 * @param string $type Snapshot grouping key.
	 * @return array{id:int,captured_at:string,payload:array<string,mixed>}|null
	 */
	public function latest_row( $type ) {
		// Ordering by both `captured_at` and `id` keeps behaviour deterministic if two rows share timestamps.
		$sql = $this->db->prepare(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from prefix + known suffix.
			"SELECT id, captured_at, snapshot_json FROM {$this->table} WHERE snapshot_type=%s ORDER BY captured_at DESC, id DESC LIMIT 1",
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			sanitize_key( $type )
		);

		$row = $this->db->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) || empty( $row['snapshot_json'] ) ) {
			return null;
		}

		// json_decode failures yield null; caller interprets that as "no usable prior snapshot".
		$decoded = json_decode( $row['snapshot_json'], true );

		if ( ! is_array( $decoded ) ) {
			return null;
		}

		return array(
			'id'          => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'captured_at' => isset( $row['captured_at'] ) ? (string) $row['captured_at'] : '',
			'payload'     => $decoded,
		);
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

	/**
	 * Recent rows for one type, newest first, with id + captured_at retained.
	 *
	 * The latest() method drops id/captured_at because the diff engine only needs the payload;
	 * the preview screen needs to show *when* each generation was captured, so this
	 * returns the envelope too. Ordering matches latest() and prune_keep() exactly -
	 * change one and you must change all three, or the preview will disagree with
	 * the row prune_keep() decided to keep.
	 *
	 * @param string $type  core|themes|plugins|users.
	 * @param int    $limit Maximum rows (0 returns an empty array).
	 * @return array<int,array{id:int,captured_at:string,payload:array<string,mixed>}>
	 */
	public function recent( $type, $limit = 2 ) {
		$limit = absint( $limit );
		if ( 0 === $limit ) {
			return array();
		}

		$sql = $this->db->prepare(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from prefix + known suffix.
			"SELECT id, captured_at, snapshot_json FROM {$this->table} WHERE snapshot_type=%s ORDER BY captured_at DESC, id DESC LIMIT %d",
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			sanitize_key( $type ),
			$limit
		);

		$rows = $this->db->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$result = array();
		foreach ( $rows as $row ) {
			// A row with unparseable JSON still surfaces (with an empty payload) rather than
			// vanishing from the list; a single corrupt row must not hide the others.
			$decoded = isset( $row['snapshot_json'] ) ? json_decode( $row['snapshot_json'], true ) : null;

			$result[] = array(
				'id'          => isset( $row['id'] ) ? (int) $row['id'] : 0,
				'captured_at' => isset( $row['captured_at'] ) ? (string) $row['captured_at'] : '',
				'payload'     => is_array( $decoded ) ? $decoded : array(),
			);
		}

		return $result;
	}

	/**
	 * Distinct snapshot types present in the table, alphabetically.
	 *
	 * Read from the table rather than hard-coding core/themes/plugins/users so rows
	 * written by a future dimension still show up in the preview.
	 *
	 * @return array<int,string>
	 */
	public function types() {
		$sql =
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table literal, no user input.
			"SELECT DISTINCT snapshot_type FROM `{$this->table}`";

		$rows = $this->db->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$types = array();
		foreach ( $rows as $row ) {
			if ( isset( $row['snapshot_type'] ) ) {
				$types[] = (string) $row['snapshot_type'];
			}
		}

		$types = array_values( array_unique( $types ) );
		sort( $types );

		return $types;
	}

	/**
	 * Whether this blog's snapshot table exists.
	 *
	 * Not hypothetical: upgrade_database_if_needed() only runs once a request hits
	 * that blog, so on alpine-dealer.local `wpmar_network_segments` exists on 4 of
	 * 20 sites. A cross-site view must never assume the table is there.
	 *
	 * @return bool
	 */
	public function table_exists() {
		$sql = $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $this->table ) );

		return ! empty( $this->db->get_var( $sql ) );
	}
}
