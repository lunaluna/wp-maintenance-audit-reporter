<?php
/**
 * Unit tests for WPMAR_Loopback_Detector.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Covers probe verdicts (2xx/4xx/WP_Error), transient caching, and cache flushing.
 */
final class LoopbackDetectorTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
		}

		require_once __DIR__ . '/wp-stubs.php';
		require_once dirname( __DIR__ ) . '/includes/class-wpmar-loopback-detector.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wpmar_test_transients'] = array();
		$GLOBALS['_wpmar_test_http_calls'] = array();
		unset( $GLOBALS['_wpmar_test_http_response'] );
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['_wpmar_test_transients'],
			$GLOBALS['_wpmar_test_http_calls'],
			$GLOBALS['_wpmar_test_http_response']
		);
		parent::tearDown();
	}

	public function test_available_when_probe_returns_200(): void {
		$detector = new \WPMAR_Loopback_Detector();

		self::assertTrue( $detector->run_check() );
	}

	public function test_available_when_probe_returns_400(): void {
		// admin-ajax answering at all (even 400 for an unknown action) proves
		// the request got through the access-control layer.
		$GLOBALS['_wpmar_test_http_response'] = array(
			'response' => array( 'code' => 400 ),
		);

		$detector = new \WPMAR_Loopback_Detector();

		self::assertTrue( $detector->run_check() );
	}

	public function test_blocked_when_probe_returns_401(): void {
		$GLOBALS['_wpmar_test_http_response'] = array(
			'response' => array( 'code' => 401 ),
		);

		$detector = new \WPMAR_Loopback_Detector();

		self::assertFalse( $detector->run_check() );
	}

	public function test_blocked_when_probe_returns_403(): void {
		$GLOBALS['_wpmar_test_http_response'] = array(
			'response' => array( 'code' => 403 ),
		);

		$detector = new \WPMAR_Loopback_Detector();

		self::assertFalse( $detector->run_check() );
	}

	public function test_blocked_when_probe_errors(): void {
		$GLOBALS['_wpmar_test_http_response'] = new \WP_Error( 'http_request_failed', 'timeout' );

		$detector = new \WPMAR_Loopback_Detector();

		self::assertFalse( $detector->run_check() );
	}

	public function test_is_loopback_available_uses_cached_verdict_without_reprobing(): void {
		$GLOBALS['_wpmar_test_http_response'] = array(
			'response' => array( 'code' => 401 ),
		);

		$detector = new \WPMAR_Loopback_Detector();
		$detector->run_check();

		self::assertCount( 1, $GLOBALS['_wpmar_test_http_calls'] );

		// Even though the environment would now answer 200, the cached verdict wins.
		unset( $GLOBALS['_wpmar_test_http_response'] );

		self::assertFalse( $detector->is_loopback_available() );
		self::assertCount( 1, $GLOBALS['_wpmar_test_http_calls'] );
	}

	public function test_is_loopback_available_probes_when_cache_empty(): void {
		$detector = new \WPMAR_Loopback_Detector();

		self::assertTrue( $detector->is_loopback_available() );
		self::assertCount( 1, $GLOBALS['_wpmar_test_http_calls'] );
	}

	public function test_flush_cache_forces_reprobe(): void {
		$detector = new \WPMAR_Loopback_Detector();
		$detector->run_check();
		$detector->flush_cache();

		$GLOBALS['_wpmar_test_http_response'] = array(
			'response' => array( 'code' => 401 ),
		);

		self::assertFalse( $detector->is_loopback_available() );
		self::assertCount( 2, $GLOBALS['_wpmar_test_http_calls'] );
	}

	public function test_run_check_caches_status_code_and_timestamp(): void {
		$GLOBALS['_wpmar_test_http_response'] = array(
			'response' => array( 'code' => 401 ),
		);

		$detector = new \WPMAR_Loopback_Detector();
		$detector->run_check();

		$cached = $GLOBALS['_wpmar_test_transients'][ \WPMAR_Loopback_Detector::TRANSIENT_KEY ];

		self::assertFalse( $cached['available'] );
		self::assertSame( 401, $cached['status_code'] );
		self::assertIsInt( $cached['checked_at'] );
	}
}
