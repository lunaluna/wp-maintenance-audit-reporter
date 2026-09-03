<?php
/**
 * Unit tests for WPMAR_Network_Segments_Repository's baseline/snapshots_persisted columns.
 *
 * These columns were added in WPMAR 1.5.6 so the async network rollup path (Job
 * Dispatcher -> Action Scheduler -> this table -> finalize_rollup()) can report the
 * same comparison-baseline freshness the sync path already gets straight from
 * WPMAR_Runner::run_site_segment()'s in-memory return value.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-logger.php';
require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-network-segments-repository.php';

/**
 * Covers WPMAR_Network_Segments_Repository::create_queued_batch() / mark_done().
 */
final class NetworkSegmentsRepositoryTest extends TestCase {

	/** @var \WPMAR_Test_Fake_Wpdb */
	private $db;

	/** @var \WPMAR_Network_Segments_Repository */
	private $repo;

	protected function setUp(): void {
		parent::setUp();

		$this->db        = new \WPMAR_Test_Fake_Wpdb();
		$GLOBALS['wpdb'] = $this->db;
		$this->repo      = new \WPMAR_Network_Segments_Repository();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_create_queued_batch_defaults_baseline_json_empty_and_snapshots_persisted_zero(): void {
		$this->repo->create_queued_batch( 'run-1', array( 5 ) );

		$row = $this->db->insert_calls[0];

		self::assertSame( '', $row['baseline_json'] );
		self::assertSame( 0, $row['snapshots_persisted'] );
	}

	public function test_mark_done_encodes_baseline_array_as_json(): void {
		$this->repo->mark_done(
			'run-1',
			5,
			array(
				'baseline' => array(
					'core'   => '2026-07-28 00:04:06',
					'themes' => null,
				),
			)
		);

		$data = $this->db->update_calls[0][0];

		self::assertSame(
			array(
				'core'   => '2026-07-28 00:04:06',
				'themes' => null,
			),
			json_decode( $data['baseline_json'], true )
		);
	}

	public function test_mark_done_without_baseline_key_stores_empty_string(): void {
		$this->repo->mark_done( 'run-1', 5, array() );

		$data = $this->db->update_calls[0][0];

		self::assertSame( '', $data['baseline_json'] );
	}

	public function test_mark_done_stores_snapshots_persisted_as_one_when_true(): void {
		$this->repo->mark_done( 'run-1', 5, array( 'snapshots_persisted' => true ) );

		self::assertSame( 1, $this->db->update_calls[0][0]['snapshots_persisted'] );
	}

	public function test_mark_done_stores_snapshots_persisted_as_zero_when_false_or_absent(): void {
		$this->repo->mark_done( 'run-1', 5, array( 'snapshots_persisted' => false ) );
		self::assertSame( 0, $this->db->update_calls[0][0]['snapshots_persisted'] );

		$this->repo->mark_done( 'run-1', 5, array() );
		self::assertSame( 0, $this->db->update_calls[1][0]['snapshots_persisted'] );
	}

	public function test_mark_done_targets_row_by_run_id_and_blog_id(): void {
		// sanitize_run_id() strips anything outside [a-z0-9.], so a hyphen doesn't survive.
		$this->repo->mark_done( 'run.1', 5, array() );

		self::assertSame(
			array(
				'run_id'  => 'run.1',
				'blog_id' => 5,
			),
			$this->db->update_calls[0][1]
		);
	}
}
