<?php
/**
 * Unit tests for WPMAR_Check_Auto_Updates::collect()'s filter-execution isolation.
 *
 * Runs with process isolation because it flips the shared
 * $GLOBALS['_wpmar_test_apply_filters_functional'] switch (see wp-stubs.php) to make
 * add_filter() callbacks actually run — every other test file relies on apply_filters()
 * staying a no-op pass-through (see tests/UpdaterConfigTest.php for the same pattern).
 *
 * @package WPMAR\Tests
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/checks/class-wpmar-check-auto-updates.php';

/**
 * Exposes log_filter_output() calls in-memory instead of routing them to the real
 * WPMAR_Logger (which needs WPMAR_Private_Storage / WPMAR_Jobs_Repository and writes to
 * disk). Other test files in this suite may load the real WPMAR_Logger as a side effect
 * of requiring class-wpmar-runner.php etc.; overriding this one protected method (rather
 * than relying on `class_exists( 'WPMAR_Logger' )` being false) keeps this test correct
 * regardless of what ran before it in the same PHPUnit process.
 */
class ExposedCheckAutoUpdatesForLogging extends \WPMAR_Check_Auto_Updates {

	/**
	 * @var array<int,array{0:string,1:int}>
	 */
	public $logged = array();

	/**
	 * @param string $log_key Identifier for the log entry.
	 * @param int    $bytes   Number of captured bytes.
	 * @return void
	 */
	protected function log_filter_output( $log_key, $bytes ) {
		$this->logged[] = array( $log_key, $bytes );
	}
}

/**
 * Covers the "Filter execution is isolated two ways, and only two" contract from the
 * class docblock: exceptions and stray output (echo/warnings) must never escape
 * collect(), and a captured output buffer's content is logged by byte count only.
 */
final class CheckAutoUpdatesCollectTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_wpmar_test_filters']                  = array();
		$GLOBALS['_wpmar_test_apply_filters_functional']  = false;
		$GLOBALS['_wpmar_test_actions_fired']             = array();
		$GLOBALS['_wpmar_test_site_transients']           = array();
		$GLOBALS['_wpmar_test_site_options']              = array();
		$GLOBALS['_wpmar_test_vcs_checkout']              = array();
		$GLOBALS['_wpmar_test_installing']                = false;
		$GLOBALS['_wpmar_test_core_should_update']        = false;

		\WPMAR_Check_Auto_Updates::reset_site_gates_cache();
	}

	/**
	 * One plugin inventory row plus a matching `update_plugins` site-transient offer in
	 * the `response` bucket, so has_offer=true and the `auto_update_plugin` filter runs.
	 *
	 * @return array<string,mixed>
	 */
	private function datasetWithPluginOffer(): array {
		$offer               = new \stdClass();
		$offer->id           = 'w.org/plugins/demo';
		$offer->new_version  = '1.1';
		$offer->autoupdate   = false;
		$offer->requires_php = '';

		$transient           = new \stdClass();
		$transient->response = array( 'demo/demo.php' => $offer );
		$transient->no_update = array();

		$GLOBALS['_wpmar_test_site_transients']['update_plugins'] = $transient;

		return array(
			'plugins' => array(
				'inventory' => array(
					array(
						'slug'     => 'demo',
						'basename' => 'demo/demo.php',
						'title'    => 'Demo',
						'version'  => '1.0',
						'active'   => true,
					),
				),
			),
			'themes'  => array( 'inventory' => array() ),
		);
	}

	/** ⓐ★ フィルターが echo する → collect() が完走し、出力が呼び出し側に漏れない. */
	public function test_a_echoing_filter_does_not_leak_to_caller(): void {
		$GLOBALS['_wpmar_test_apply_filters_functional'] = true;
		add_filter(
			'auto_update_plugin',
			function ( $pre, $item ) {
				unset( $item );
				echo 'leaked-output-from-filter';
				return $pre;
			},
			10,
			2
		);

		$level_before = ob_get_level();

		ob_start();
		$check  = new \WPMAR_Check_Auto_Updates();
		$result = $check->collect( array(), $this->datasetWithPluginOffer() );
		$caller_saw = ob_get_clean();

		self::assertSame( '', $caller_saw, '呼び出し側の出力バッファにフィルターの echo が漏れないこと' );
		self::assertSame( $level_before, ob_get_level(), 'ob_get_level() が呼び出し前後で等しいこと' );
		self::assertArrayHasKey( 'demo/demo.php', $result['plugins'] );
	}

	/**
	 * ⓑ★ フィルターが warning を出す（未定義配列キーアクセス）→ 同上。Throwable では
	 * 捕捉できないことの明示的な回帰ガード。
	 *
	 * PHPUnit は既定で PHP warning を例外に変換するため、その場合 guarded_filter() の
	 * try/catch がたまたま拾ってしまい「Throwable では捕捉できない」という検証にならない。
	 * ここでは一時的に独自のエラーハンドラへ差し替え、実運用（display_errors 環境で
	 * warning がそのまま出力される WordPress）に近い状態を再現してから検証する。
	 */
	public function test_b_filter_warning_is_not_converted_to_exception_and_does_not_leak(): void {
		$GLOBALS['_wpmar_test_apply_filters_functional'] = true;
		add_filter(
			'auto_update_plugin',
			function ( $pre, $item ) {
				unset( $item );
				trigger_error( 'demo warning from filter', E_USER_WARNING );
				return $pre;
			},
			10,
			2
		);

		$previous_handler = set_error_handler(
			function ( $errno, $errstr ) {
				unset( $errno );
				echo 'PHP Warning: ' . $errstr;
				return true;
			}
		);

		$level_before = ob_get_level();

		try {
			ob_start();
			$check  = new \WPMAR_Check_Auto_Updates();
			$result = $check->collect( array(), $this->datasetWithPluginOffer() );
			$caller_saw = ob_get_clean();
		} finally {
			set_error_handler( $previous_handler );
		}

		self::assertSame( '', $caller_saw );
		self::assertSame( $level_before, ob_get_level() );
		self::assertArrayHasKey( 'demo/demo.php', $result['plugins'] );
	}

	/**
	 * ⓒ★ フィルターが echo した場合にログへ記録が残り（bytes のみ）、出力の中身そのもの
	 * は記録されないこと.
	 */
	public function test_c_filter_output_is_logged_by_byte_count_only(): void {
		$GLOBALS['_wpmar_test_apply_filters_functional'] = true;
		$leaked = 'sensitive-looking-output-<script>';
		add_filter(
			'auto_update_plugin',
			function ( $pre, $item ) use ( $leaked ) {
				unset( $item );
				echo $leaked;
				return $pre;
			},
			10,
			2
		);

		ob_start();
		$check = new ExposedCheckAutoUpdatesForLogging();
		$check->collect( array(), $this->datasetWithPluginOffer() );
		ob_end_clean();

		$matching = array_values(
			array_filter(
				$check->logged,
				static function ( $call ) {
					return 'item:plugin:demo/demo.php' === $call[0];
				}
			)
		);

		self::assertNotEmpty( $matching, 'フィルター出力が発生したらログが記録されること' );
		self::assertSame( strlen( $leaked ), $matching[0][1], 'bytes が実際の出力長と一致すること' );

		// 記録される値は log_key（識別子）と bytes（整数）のみ。出力の中身そのものは
		// 一切保持されないので、漏洩した文字列を含む値はどこにも現れない。
		foreach ( $check->logged as $call ) {
			foreach ( $call as $value ) {
				if ( is_string( $value ) ) {
					self::assertStringNotContainsString( $leaked, $value, '出力の中身そのものはログに残さないこと' );
				}
			}
		}
	}

	/**
	 * ⓓ★ フィルターが例外を投げた場合でも出力バッファが確実に閉じること（try の中で
	 * ob_start() し finally で ob_get_clean() する設計の回帰ガード。閉じ忘れると以降の
	 * 出力がすべて飲み込まれ、レポート本文が空になる）。
	 */
	public function test_d_output_buffer_closes_even_when_filter_throws(): void {
		$GLOBALS['_wpmar_test_apply_filters_functional'] = true;
		add_filter(
			'auto_update_plugin',
			function ( $pre, $item ) {
				unset( $pre, $item );
				echo 'before-throw';
				throw new \RuntimeException( 'boom from filter' );
			},
			10,
			2
		);

		$level_before = ob_get_level();

		ob_start();
		$check  = new \WPMAR_Check_Auto_Updates();
		$result = $check->collect( array(), $this->datasetWithPluginOffer() );
		$caller_saw = ob_get_clean();

		self::assertSame( '', $caller_saw );
		self::assertSame( $level_before, ob_get_level(), '例外発生後も ob バッファが確実に閉じていること' );

		$row = $result['plugins']['demo/demo.php'];
		self::assertTrue( $row['filter_error'] );
		self::assertFalse( $row['decision'] === null );

		// フィルター実行後も次の出力（このアサーション自体を含む PHPUnit の以降の処理）が
		// 飲み込まれていないこと、を間接的に示す：ここまで到達し、通常どおり文字列出力できる。
		ob_start();
		echo 'still-alive';
		self::assertSame( 'still-alive', ob_get_clean() );
	}
}
