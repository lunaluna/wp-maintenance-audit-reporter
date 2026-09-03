<?php
/**
 * Unit tests for WPMAR_Runner's real (non-dry) run path.
 *
 * Exercises the full orchestration in run(): domain gate, snapshot diffing,
 * body rendering, mail dispatch ordering, report persistence, the run-lock
 * mutex (including exception safety), and the post-run reschedule/timestamp
 * bookkeeping. Markdown/PDF file writing is intentionally kept out of scope
 * here (output.md_enabled / output.pdf_enabled stay false in every test) —
 * that belongs to the dedicated rendering/PDF test steps; this file only
 * verifies what gets written to `wpmar_reports` and what side effects fire.
 *
 * @package WPMAR\Tests
 *
 * @runTestsInSeparateProcesses
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

if ( ! defined( 'WPMAR_HOOK_SCHEDULED' ) ) {
	define( 'WPMAR_HOOK_SCHEDULED', 'wpmar_run_audit' );
}

// WPMAR_Logger::purge_keep_latest() (called unconditionally at the end of every
// real run) resolves a directory through WPMAR_Private_Storage off this constant.
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-runner-fullrun-test-content' );
}

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-cli-environment.php';
require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-private-storage.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-logger.php';
require_once dirname( __DIR__ ) . '/includes/api/class-wpmar-wporg-client.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-data-collector.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-domain-gate.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-network-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-network.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-scheduler.php';
require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-snapshot-repository.php';
require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-report-repository.php';
require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-pdf-writer.php';
require_once dirname( __DIR__ ) . '/includes/notify/class-wpmar-notifier-mail.php';
require_once dirname( __DIR__ ) . '/includes/notify/class-wpmar-notification-dispatcher.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-runner.php';
require_once __DIR__ . '/fixtures/class-fake-data-collector.php';

/**
 * Substitutes a scripted WPMAR_Test_Fake_Data_Collector for the real one.
 */
final class ExposedFullRunRunner extends \WPMAR_Runner {

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
 * Covers WPMAR_Runner::run() (real, non-dry path).
 */
final class RunnerFullRunTest extends TestCase {

	/** @var array<string,mixed> */
	private $dataset_full;

	/** @var string */
	private $uploads_base;

	protected function setUp(): void {
		parent::setUp();

		$this->dataset_full = require __DIR__ . '/fixtures/dataset-full.php';

		$this->uploads_base = rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-runner-fullrun-uploads-' . uniqid( '', true );
		mkdir( $this->uploads_base, 0777, true );

		$GLOBALS['_wpmar_test_options']           = array( 'wpmar_settings' => $this->base_settings() );
		$GLOBALS['_wpmar_test_transients']        = array();
		$GLOBALS['_wpmar_test_mail_calls']        = array();
		$GLOBALS['_wpmar_test_timezone']          = 'Asia/Tokyo';
		$GLOBALS['_wpmar_test_upload_basedir']    = $this->uploads_base;
		$GLOBALS['_wpmar_test_is_multisite']      = false;
		$GLOBALS['_wpmar_test_current_blog_id']   = 1;
		$GLOBALS['wpdb']                          = new \WPMAR_Test_Fake_Wpdb();
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->uploads_base );
		$this->rrmdir( WP_CONTENT_DIR );
		unset(
			$GLOBALS['_wpmar_test_options'],
			$GLOBALS['_wpmar_test_transients'],
			$GLOBALS['_wpmar_test_mail_calls'],
			$GLOBALS['_wpmar_test_timezone'],
			$GLOBALS['_wpmar_test_upload_basedir'],
			$GLOBALS['_wpmar_test_is_multisite'],
			$GLOBALS['_wpmar_test_current_blog_id'],
			$GLOBALS['wpdb']
		);
		parent::tearDown();
	}

	/**
	 * Baseline settings for this test class: MD/PDF file writes disabled (out
	 * of scope here — see the Step 5/6 rendering & PDF tests) and retention
	 * purge disabled (Fake wpdb's get_col() doesn't emulate a real date WHERE
	 * filter, so leaving retention on would self-purge the row this test just
	 * inserted).
	 *
	 * @return array<string,mixed>
	 */
	private function base_settings() {
		return array(
			'output'    => array(
				'md_enabled'  => false,
				'pdf_enabled' => false,
			),
			'retention' => array(
				'months' => 0,
			),
			'mail'      => array(
				'enabled' => false,
			),
		);
	}

	/**
	 * @param array<string,mixed> $overrides Deep-merged onto base_settings().
	 * @return void
	 */
	private function configure_settings( array $overrides ) {
		$GLOBALS['_wpmar_test_options']['wpmar_settings'] = array_replace_recursive( $this->base_settings(), $overrides );
	}

	/**
	 * @param string $dir Directory to remove recursively.
	 * @return void
	 */
	private function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	public function test_success_result_has_report_id_mail_sent_and_status(): void {
		$runner = new ExposedFullRunRunner( new \WPMAR_Test_Fake_Data_Collector( $this->dataset_full ) );

		$result = $runner->run( array( 'triggered_by' => 'manual' ) );

		self::assertArrayHasKey( 'report_id', $result );
		self::assertArrayHasKey( 'mail_sent', $result );
		self::assertArrayHasKey( 'status', $result );
		self::assertSame( 'success', $result['status'] );
	}

	public function test_inserts_exactly_one_report_with_distinct_admin_and_client_bodies(): void {
		$runner = new ExposedFullRunRunner( new \WPMAR_Test_Fake_Data_Collector( $this->dataset_full ) );
		$runner->run( array( 'triggered_by' => 'manual' ) );

		self::assertCount( 1, $GLOBALS['wpdb']->insert_calls );

		$row = $GLOBALS['wpdb']->insert_calls[0];
		self::assertNotSame( '', $row['body_md'] );
		self::assertNotSame( '', $row['body_client_md'] );
		self::assertNotSame( $row['body_md'], $row['body_client_md'] );
	}

	public function test_mail_is_sent_before_the_report_row_is_inserted(): void {
		$this->configure_settings(
			array(
				'mail' => array(
					'enabled'   => true,
					'client_to' => array( 'client@example.test' ),
					'admin_to'  => array( 'admin@example.test' ),
				),
			)
		);

		$runner = new ExposedFullRunRunner( new \WPMAR_Test_Fake_Data_Collector( $this->dataset_full ) );
		$runner->run( array( 'triggered_by' => 'manual' ) );

		self::assertNotEmpty( $GLOBALS['_wpmar_test_mail_calls'], 'Expected at least one wp_mail() call.' );
		self::assertCount( 1, $GLOBALS['wpdb']->insert_calls );

		$last_mail_seq = end( $GLOBALS['_wpmar_test_mail_calls'] )['seq'];
		$insert_seq    = $GLOBALS['wpdb']->insert_seqs[0];

		self::assertLessThan( $insert_seq, $last_mail_seq, 'Every mail send must happen before the report INSERT.' );
	}

	public function test_persist_snapshots_defaults_to_false_for_manual_runs(): void {
		$runner = new ExposedFullRunRunner( new \WPMAR_Test_Fake_Data_Collector( $this->dataset_full ) );
		$runner->run( array( 'triggered_by' => 'manual' ) );

		self::assertArrayNotHasKey( 'wp_wpmar_snapshots', $GLOBALS['wpdb']->tables );
	}

	public function test_persist_snapshots_true_saves_and_prunes_every_dimension(): void {
		$runner = new ExposedFullRunRunner( new \WPMAR_Test_Fake_Data_Collector( $this->dataset_full ) );
		$runner->run(
			array(
				'triggered_by'      => 'manual',
				'persist_snapshots' => true,
			)
		);

		self::assertArrayHasKey( 'wp_wpmar_snapshots', $GLOBALS['wpdb']->tables );
		// core / themes / plugins / users — one row per canonical diff dimension.
		self::assertCount( 4, $GLOBALS['wpdb']->tables['wp_wpmar_snapshots'] );
	}

	public function test_summary_json_records_baseline_and_whether_snapshots_were_persisted(): void {
		$runner = new ExposedFullRunRunner( new \WPMAR_Test_Fake_Data_Collector( $this->dataset_full ) );
		$runner->run(
			array(
				'triggered_by'      => 'manual',
				'persist_snapshots' => true,
			)
		);

		// persist_snapshots=true also inserts 4 snapshot rows before the report row -
		// the report row is always last (mail/snapshots precede it; see the "Mail
		// intentionally precedes INSERT" note in run()), so take the tail entry rather
		// than assuming index 0.
		$summary = json_decode( end( $GLOBALS['wpdb']->insert_calls )['summary_json'], true );

		self::assertTrue( $summary['snapshots_persisted'] );
		self::assertArrayHasKey( 'baseline', $summary );
		// The fake wpdb has no rows to find a prior snapshot in, so every dimension is a
		// first-ever collection - there is no baseline to report yet.
		foreach ( array( 'core', 'themes', 'plugins', 'users' ) as $dimension ) {
			self::assertArrayHasKey( $dimension, $summary['baseline'] );
			self::assertNull( $summary['baseline'][ $dimension ] );
		}
	}

	public function test_summary_json_reports_snapshots_not_persisted_for_manual_runs(): void {
		$runner = new ExposedFullRunRunner( new \WPMAR_Test_Fake_Data_Collector( $this->dataset_full ) );
		$runner->run( array( 'triggered_by' => 'manual' ) );

		$summary = json_decode( end( $GLOBALS['wpdb']->insert_calls )['summary_json'], true );

		self::assertFalse( $summary['snapshots_persisted'] );
	}

	public function test_domain_gate_mismatch_marks_row_skipped_and_suppresses_mail_and_snapshots(): void {
		$this->configure_settings(
			array(
				'domain' => array( 'allowed_host' => 'not-this-site.example.test' ),
				'mail'   => array(
					'enabled'   => true,
					'client_to' => array( 'client@example.test' ),
					'admin_to'  => array( 'admin@example.test' ),
				),
			)
		);

		$runner = new ExposedFullRunRunner( new \WPMAR_Test_Fake_Data_Collector( $this->dataset_full ) );
		$result = $runner->run(
			array(
				'triggered_by'      => 'manual',
				'persist_snapshots' => true,
			)
		);

		self::assertSame( 'skipped_domain', $result['status'] );
		self::assertFalse( $result['mail_sent'] );
		self::assertCount( 0, $GLOBALS['_wpmar_test_mail_calls'] );
		self::assertArrayNotHasKey( 'wp_wpmar_snapshots', $GLOBALS['wpdb']->tables );
	}

	public function test_busy_run_lock_short_circuits_without_touching_the_collector(): void {
		$GLOBALS['_wpmar_test_transients']['wpmar_run_lock'] = 1;

		$runner = new ExposedFullRunRunner( new \WPMAR_Test_Fake_Data_Collector( $this->dataset_full ) );
		$result = $runner->run( array( 'triggered_by' => 'manual' ) );

		self::assertSame(
			array(
				'skipped' => true,
				'reason'  => 'busy',
			),
			$result
		);
		self::assertCount( 0, $GLOBALS['wpdb']->insert_calls );
	}

	public function test_run_lock_is_released_via_finally_even_when_the_collector_throws(): void {
		$runner = new ExposedFullRunRunner( new \WPMAR_Test_Fake_Data_Collector( array(), new \RuntimeException( 'gather failed' ) ) );

		try {
			$runner->run( array( 'triggered_by' => 'manual' ) );
			self::fail( 'Expected the collector exception to propagate.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'gather failed', $e->getMessage() );
		}

		self::assertFalse( get_transient( 'wpmar_run_lock' ), 'The mutex must be released even when run() exits via an exception.' );
	}

	public function test_mail_disabled_still_inserts_but_reports_mail_sent_false(): void {
		// mail.enabled is already false in base_settings().
		$runner = new ExposedFullRunRunner( new \WPMAR_Test_Fake_Data_Collector( $this->dataset_full ) );
		$result = $runner->run( array( 'triggered_by' => 'manual' ) );

		self::assertFalse( $result['mail_sent'] );
		self::assertCount( 0, $GLOBALS['_wpmar_test_mail_calls'] );
		self::assertCount( 1, $GLOBALS['wpdb']->insert_calls );
		self::assertSame( 0, $GLOBALS['wpdb']->insert_calls[0]['mail_sent'] );
	}

	public function test_pdf_disabled_never_touches_the_pdf_writer(): void {
		// output.pdf_enabled is already false in base_settings(); if the runner
		// ever reached WPMAR_PDF_Writer::write_pdf_from_markdown() regardless,
		// pdf_file_path would come back non-empty (or the run would blow up
		// trying to render/write a real PDF via mPDF).
		$runner = new ExposedFullRunRunner( new \WPMAR_Test_Fake_Data_Collector( $this->dataset_full ) );
		$runner->run( array( 'triggered_by' => 'manual' ) );

		self::assertSame( '', $GLOBALS['wpdb']->insert_calls[0]['pdf_file_path'] );
	}

	public function test_completion_updates_last_audit_timestamp_and_reschedules_cron(): void {
		$runner = new ExposedFullRunRunner( new \WPMAR_Test_Fake_Data_Collector( $this->dataset_full ) );
		$runner->run( array( 'triggered_by' => 'manual' ) );

		self::assertNotEmpty( get_option( 'wpmar_last_audit_completed_at' ) );
		self::assertNotEmpty( wp_next_scheduled( WPMAR_HOOK_SCHEDULED ), 'reschedule() must leave a future WPMAR_HOOK_SCHEDULED event.' );
	}
}
