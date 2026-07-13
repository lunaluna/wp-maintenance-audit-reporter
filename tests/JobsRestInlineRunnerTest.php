<?php
/**
 * Unit tests for the WPMAR_Jobs_REST inline fallback runner (Basic auth support).
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

// The exposing subclass below needs the production class at declaration time,
// so these loads cannot wait for setUpBeforeClass().
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-jobs-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-loopback-detector.php';
require_once dirname( __DIR__ ) . '/includes/api/class-wpmar-jobs-rest.php';

/**
 * Exposes the protected inline-runner internals for direct assertions.
 */
final class ExposedJobsRest extends \WPMAR_Jobs_REST {

	public static function callJobLoopbackBlocked( array $job ): bool {
		return self::job_loopback_blocked( $job );
	}

	public static function callShouldRunInline( array $job ): bool {
		return self::should_run_inline( $job );
	}

	public static function callMaybeRunQueueInline( array $job ): bool {
		return self::maybe_run_queue_inline( $job );
	}
}

/**
 * Queue runner double: returns a scripted sequence of processed counts.
 */
final class FakeQueueRunner {

	/**
	 * Remaining scripted return values for run().
	 *
	 * @var array<int,int>
	 */
	public $results;

	/**
	 * Contexts passed to run(), in call order.
	 *
	 * @var array<int,string>
	 */
	public $calls = array();

	public function __construct( array $results ) {
		$this->results = $results;
	}

	public function run( $context = '' ): int {
		$this->calls[] = (string) $context;

		if ( empty( $this->results ) ) {
			return 0;
		}

		return (int) array_shift( $this->results );
	}
}

/**
 * Covers the decision matrix (status × loopback flag × AS availability), the
 * transient mutex, queue draining, and filter add/remove symmetry.
 */
final class JobsRestInlineRunnerTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
		}

		require_once __DIR__ . '/wp-stubs.php';
		require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-jobs-repository.php';
		require_once dirname( __DIR__ ) . '/includes/class-wpmar-loopback-detector.php';
		require_once dirname( __DIR__ ) . '/includes/api/class-wpmar-jobs-rest.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wpmar_test_as_available'] = true;
		$GLOBALS['_wpmar_test_transients']   = array();
		$GLOBALS['_wpmar_test_filters']      = array();
		$GLOBALS['_wpmar_test_http_calls']   = array();
		$GLOBALS['_wpmar_test_as_runner']    = new FakeQueueRunner( array( 0 ) );
		unset( $GLOBALS['_wpmar_test_http_response'] );
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['_wpmar_test_as_available'],
			$GLOBALS['_wpmar_test_transients'],
			$GLOBALS['_wpmar_test_filters'],
			$GLOBALS['_wpmar_test_http_calls'],
			$GLOBALS['_wpmar_test_http_response'],
			$GLOBALS['_wpmar_test_as_runner']
		);
		parent::tearDown();
	}

	private static function job( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'               => 'wpmar.test1',
				'status'           => 'queued',
				'scope'            => 'single',
				'loopback_blocked' => '1',
			),
			$overrides
		);
	}

	// -------------------------------------------------------------------------
	// job_loopback_blocked
	// -------------------------------------------------------------------------

	public function test_loopback_blocked_reads_row_flag(): void {
		self::assertTrue( ExposedJobsRest::callJobLoopbackBlocked( self::job( array( 'loopback_blocked' => '1' ) ) ) );
		self::assertFalse( ExposedJobsRest::callJobLoopbackBlocked( self::job( array( 'loopback_blocked' => '0' ) ) ) );

		// Column values never trigger a live probe.
		self::assertCount( 0, $GLOBALS['_wpmar_test_http_calls'] );
	}

	public function test_loopback_blocked_falls_back_to_detector_for_legacy_rows(): void {
		$job = self::job();
		unset( $job['loopback_blocked'] );

		$GLOBALS['_wpmar_test_http_response'] = array(
			'response' => array( 'code' => 401 ),
		);

		self::assertTrue( ExposedJobsRest::callJobLoopbackBlocked( $job ) );
		self::assertCount( 1, $GLOBALS['_wpmar_test_http_calls'] );
	}

	// -------------------------------------------------------------------------
	// should_run_inline
	// -------------------------------------------------------------------------

	public function test_runs_inline_for_queued_blocked_job(): void {
		self::assertTrue( ExposedJobsRest::callShouldRunInline( self::job() ) );
	}

	public function test_runs_inline_for_running_blocked_job(): void {
		self::assertTrue( ExposedJobsRest::callShouldRunInline( self::job( array( 'status' => 'running' ) ) ) );
	}

	public function test_skips_inline_for_terminal_statuses(): void {
		self::assertFalse( ExposedJobsRest::callShouldRunInline( self::job( array( 'status' => 'done' ) ) ) );
		self::assertFalse( ExposedJobsRest::callShouldRunInline( self::job( array( 'status' => 'failed' ) ) ) );
	}

	public function test_skips_inline_when_loopback_works(): void {
		self::assertFalse( ExposedJobsRest::callShouldRunInline( self::job( array( 'loopback_blocked' => '0' ) ) ) );
	}

	public function test_skips_inline_when_action_scheduler_unavailable(): void {
		$GLOBALS['_wpmar_test_as_available'] = false;

		self::assertFalse( ExposedJobsRest::callShouldRunInline( self::job() ) );
	}

	// -------------------------------------------------------------------------
	// maybe_run_queue_inline
	// -------------------------------------------------------------------------

	public function test_drains_queue_until_empty(): void {
		$runner                           = new FakeQueueRunner( array( 1, 1, 1, 0 ) );
		$GLOBALS['_wpmar_test_as_runner'] = $runner;

		self::assertTrue( ExposedJobsRest::callMaybeRunQueueInline( self::job() ) );

		// Kept claiming until a pass processed nothing.
		self::assertCount( 4, $runner->calls );
		self::assertSame( 'WPMAR Inline Fallback', $runner->calls[0] );
	}

	public function test_does_not_touch_queue_when_not_blocked(): void {
		$runner                           = new FakeQueueRunner( array( 1, 0 ) );
		$GLOBALS['_wpmar_test_as_runner'] = $runner;

		self::assertFalse( ExposedJobsRest::callMaybeRunQueueInline( self::job( array( 'loopback_blocked' => '0' ) ) ) );
		self::assertCount( 0, $runner->calls );
	}

	public function test_respects_concurrent_runner_lock(): void {
		$runner                           = new FakeQueueRunner( array( 1, 0 ) );
		$GLOBALS['_wpmar_test_as_runner'] = $runner;

		set_transient( 'wpmar_inline_runner_lock', 1, 30 );

		self::assertFalse( ExposedJobsRest::callMaybeRunQueueInline( self::job() ) );
		self::assertCount( 0, $runner->calls );
	}

	public function test_releases_lock_after_run(): void {
		ExposedJobsRest::callMaybeRunQueueInline( self::job() );

		self::assertFalse( get_transient( 'wpmar_inline_runner_lock' ) );
	}

	public function test_batch_size_filter_added_and_removed_symmetrically(): void {
		ExposedJobsRest::callMaybeRunQueueInline( self::job() );

		$batch_events = array_values(
			array_filter(
				$GLOBALS['_wpmar_test_filters'],
				static function ( array $event ): bool {
					return 'action_scheduler_queue_runner_batch_size' === $event[1];
				}
			)
		);

		self::assertCount( 2, $batch_events );
		self::assertSame( 'add', $batch_events[0][0] );
		self::assertSame( 'remove', $batch_events[1][0] );
	}
}
