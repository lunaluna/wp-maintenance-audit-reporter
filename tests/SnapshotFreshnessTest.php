<?php
/**
 * Unit tests for WPMAR_Snapshot_Preview::freshness_rows() / render_freshness().
 *
 * WPMAR 1.5.6 adds this "did the monthly run actually update the baseline" mini
 * block to the existing snapshot preview section (WPMAR 1.5.3). Kept in its own
 * file rather than SnapshotPreviewMarkdownTest.php because it needs a
 * WPMAR_Snapshot_Repository double (freshness_rows() takes the repository, not
 * already-loaded rows like to_markdown() does).
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-snapshot-repository.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wpmar-snapshot-preview.php';

/**
 * The shared \WPMAR_Test_Fake_Wpdb::get_results() ignores snapshot_type (every
 * existing recent()/to_markdown() test only ever seeds one type at a time), but
 * freshness_rows() must be exercised across all four known dimensions at once to
 * mean anything - so this scopes get_results() to snapshot_type, mirroring the
 * get_row() override SnapshotRepositoryTest.php already needed for latest_row().
 */
final class TypeScopedFakeWpdb extends \WPMAR_Test_Fake_Wpdb {

	/**
	 * @param array{0:string,1:array<int,mixed>}|string $prepared Prepared tuple.
	 * @param mixed                                      $output   Output type (ignored).
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( $prepared, $output = null ) {
		list( $sql, $args ) = $this->normalize_prepared( $prepared );

		if ( false === stripos( $sql, 'snapshot_type=%s' ) ) {
			return parent::get_results( $prepared, $output );
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

		$limit = (int) end( $args );

		return array_slice( $matches, 0, $limit );
	}
}

/**
 * Covers WPMAR_Snapshot_Preview::freshness_rows() / render_freshness().
 */
final class SnapshotFreshnessTest extends TestCase {

	/** @var TypeScopedFakeWpdb */
	private $db;

	/** @var \WPMAR_Snapshot_Repository */
	private $repo;

	protected function setUp(): void {
		parent::setUp();

		$this->db        = new TypeScopedFakeWpdb();
		$GLOBALS['wpdb'] = $this->db;
		$this->repo      = new \WPMAR_Snapshot_Repository();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * @param int    $id          Row id.
	 * @param string $type        snapshot_type.
	 * @param string $captured_at 'Y-m-d H:i:s' UTC.
	 * @return void
	 */
	private function seed_row( $id, $type, $captured_at ) {
		$this->db->tables['wp_wpmar_snapshots'][ (string) $id ] = array(
			'id'            => $id,
			'captured_at'   => $captured_at,
			'snapshot_type' => $type,
			'snapshot_json' => wp_json_encode( array() ),
		);
	}

	// -------------------------------------------------------------------------
	// freshness_rows()
	// -------------------------------------------------------------------------

	public function test_freshness_rows_returns_captured_at_per_known_dimension(): void {
		$this->db->var_returns = array( 1 ); // table_exists() => true.
		$this->seed_row( 1, 'core', '2026-07-28 00:04:06' );
		$this->seed_row( 2, 'themes', '2026-07-28 00:04:06' );
		$this->seed_row( 3, 'plugins', '2026-07-28 00:04:06' );
		$this->seed_row( 4, 'users', '2026-07-28 00:04:06' );

		$rows = \WPMAR_Snapshot_Preview::freshness_rows( $this->repo );

		foreach ( array( 'core', 'themes', 'plugins', 'users' ) as $type ) {
			self::assertSame( '2026-07-28 00:04:06', $rows[ $type ] );
		}
	}

	public function test_freshness_rows_reports_the_newest_generation_not_the_oldest(): void {
		$this->db->var_returns = array( 1 );
		$this->seed_row( 1, 'core', '2026-06-01 00:00:00' );
		$this->seed_row( 2, 'core', '2026-07-01 00:00:00' );

		$rows = \WPMAR_Snapshot_Preview::freshness_rows( $this->repo );

		self::assertSame( '2026-07-01 00:00:00', $rows['core'] );
	}

	public function test_freshness_rows_returns_null_for_dimension_with_no_rows(): void {
		$this->db->var_returns = array( 1 );
		$this->seed_row( 1, 'core', '2026-07-28 00:04:06' );
		// themes/plugins/users deliberately left empty.

		$rows = \WPMAR_Snapshot_Preview::freshness_rows( $this->repo );

		self::assertNull( $rows['themes'] );
		self::assertNull( $rows['plugins'] );
		self::assertNull( $rows['users'] );
	}

	public function test_freshness_rows_returns_empty_array_when_table_missing(): void {
		$this->db->var_returns = array( 0 ); // table_exists() => false.

		self::assertSame( array(), \WPMAR_Snapshot_Preview::freshness_rows( $this->repo ) );
	}

	// -------------------------------------------------------------------------
	// render_freshness()
	// -------------------------------------------------------------------------

	/**
	 * @return string Captured HTML output.
	 */
	private function render(): string {
		ob_start();
		\WPMAR_Snapshot_Preview::render_freshness( $this->repo );

		return (string) ob_get_clean();
	}

	public function test_render_freshness_lists_each_dimensions_captured_at(): void {
		$this->db->var_returns = array( 1 );
		$this->seed_row( 1, 'core', '2026-07-28 00:04:06' );

		$html = $this->render();

		self::assertStringContainsString( '2026-07-28 00:04:06 UTC', $html );
	}

	public function test_render_freshness_shows_no_record_for_unsaved_dimension(): void {
		$this->db->var_returns = array( 1 );
		$this->seed_row( 1, 'core', '2026-07-28 00:04:06' );
		// themes/plugins/users left empty.

		$html = $this->render();

		self::assertStringContainsString( '記録なし', $html );
	}

	public function test_render_freshness_warns_when_oldest_baseline_exceeds_threshold(): void {
		$this->db->var_returns = array( 1 );
		$stale_at               = gmdate( 'Y-m-d H:i:s', time() - ( \WPMAR_Snapshot_Preview::STALE_THRESHOLD_DAYS + 1 ) * DAY_IN_SECONDS );
		foreach ( array( 'core', 'themes', 'plugins', 'users' ) as $i => $type ) {
			$this->seed_row( $i + 1, $type, $stale_at );
		}

		$html = $this->render();

		self::assertStringContainsString( '以上前です', $html );
	}

	public function test_render_freshness_does_not_warn_when_baseline_is_fresh(): void {
		$this->db->var_returns = array( 1 );
		$fresh_at               = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		foreach ( array( 'core', 'themes', 'plugins', 'users' ) as $i => $type ) {
			$this->seed_row( $i + 1, $type, $fresh_at );
		}

		$html = $this->render();

		self::assertStringNotContainsString( '以上前です', $html );
	}

	public function test_render_freshness_prints_nothing_when_table_missing(): void {
		$this->db->var_returns = array( 0 );

		self::assertSame( '', $this->render() );
	}
}
