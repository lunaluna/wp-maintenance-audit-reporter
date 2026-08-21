<?php
/**
 * Unit tests for WPMAR_Runner's dry-run path (`run(['dry' => true])`).
 *
 * A dry run must return a JSON preview without touching the database, sending
 * mail, or taking the run lock — it is the QA-safe "what would this collect"
 * path exposed via `wp wpmar audit run --dry-run`.
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
require_once dirname( __DIR__ ) . '/includes/class-wpmar-cli-environment.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-logger.php';
require_once dirname( __DIR__ ) . '/includes/api/class-wpmar-wporg-client.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-data-collector.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-runner.php';
require_once __DIR__ . '/fixtures/class-fake-data-collector.php';

/**
 * Substitutes a scripted WPMAR_Test_Fake_Data_Collector for the real one.
 */
final class ExposedDryRunRunner extends \WPMAR_Runner {

	/** @var \WPMAR_Data_Collector */
	private $collector;

	/**
	 * @param \WPMAR_Data_Collector $collector Collector double to hand back from make_data_collector().
	 */
	public function __construct( \WPMAR_Data_Collector $collector ) {
		$this->collector = $collector;
	}

	/**
	 * @return \WPMAR_Data_Collector
	 */
	protected function make_data_collector() {
		return $this->collector;
	}
}

/**
 * Covers WPMAR_Runner::run(['dry' => true]) (delegates to the protected handle_dry_run()).
 */
final class RunnerDryRunTest extends TestCase {

	/** @var array<string,mixed> */
	private $dataset_full;

	/** @var array<string,mixed> */
	private $dataset_minimal;

	protected function setUp(): void {
		parent::setUp();

		$this->dataset_full    = require __DIR__ . '/fixtures/dataset-full.php';
		$this->dataset_minimal = require __DIR__ . '/fixtures/dataset-minimal.php';

		$GLOBALS['_wpmar_test_options']            = array( 'blogname' => 'テスト保守サイト' );
		$GLOBALS['_wpmar_test_transients']         = array();
		$GLOBALS['_wpmar_test_mail_calls']         = array();
		$GLOBALS['_wpmar_test_timezone']           = 'Asia/Tokyo';
		$GLOBALS['wpdb']                           = new \WPMAR_Test_Fake_Wpdb();
		unset( $GLOBALS['_wpmar_test_json_encode_return'], $GLOBALS['_wpmar_test_mail_throw'], $GLOBALS['_wpmar_test_mail_results'] );
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['_wpmar_test_options'],
			$GLOBALS['_wpmar_test_transients'],
			$GLOBALS['_wpmar_test_mail_calls'],
			$GLOBALS['_wpmar_test_timezone'],
			$GLOBALS['wpdb'],
			$GLOBALS['_wpmar_test_json_encode_return'],
			$GLOBALS['_wpmar_test_mail_throw'],
			$GLOBALS['_wpmar_test_mail_results']
		);
		parent::tearDown();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function run_dry( array $dataset ) {
		$runner = new ExposedDryRunRunner( new \WPMAR_Test_Fake_Data_Collector( $dataset ) );

		return $runner->run( array( 'dry' => true ) );
	}

	public function test_returns_both_dry_preview_and_dry_brevity_keys(): void {
		$result = $this->run_dry( $this->dataset_full );

		self::assertArrayHasKey( 'dry_preview', $result );
		self::assertArrayHasKey( 'dry_brevity', $result );
		self::assertSame( $this->dataset_full, $result['dry_preview'] );
		self::assertIsString( $result['dry_brevity'] );
	}

	public function test_dry_brevity_is_valid_json_with_exactly_the_expected_keys(): void {
		$result  = $this->run_dry( $this->dataset_full );
		$decoded = json_decode( $result['dry_brevity'], true );

		self::assertIsArray( $decoded, 'dry_brevity must decode as JSON.' );
		self::assertSame(
			array(
				'site',
				'dry_run_at',
				'dry_run_at_utc',
				'core_version',
				'theme_count',
				'plugins_count',
				'security_warning_count',
				'security_summary',
			),
			array_keys( $decoded )
		);
	}

	public function test_theme_and_plugin_counts_match_fixture_inventory(): void {
		$result  = $this->run_dry( $this->dataset_full );
		$decoded = json_decode( $result['dry_brevity'], true );

		self::assertSame( count( $this->dataset_full['themes']['inventory'] ), $decoded['theme_count'] );
		self::assertSame( count( $this->dataset_full['plugins']['inventory'] ), $decoded['plugins_count'] );
	}

	public function test_minimal_dataset_reports_zero_counts(): void {
		$result  = $this->run_dry( $this->dataset_minimal );
		$decoded = json_decode( $result['dry_brevity'], true );

		self::assertSame( 0, $decoded['theme_count'] );
		self::assertSame( 0, $decoded['plugins_count'] );
	}

	public function test_never_writes_to_the_database(): void {
		$this->run_dry( $this->dataset_full );

		self::assertCount( 0, $GLOBALS['wpdb']->insert_calls );
		self::assertCount( 0, $GLOBALS['wpdb']->update_calls );
	}

	public function test_never_sends_mail(): void {
		$this->run_dry( $this->dataset_full );

		self::assertCount( 0, $GLOBALS['_wpmar_test_mail_calls'] );
	}

	public function test_never_touches_the_run_lock_transient(): void {
		$this->run_dry( $this->dataset_full );

		self::assertFalse( get_transient( 'wpmar_run_lock' ), 'Dry run must return before the mutex transient is ever set.' );
	}

	public function test_falls_back_to_error_marker_when_json_encoding_fails(): void {
		$GLOBALS['_wpmar_test_json_encode_return'] = false;

		$result = $this->run_dry( $this->dataset_full );

		self::assertSame( '{"error":"wpmar_dry_preview_encode_failed"}', $result['dry_brevity'] );
	}

	public function test_falls_back_to_error_marker_when_json_encoding_returns_empty_string(): void {
		$GLOBALS['_wpmar_test_json_encode_return'] = '';

		$result = $this->run_dry( $this->dataset_full );

		self::assertSame( '{"error":"wpmar_dry_preview_encode_failed"}', $result['dry_brevity'] );
	}

	public function test_japanese_text_is_kept_unescaped_for_admin_readability(): void {
		$result = $this->run_dry( $this->dataset_full );

		self::assertStringContainsString( 'テスト保守サイト', $result['dry_brevity'] );
		self::assertStringNotContainsString( '\\u30c6', $result['dry_brevity'] );
	}
}
