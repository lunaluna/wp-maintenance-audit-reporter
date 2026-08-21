<?php
/**
 * Unit tests for WPMAR_Scheduler.
 *
 * Covers the pure `next_timestamp_after()` timestamp arithmetic (month-end
 * clamping, the strictly-after boundary contract, timezone handling
 * including DST, and out-of-range inputs), `effective_schedule_settings()`'s
 * network vs single-site source selection, and a regression test for the
 * `persist_snapshots` bug fixed in WPMAR_Scheduler::handle_event() (its
 * `$args` used to omit the key entirely, which WPMAR_Runner::run() /
 * WPMAR_Network_Runner::run() then defaulted to `false` — silently
 * preventing every WP-Cron monthly audit from ever saving a snapshot).
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;
use DateTimeImmutable;
use DateTimeZone;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

if ( ! defined( 'WPMAR_HOOK_SCHEDULED' ) ) {
	define( 'WPMAR_HOOK_SCHEDULED', 'wpmar_run_audit' );
}

if ( ! defined( 'WPMAR_HOOK_NETWORK_MANUAL_RUN' ) ) {
	define( 'WPMAR_HOOK_NETWORK_MANUAL_RUN', 'wpmar_run_network_audit_manual' );
}

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-network-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-network.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-domain-gate.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-loopback-detector.php';
require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-jobs-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-job-dispatcher.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-network-runner.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-scheduler.php';

/**
 * Covers WPMAR_Scheduler::next_timestamp_after() / effective_schedule_settings() / handle_event().
 */
final class SchedulerNextTimestampTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_wpmar_test_options']         = array();
		$GLOBALS['_wpmar_test_site_options']    = array();
		$GLOBALS['_wpmar_test_transients']      = array();
		$GLOBALS['_wpmar_test_cron']            = array();
		$GLOBALS['_wpmar_test_is_multisite']    = false;
		$GLOBALS['_wpmar_test_current_blog_id'] = 1;
		$GLOBALS['_wpmar_test_as_available']    = false;
		$GLOBALS['_wpmar_test_as_calls']        = array();
		$GLOBALS['wpdb']                        = new \WPMAR_Test_Fake_Wpdb();
		unset( $GLOBALS['_wpmar_test_http_response'] );
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['_wpmar_test_options'],
			$GLOBALS['_wpmar_test_site_options'],
			$GLOBALS['_wpmar_test_transients'],
			$GLOBALS['_wpmar_test_cron'],
			$GLOBALS['_wpmar_test_is_multisite'],
			$GLOBALS['_wpmar_test_current_blog_id'],
			$GLOBALS['_wpmar_test_as_available'],
			$GLOBALS['_wpmar_test_as_calls'],
			$GLOBALS['_wpmar_test_http_response'],
			$GLOBALS['wpdb']
		);
		parent::tearDown();
	}

	/**
	 * @param int    $day    Schedule day-of-month.
	 * @param int    $hour   Schedule hour.
	 * @param int    $minute Schedule minute.
	 * @param string $tz     Timezone slug (or intentionally invalid/empty).
	 * @return array<string,mixed>
	 */
	private function schedule_settings( $day = 25, $hour = 2, $minute = 0, $tz = 'Asia/Tokyo' ) {
		return array(
			'schedule' => array(
				'day'    => $day,
				'hour'   => $hour,
				'minute' => $minute,
				'tz'     => $tz,
			),
		);
	}

	// -------------------------------------------------------------------------
	// next_timestamp_after() — basic behaviour
	// -------------------------------------------------------------------------

	public function test_returns_this_month_when_reference_is_before_the_target_time(): void {
		$tz        = new DateTimeZone( 'Asia/Tokyo' );
		$reference = new DateTimeImmutable( '2025-06-01 00:00:00', $tz );

		$next = \WPMAR_Scheduler::next_timestamp_after( $reference, $this->schedule_settings( 25, 2, 0, 'Asia/Tokyo' ) );

		self::assertSame( '2025-06-25 02:00:00', gmdate( 'Y-m-d H:i:s', $next + 9 * HOUR_IN_SECONDS ) );
	}

	public function test_returns_next_month_when_reference_is_after_the_target_time(): void {
		$tz        = new DateTimeZone( 'Asia/Tokyo' );
		$reference = new DateTimeImmutable( '2025-06-26 00:00:00', $tz );

		$next = \WPMAR_Scheduler::next_timestamp_after( $reference, $this->schedule_settings( 25, 2, 0, 'Asia/Tokyo' ) );

		self::assertSame( '2025-07-25 02:00:00', gmdate( 'Y-m-d H:i:s', $next + 9 * HOUR_IN_SECONDS ) );
	}

	// -------------------------------------------------------------------------
	// Month-end clamping: min( $day, days_in_month )
	// -------------------------------------------------------------------------

	public function test_day_31_clamps_to_28_in_a_non_leap_february(): void {
		$tz        = new DateTimeZone( 'Asia/Tokyo' );
		$reference = new DateTimeImmutable( '2025-02-01 00:00:00', $tz );

		$next = \WPMAR_Scheduler::next_timestamp_after( $reference, $this->schedule_settings( 31, 2, 0, 'Asia/Tokyo' ) );

		self::assertSame( '2025-02-28 02:00:00', gmdate( 'Y-m-d H:i:s', $next + 9 * HOUR_IN_SECONDS ) );
	}

	public function test_day_31_clamps_to_29_in_a_leap_february(): void {
		$tz        = new DateTimeZone( 'Asia/Tokyo' );
		$reference = new DateTimeImmutable( '2024-02-01 00:00:00', $tz );

		$next = \WPMAR_Scheduler::next_timestamp_after( $reference, $this->schedule_settings( 31, 2, 0, 'Asia/Tokyo' ) );

		self::assertSame( '2024-02-29 02:00:00', gmdate( 'Y-m-d H:i:s', $next + 9 * HOUR_IN_SECONDS ) );
	}

	public function test_day_31_clamps_to_30_in_april(): void {
		$tz        = new DateTimeZone( 'Asia/Tokyo' );
		$reference = new DateTimeImmutable( '2025-04-01 00:00:00', $tz );

		$next = \WPMAR_Scheduler::next_timestamp_after( $reference, $this->schedule_settings( 31, 2, 0, 'Asia/Tokyo' ) );

		self::assertSame( '2025-04-30 02:00:00', gmdate( 'Y-m-d H:i:s', $next + 9 * HOUR_IN_SECONDS ) );
	}

	// -------------------------------------------------------------------------
	// Boundary: strictly-after contract
	// -------------------------------------------------------------------------

	public function test_reference_exactly_on_the_candidate_rolls_to_next_month(): void {
		$tz        = new DateTimeZone( 'Asia/Tokyo' );
		$reference = new DateTimeImmutable( '2025-06-25 02:00:00', $tz );

		$next = \WPMAR_Scheduler::next_timestamp_after( $reference, $this->schedule_settings( 25, 2, 0, 'Asia/Tokyo' ) );

		self::assertGreaterThan( $reference->getTimestamp(), $next, 'The candidate must be strictly after the reference, not equal to it.' );
		self::assertSame( '2025-07-25 02:00:00', gmdate( 'Y-m-d H:i:s', $next + 9 * HOUR_IN_SECONDS ) );
	}

	public function test_reference_one_second_before_the_candidate_still_returns_this_month(): void {
		$tz        = new DateTimeZone( 'Asia/Tokyo' );
		$reference = new DateTimeImmutable( '2025-06-25 01:59:59', $tz );

		$next = \WPMAR_Scheduler::next_timestamp_after( $reference, $this->schedule_settings( 25, 2, 0, 'Asia/Tokyo' ) );

		self::assertSame( '2025-06-25 02:00:00', gmdate( 'Y-m-d H:i:s', $next + 9 * HOUR_IN_SECONDS ) );
	}

	// -------------------------------------------------------------------------
	// Timezones
	// -------------------------------------------------------------------------

	public function test_utc_and_asia_tokyo_settings_produce_timestamps_nine_hours_apart(): void {
		// Same wall-clock target (25th, 02:00), interpreted in two different zones.
		$reference_utc   = new DateTimeImmutable( '2025-06-01 00:00:00', new DateTimeZone( 'UTC' ) );
		$reference_tokyo = new DateTimeImmutable( '2025-06-01 00:00:00', new DateTimeZone( 'Asia/Tokyo' ) );

		$next_utc   = \WPMAR_Scheduler::next_timestamp_after( $reference_utc, $this->schedule_settings( 25, 2, 0, 'UTC' ) );
		$next_tokyo = \WPMAR_Scheduler::next_timestamp_after( $reference_tokyo, $this->schedule_settings( 25, 2, 0, 'Asia/Tokyo' ) );

		self::assertSame( 9 * HOUR_IN_SECONDS, $next_utc - $next_tokyo, 'Asia/Tokyo (UTC+9) 02:00 must be 9 hours earlier in absolute time than UTC 02:00 on the same date.' );
	}

	public function test_invalid_timezone_string_falls_back_to_asia_tokyo(): void {
		$tz        = new DateTimeZone( 'Asia/Tokyo' );
		$reference = new DateTimeImmutable( '2025-06-01 00:00:00', $tz );

		$with_invalid_tz = \WPMAR_Scheduler::next_timestamp_after( $reference, $this->schedule_settings( 25, 2, 0, 'Not/AZone' ) );
		$with_tokyo_tz   = \WPMAR_Scheduler::next_timestamp_after( $reference, $this->schedule_settings( 25, 2, 0, 'Asia/Tokyo' ) );

		self::assertSame( $with_tokyo_tz, $with_invalid_tz );
	}

	public function test_missing_timezone_setting_falls_back_to_asia_tokyo(): void {
		$tz        = new DateTimeZone( 'Asia/Tokyo' );
		$reference = new DateTimeImmutable( '2025-06-01 00:00:00', $tz );

		$settings = array(
			'schedule' => array(
				'day'    => 25,
				'hour'   => 2,
				'minute' => 0,
				// 'tz' intentionally absent.
			),
		);

		$without_tz    = \WPMAR_Scheduler::next_timestamp_after( $reference, $settings );
		$with_tokyo_tz = \WPMAR_Scheduler::next_timestamp_after( $reference, $this->schedule_settings( 25, 2, 0, 'Asia/Tokyo' ) );

		self::assertSame( $with_tokyo_tz, $without_tz );
	}

	// -------------------------------------------------------------------------
	// DST transitions (America/New_York: spring-forward in March, fall-back in November)
	// -------------------------------------------------------------------------

	public function test_dst_spring_forward_does_not_throw_and_returns_a_sane_timestamp(): void {
		$tz        = new DateTimeZone( 'America/New_York' );
		$reference = new DateTimeImmutable( '2025-03-01 00:00:00', $tz );

		$next = \WPMAR_Scheduler::next_timestamp_after( $reference, $this->schedule_settings( 25, 2, 0, 'America/New_York' ) );

		self::assertIsInt( $next );
		self::assertGreaterThan( $reference->getTimestamp(), $next );
		self::assertSame( '2025-03-25', gmdate( 'Y-m-d', $next + ( new DateTimeImmutable( "@{$next}" ) )->setTimezone( $tz )->getOffset() ) );
	}

	public function test_dst_fall_back_does_not_throw_and_returns_a_sane_timestamp(): void {
		$tz        = new DateTimeZone( 'America/New_York' );
		$reference = new DateTimeImmutable( '2025-11-01 00:00:00', $tz );

		$next = \WPMAR_Scheduler::next_timestamp_after( $reference, $this->schedule_settings( 25, 2, 0, 'America/New_York' ) );

		self::assertIsInt( $next );
		self::assertGreaterThan( $reference->getTimestamp(), $next );
		self::assertSame( '2025-11-25', gmdate( 'Y-m-d', $next + ( new DateTimeImmutable( "@{$next}" ) )->setTimezone( $tz )->getOffset() ) );
	}

	// -------------------------------------------------------------------------
	// Out-of-range day values never throw
	// -------------------------------------------------------------------------

	public function test_day_zero_does_not_throw(): void {
		$tz        = new DateTimeZone( 'Asia/Tokyo' );
		$reference = new DateTimeImmutable( '2025-06-01 00:00:00', $tz );

		$next = \WPMAR_Scheduler::next_timestamp_after( $reference, $this->schedule_settings( 0, 2, 0, 'Asia/Tokyo' ) );

		self::assertIsInt( $next );
		self::assertGreaterThan( $reference->getTimestamp(), $next );
	}

	public function test_day_99_does_not_throw(): void {
		$tz        = new DateTimeZone( 'Asia/Tokyo' );
		$reference = new DateTimeImmutable( '2025-06-01 00:00:00', $tz );

		$next = \WPMAR_Scheduler::next_timestamp_after( $reference, $this->schedule_settings( 99, 2, 0, 'Asia/Tokyo' ) );

		self::assertIsInt( $next );
		self::assertGreaterThan( $reference->getTimestamp(), $next );
	}

	// -------------------------------------------------------------------------
	// effective_schedule_settings()
	// -------------------------------------------------------------------------

	public function test_effective_schedule_settings_uses_site_settings_when_network_audit_is_off(): void {
		$GLOBALS['_wpmar_test_is_multisite']        = true;
		$GLOBALS['_wpmar_test_site_options']['wpmar_network_settings'] = array( 'network_audit_enabled' => false );
		$GLOBALS['_wpmar_test_options']['wpmar_settings']              = array(
			'schedule' => array(
				'day'    => 10,
				'hour'   => 3,
				'minute' => 15,
				'tz'     => 'UTC',
			),
		);

		$settings = \WPMAR_Scheduler::effective_schedule_settings();

		self::assertSame( 10, $settings['schedule']['day'] );
		self::assertSame( 'UTC', $settings['schedule']['tz'] );
	}

	public function test_effective_schedule_settings_uses_network_rollup_settings_when_enabled(): void {
		$GLOBALS['_wpmar_test_is_multisite']        = true;
		$GLOBALS['_wpmar_test_site_options']['wpmar_network_settings'] = array(
			'network_audit_enabled' => true,
			'schedule'              => array(
				'day'    => 5,
				'hour'   => 4,
				'minute' => 30,
				'tz'     => 'America/New_York',
			),
		);

		$settings = \WPMAR_Scheduler::effective_schedule_settings();

		self::assertSame( 5, $settings['schedule']['day'] );
		self::assertSame( 'America/New_York', $settings['schedule']['tz'] );
	}

	// -------------------------------------------------------------------------
	// Regression: handle_event() must pass persist_snapshots => true through to the
	// queued job's args (see fix commit) so scheduled runs actually keep snapshots.
	// -------------------------------------------------------------------------

	public function test_handle_event_queues_a_single_site_job_with_persist_snapshots_true(): void {
		$GLOBALS['_wpmar_test_as_available'] = true;

		\WPMAR_Scheduler::handle_event();

		$jobs = $GLOBALS['wpdb']->tables['wp_wpmar_jobs'] ?? array();
		self::assertCount( 1, $jobs );

		$row     = array_values( $jobs )[0];
		$decoded = json_decode( (string) $row['args_json'], true );

		self::assertTrue( $decoded['persist_snapshots'] ?? null, 'Scheduled single-site runs must persist snapshots.' );
		self::assertSame( 'cron', $decoded['triggered_by'] );
	}

	public function test_handle_event_queues_a_network_job_with_persist_snapshots_true(): void {
		$GLOBALS['_wpmar_test_as_available']                           = true;
		$GLOBALS['_wpmar_test_is_multisite']                           = true;
		$GLOBALS['_wpmar_test_site_options']['wpmar_network_settings'] = array( 'network_audit_enabled' => true );

		\WPMAR_Scheduler::handle_event();

		$jobs = $GLOBALS['wpdb']->tables['wp_wpmar_jobs'] ?? array();
		self::assertCount( 1, $jobs );

		$row     = array_values( $jobs )[0];
		$decoded = json_decode( (string) $row['args_json'], true );

		self::assertTrue( $decoded['persist_snapshots'] ?? null, 'Scheduled network rollup runs must persist snapshots.' );
		self::assertSame( 'cron_network', $decoded['triggered_by'] );
		self::assertTrue( \WPMAR_Network_Runner::should_persist_snapshots( $decoded ), 'Decoded job args must survive should_persist_snapshots() as true.' );
	}
}
