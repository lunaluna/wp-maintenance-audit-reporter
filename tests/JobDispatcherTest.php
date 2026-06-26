<?php
/**
 * Unit tests for WPMAR_Job_Dispatcher orchestration guards.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Covers graceful degradation when Action Scheduler is unavailable, and the
 * run_audit_job() guards (unknown id, non-queued idempotency) that prevent
 * duplicate or orphaned runs. The happy-path execution is verified end-to-end.
 */
final class JobDispatcherTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
		}

		require_once __DIR__ . '/wp-stubs.php';
		require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-jobs-repository.php';
		require_once dirname( __DIR__ ) . '/includes/class-wpmar-job-dispatcher.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wpmar_test_as_available'] = false;
		$GLOBALS['_wpmar_test_as_calls']     = array();
		$GLOBALS['wpdb']                     = new \WPMAR_Test_Fake_Wpdb();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_wpmar_test_as_available'], $GLOBALS['_wpmar_test_as_calls'], $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// enqueue_audit_job — graceful degradation
	// -------------------------------------------------------------------------

	public function test_enqueue_returns_wp_error_when_action_scheduler_unavailable(): void {
		$GLOBALS['_wpmar_test_as_available'] = false;

		$result = \WPMAR_Job_Dispatcher::enqueue_audit_job( array( 'dry' => false ), 'single' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'wpmar_as_unavailable', $result->get_error_code() );
	}

	public function test_enqueue_does_not_touch_the_queue_when_unavailable(): void {
		$GLOBALS['_wpmar_test_as_available'] = false;

		\WPMAR_Job_Dispatcher::enqueue_audit_job( array( 'dry' => false ), 'single' );

		self::assertCount( 0, $GLOBALS['_wpmar_test_as_calls'] );
	}

	public function test_enqueue_creates_row_and_queues_when_available(): void {
		$GLOBALS['_wpmar_test_as_available'] = true;

		$result = \WPMAR_Job_Dispatcher::enqueue_audit_job( array( 'dry' => false ), 'network' );

		self::assertIsString( $result );
		self::assertMatchesRegularExpression( '/^[a-z0-9.]+$/', $result );

		// A queued row was inserted ...
		self::assertCount( 1, $GLOBALS['wpdb']->insert_calls );
		self::assertSame( 'queued', $GLOBALS['wpdb']->insert_calls[0]['status'] );
		self::assertSame( 'network', $GLOBALS['wpdb']->insert_calls[0]['scope'] );

		// ... and exactly one async action was enqueued with the job id.
		self::assertCount( 1, $GLOBALS['_wpmar_test_as_calls'] );
		list( $hook, $args ) = $GLOBALS['_wpmar_test_as_calls'][0];
		self::assertSame( \WPMAR_Job_Dispatcher::HOOK, $hook );
		self::assertSame( array( $result ), $args );
	}

	// -------------------------------------------------------------------------
	// run_audit_job — guards
	// -------------------------------------------------------------------------

	public function test_run_audit_job_noops_on_unknown_job(): void {
		// No rows configured → find() returns null.
		\WPMAR_Job_Dispatcher::run_audit_job( 'wpmar.unknown' );

		self::assertCount( 0, $GLOBALS['wpdb']->update_calls );
	}

	public function test_run_audit_job_is_idempotent_for_non_queued_status(): void {
		$GLOBALS['wpdb']->rows['wpmar.done1'] = array(
			'id'        => 'wpmar.done1',
			'status'    => 'done',
			'scope'     => 'single',
			'args_json' => '{"dry":false}',
		);

		\WPMAR_Job_Dispatcher::run_audit_job( 'wpmar.done1' );

		// A done job must not be re-run, so mark_running (an update) never fires.
		self::assertCount( 0, $GLOBALS['wpdb']->update_calls );
	}
}
