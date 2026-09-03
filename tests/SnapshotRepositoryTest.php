<?php
/**
 * Unit tests for WPMAR_Snapshot_Repository::recent() / types() / table_exists() / latest_row().
 *
 * The prune_keep() method is already exercised elsewhere; this file covers the
 * generation-with-envelope reads the snapshot preview screen (WPMAR 1.5.3) and the
 * baseline-freshness reporting (WPMAR 1.5.6) need.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

// RecordingFakeWpdb below extends \WPMAR_Test_Fake_Wpdb at file scope, so the stub
// class must exist before this file's class declarations are parsed.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-snapshot-repository.php';

/**
 * Records every prepare()d SQL string so tests can assert on query shape (WHERE
 * scope, ORDER BY) without a real database — the shared fake doesn't keep history.
 */
final class RecordingFakeWpdb extends \WPMAR_Test_Fake_Wpdb {

	/** @var array<int,string> */
	public $prepared_queries = array();

	/**
	 * @param string $query   Query with placeholders.
	 * @param mixed  ...$args Bound args.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	public function prepare( $query, ...$args ) {
		$this->prepared_queries[] = $query;
		return parent::prepare( $query, ...$args );
	}

	/**
	 * The inherited get_row() only knows id-keyed lookups; latest_row()'s SELECT scopes
	 * by snapshot_type instead, so resolve that case here (newest captured_at/id first,
	 * matching the ORDER BY latest_row() actually issues) and fall back to the parent
	 * for anything else.
	 *
	 * @param array{0:string,1:array<int,mixed>}|string $prepared Prepared tuple.
	 * @param mixed                                     $output   Output type (ignored).
	 * @return array<string,mixed>|null
	 */
	public function get_row( $prepared, $output = null ) {
		list( $sql, $args ) = $this->normalize_prepared( $prepared );

		if ( false === stripos( $sql, 'snapshot_type=%s' ) ) {
			return parent::get_row( $prepared, $output );
		}

		$table = $this->extract_table_from_sql( $sql );
		$type  = isset( $args[0] ) ? (string) $args[0] : '';
		$rows  = ( '' !== $table && isset( $this->tables[ $table ] ) ) ? $this->tables[ $table ] : array();

		$matches = array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $type ) {
					return isset( $row['snapshot_type'] ) && $type === $row['snapshot_type'];
				}
			)
		);

		usort(
			$matches,
			static function ( $a, $b ) {
				return array( $b['captured_at'] ?? '', $b['id'] ?? 0 ) <=> array( $a['captured_at'] ?? '', $a['id'] ?? 0 );
			}
		);

		return $matches[0] ?? null;
	}
}

/**
 * Covers WPMAR_Snapshot_Repository::recent()/types()/table_exists().
 */
final class SnapshotRepositoryTest extends TestCase {

	/** @var RecordingFakeWpdb */
	private $db;

	/** @var \WPMAR_Snapshot_Repository */
	private $repo;

	protected function setUp(): void {
		parent::setUp();

		$this->db        = new RecordingFakeWpdb();
		$GLOBALS['wpdb'] = $this->db;
		$this->repo      = new \WPMAR_Snapshot_Repository();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Seeds one row directly into the fake table, bypassing save() so captured_at
	 * and id can be controlled precisely (save() always stamps gmdate('now')).
	 *
	 * @param int    $id          Row id.
	 * @param string $type        snapshot_type.
	 * @param string $captured_at 'Y-m-d H:i:s' UTC.
	 * @param string $json        Raw snapshot_json (already encoded, or intentionally malformed).
	 * @return void
	 */
	private function seed_row( $id, $type, $captured_at, $json ) {
		$this->db->tables['wp_wpmar_snapshots'][ (string) $id ] = array(
			'id'            => $id,
			'captured_at'   => $captured_at,
			'snapshot_type' => $type,
			'snapshot_json' => $json,
		);
	}

	// -------------------------------------------------------------------------
	// recent()
	// -------------------------------------------------------------------------

	public function test_recent_returns_newest_generation_first(): void {
		$this->seed_row( 10, 'core', '2026-06-01 00:00:00', wp_json_encode( array( 'version' => '7.0' ) ) );
		$this->seed_row( 20, 'core', '2026-07-01 00:00:00', wp_json_encode( array( 'version' => '7.1' ) ) );

		$rows = $this->repo->recent( 'core', 2 );

		self::assertSame( 20, $rows[0]['id'] );
		self::assertSame( 10, $rows[1]['id'] );
	}

	public function test_recent_does_not_exceed_limit(): void {
		$this->seed_row( 1, 'core', '2026-06-01 00:00:00', wp_json_encode( array() ) );
		$this->seed_row( 2, 'core', '2026-06-02 00:00:00', wp_json_encode( array() ) );
		$this->seed_row( 3, 'core', '2026-06-03 00:00:00', wp_json_encode( array() ) );

		$rows = $this->repo->recent( 'core', 2 );

		self::assertCount( 2, $rows );
	}

	public function test_recent_with_zero_limit_returns_empty_array(): void {
		$this->seed_row( 1, 'core', '2026-06-01 00:00:00', wp_json_encode( array() ) );

		self::assertSame( array(), $this->repo->recent( 'core', 0 ) );
	}

	public function test_recent_row_shape_has_id_captured_at_and_payload(): void {
		$this->seed_row( 5, 'core', '2026-06-01 00:00:00', wp_json_encode( array( 'version' => '7.0' ) ) );

		$rows = $this->repo->recent( 'core', 1 );

		self::assertIsInt( $rows[0]['id'] );
		self::assertIsString( $rows[0]['captured_at'] );
		self::assertIsArray( $rows[0]['payload'] );
		self::assertSame( array( 'version' => '7.0' ), $rows[0]['payload'] );
	}

	public function test_recent_row_with_corrupt_json_yields_empty_payload_without_exception(): void {
		$this->seed_row( 7, 'core', '2026-06-01 00:00:00', '{not valid json' );

		$rows = $this->repo->recent( 'core', 1 );

		self::assertSame( array(), $rows[0]['payload'] );
	}

	public function test_recent_issues_sql_scoped_to_snapshot_type(): void {
		$this->repo->recent( 'core', 2 );

		self::assertStringContainsString( 'snapshot_type=%s', end( $this->db->prepared_queries ) );
	}

	public function test_recent_issues_sql_ordered_same_as_latest_and_prune_keep(): void {
		$this->repo->recent( 'core', 2 );

		// Must stay byte-identical to latest()'s and prune_keep()'s ORDER BY: if this
		// drifts, the preview can show a generation prune_keep() has already decided
		// to delete.
		self::assertStringContainsString( 'ORDER BY captured_at DESC, id DESC', end( $this->db->prepared_queries ) );
	}

	// -------------------------------------------------------------------------
	// latest_row() / latest()
	// -------------------------------------------------------------------------

	public function test_latest_row_returns_newest_row_with_envelope(): void {
		$this->seed_row( 10, 'core', '2026-06-01 00:00:00', wp_json_encode( array( 'version' => '7.0' ) ) );
		$this->seed_row( 20, 'core', '2026-07-01 00:00:00', wp_json_encode( array( 'version' => '7.1' ) ) );

		$row = $this->repo->latest_row( 'core' );

		self::assertSame( 20, $row['id'] );
		self::assertSame( '2026-07-01 00:00:00', $row['captured_at'] );
		self::assertSame( array( 'version' => '7.1' ), $row['payload'] );
	}

	public function test_latest_row_returns_null_when_no_rows(): void {
		self::assertNull( $this->repo->latest_row( 'core' ) );
	}

	public function test_latest_row_returns_null_for_corrupt_json(): void {
		// Unlike recent(), a corrupt row must not surface with an empty payload here -
		// callers treat null as "no usable prior snapshot" (see latest()'s own contract).
		$this->seed_row( 7, 'core', '2026-06-01 00:00:00', '{not valid json' );

		self::assertNull( $this->repo->latest_row( 'core' ) );
	}

	public function test_latest_row_issues_sql_ordered_same_as_recent_and_prune_keep(): void {
		$this->repo->latest_row( 'core' );

		self::assertStringContainsString( 'ORDER BY captured_at DESC, id DESC', end( $this->db->prepared_queries ) );
	}

	public function test_latest_delegates_to_latest_row_and_returns_only_payload(): void {
		$this->seed_row( 1, 'core', '2026-06-01 00:00:00', wp_json_encode( array( 'version' => '7.0' ) ) );

		self::assertSame( array( 'version' => '7.0' ), $this->repo->latest( 'core' ) );
	}

	public function test_latest_returns_null_for_corrupt_json(): void {
		$this->seed_row( 7, 'core', '2026-06-01 00:00:00', '{not valid json' );

		self::assertNull( $this->repo->latest( 'core' ) );
	}

	// -------------------------------------------------------------------------
	// types()
	// -------------------------------------------------------------------------

	public function test_types_dedupes_and_sorts(): void {
		$this->seed_row( 1, 'plugins', '2026-06-01 00:00:00', wp_json_encode( array() ) );
		$this->seed_row( 2, 'core', '2026-06-01 00:00:00', wp_json_encode( array() ) );
		$this->seed_row( 3, 'core', '2026-06-02 00:00:00', wp_json_encode( array() ) );

		self::assertSame( array( 'core', 'plugins' ), $this->repo->types() );
	}

	public function test_types_returns_empty_array_for_empty_table(): void {
		self::assertSame( array(), $this->repo->types() );
	}

	// -------------------------------------------------------------------------
	// table_exists()
	// -------------------------------------------------------------------------

	public function test_table_exists_false_for_zero(): void {
		$this->db->var_returns = array( 0 );

		self::assertFalse( $this->repo->table_exists() );
	}

	public function test_table_exists_false_for_empty_string(): void {
		$this->db->var_returns = array( '' );

		self::assertFalse( $this->repo->table_exists() );
	}

	public function test_table_exists_false_for_null(): void {
		$this->db->var_returns = array( null );

		self::assertFalse( $this->repo->table_exists() );
	}

	public function test_table_exists_true_for_matching_table_name(): void {
		$this->db->var_returns = array( 'wp_wpmar_snapshots' );

		self::assertTrue( $this->repo->table_exists() );
	}
}
