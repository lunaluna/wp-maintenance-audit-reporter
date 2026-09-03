<?php
/**
 * PHPUnit coverage for multisite settings and markup merge helpers.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Asserts network settings shape and rollup merge helpers.
 *
 * @coversNothing
 */
final class NetworkMultisiteTest extends TestCase {

	/**
	 * Bootstraps plugin classes for offline tests.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
		}

		require_once __DIR__ . '/wp-stubs.php';
		require_once dirname( __DIR__ ) . '/includes/class-wpmar-settings.php';
		require_once dirname( __DIR__ ) . '/includes/class-wpmar-network-settings.php';
		require_once dirname( __DIR__ ) . '/includes/class-wpmar-domain-gate.php';
		require_once dirname( __DIR__ ) . '/includes/class-wpmar-runner.php';
		require_once dirname( __DIR__ ) . '/includes/class-wpmar-network-runner.php';
	}

	/**
	 * Calls the protected WPMAR_Network_Runner::extract_segment_baseline() via reflection.
	 *
	 * @param array<string,mixed> $segment One report_segments entry.
	 * @return array<string,mixed>
	 */
	private function extract_segment_baseline( array $segment ) {
		$method = new \ReflectionMethod( \WPMAR_Network_Runner::class, 'extract_segment_baseline' );
		$method->setAccessible( true );

		return $method->invoke( null, $segment );
	}

	/**
	 * Network defaults expose site filter and path prefix keys.
	 *
	 * @return void
	 */
	public function test_network_settings_defaults_include_site_filters(): void {
		$defaults = \WPMAR_Network_Settings::defaults();

		self::assertFalse( (bool) $defaults['network_audit_enabled'] );
		self::assertArrayHasKey( 'sites', $defaults );
		self::assertSame( 100, $defaults['sites']['max_sites'] );
		self::assertArrayHasKey( 'domain', $defaults );
		self::assertArrayHasKey( 'allowed_path_prefix', $defaults['domain'] );
		self::assertArrayHasKey( 'report', $defaults );
		self::assertSame( 'all', $defaults['report']['scope'] );
		self::assertSame( array(), $defaults['report']['blog_ids'] );
	}

	/**
	 * Site-level gate inherits network host/path when site host is empty.
	 *
	 * @return void
	 */
	public function test_merge_network_gate_settings_falls_back_to_network_host(): void {
		$site    = array(
			'domain' => array(
				'allowed_host'        => '',
				'allowed_path_prefix' => '',
			),
		);
		$network = array(
			'domain' => array(
				'allowed_host'        => 'Example.COM',
				'allowed_path_prefix' => 'client/a',
			),
		);

		$merged = \WPMAR_Domain_Gate::merge_network_gate_settings( $site, $network );

		self::assertSame( 'Example.COM', $merged['domain']['allowed_host'] );
		self::assertSame( 'client/a', $merged['domain']['allowed_path_prefix'] );
	}

	/**
	 * Client merge wraps each site segment with a heading and separator.
	 *
	 * @return void
	 */
	public function test_merge_network_client_markup_wraps_site_sections(): void {
		$merged = \WPMAR_Runner::merge_network_client_markup(
			array(
				array(
					'blog_id'        => 2,
					'site_name'      => 'Child',
					'home_url'       => 'https://example.com/child/',
					'domain_gate_ok' => true,
					'client_body'    => "Hello client\n",
				),
				array(
					'blog_id'        => 3,
					'site_name'      => 'Other',
					'home_url'       => 'https://example.com/other/',
					'domain_gate_ok' => true,
					'client_body'    => "Second site\n",
				),
			)
		);

		self::assertStringContainsString( 'Child', $merged );
		self::assertStringContainsString( 'https://example.com/child/', $merged );
		self::assertStringContainsString( 'Hello client', $merged );
		self::assertStringContainsString( '---', $merged );
	}

	/**
	 * Sync path: finalize_rollup() receives run_site_segment()'s in-memory return
	 * value, where `baseline` is already a native array.
	 *
	 * @return void
	 */
	public function test_extract_segment_baseline_reads_native_array_from_sync_path(): void {
		$baseline = array(
			'core'   => '2026-07-28 00:04:06',
			'themes' => null,
		);

		self::assertSame( $baseline, $this->extract_segment_baseline( array( 'baseline' => $baseline ) ) );
	}

	/**
	 * Async path: finalize_rollup() receives rows read back from
	 * `{$wpdb->prefix}wpmar_network_segments`, where baseline survived as the
	 * `baseline_json` JSON string column (see WPMAR_Network_Segments_Repository).
	 *
	 * @return void
	 */
	public function test_extract_segment_baseline_decodes_baseline_json_from_async_path(): void {
		$decoded = $this->extract_segment_baseline(
			array( 'baseline_json' => wp_json_encode( array( 'core' => '2026-07-28 00:04:06' ) ) )
		);

		self::assertSame( array( 'core' => '2026-07-28 00:04:06' ), $decoded );
	}

	/**
	 * Neither the sync nor the async shape's key is present.
	 *
	 * @return void
	 */
	public function test_extract_segment_baseline_returns_empty_array_when_neither_key_present(): void {
		self::assertSame( array(), $this->extract_segment_baseline( array() ) );
	}

	/**
	 * A malformed baseline_json (corrupt row, truncated column) must not surface as a
	 * fatal - the freshness line just shows "no baseline" instead of crashing the report.
	 *
	 * @return void
	 */
	public function test_extract_segment_baseline_returns_empty_array_for_corrupt_json(): void {
		self::assertSame( array(), $this->extract_segment_baseline( array( 'baseline_json' => '{not valid json' ) ) );
	}

	// -------------------------------------------------------------------------
	// should_persist_snapshots() - WPMAR 1.5.6 tri-state regression (network side
	// of the same fix covered for WPMAR_Runner in RunnerFullRunTest.php)
	// -------------------------------------------------------------------------

	/**
	 * Regression test for WPMAR 1.5.6: a caller that omits persist_snapshots
	 * entirely must still get the cron_network trigger default, the same fix
	 * applied to WPMAR_Runner::should_persist_snapshots().
	 *
	 * @return void
	 */
	public function test_should_persist_snapshots_defaults_to_true_for_cron_network_with_key_omitted(): void {
		self::assertTrue( \WPMAR_Network_Runner::should_persist_snapshots( array( 'triggered_by' => 'cron_network' ) ) );
	}

	/**
	 * @return void
	 */
	public function test_should_persist_snapshots_defaults_to_true_for_cli_network_with_key_omitted(): void {
		self::assertTrue( \WPMAR_Network_Runner::should_persist_snapshots( array( 'triggered_by' => 'cli_network' ) ) );
	}

	/**
	 * @return void
	 */
	public function test_should_persist_snapshots_defaults_to_false_for_manual_network_with_key_omitted(): void {
		self::assertFalse( \WPMAR_Network_Runner::should_persist_snapshots( array( 'triggered_by' => 'manual_network' ) ) );
	}

	/**
	 * An explicit false must still win over the cron_network trigger default.
	 *
	 * @return void
	 */
	public function test_should_persist_snapshots_explicit_false_overrides_cron_network_default(): void {
		self::assertFalse(
			\WPMAR_Network_Runner::should_persist_snapshots(
				array(
					'triggered_by'      => 'cron_network',
					'persist_snapshots' => false,
				)
			)
		);
	}

	/**
	 * An explicit true is honoured regardless of trigger (the manual + checkbox
	 * path).
	 *
	 * @return void
	 */
	public function test_should_persist_snapshots_explicit_true_is_honoured_for_manual_network(): void {
		self::assertTrue(
			\WPMAR_Network_Runner::should_persist_snapshots(
				array(
					'triggered_by'      => 'manual_network',
					'persist_snapshots' => true,
				)
			)
		);
	}
}
