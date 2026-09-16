<?php
/**
 * WPMAR_Check_Auto_Updates::collect() のフィルター実行隔離に対する単体テスト.
 *
 * `$GLOBALS['_wpmar_test_apply_filters_functional']`（wp-stubs.php を参照）を切り替えて
 * add_filter() のコールバックを実際に走らせるため、プロセス分離で実行する —
 * このスイートの他のテストファイルはすべて apply_filters() が no-op のまま素通りすることに
 * 依存している（同じパターンは tests/UpdaterConfigTest.php を参照）.
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
 * log_filter_output() の呼び出しを、本物の WPMAR_Logger（WPMAR_Private_Storage /
 * WPMAR_Jobs_Repository を必要とし、ディスクに書き込む）に流す代わりにインメモリで捕捉する.
 * このスイートの他のテストファイルが class-wpmar-runner.php 等を require する副作用で本物の
 * WPMAR_Logger を先にロードしてしまうことがあるため、
 * `class_exists( 'WPMAR_Logger' )` が false であることに頼るのではなく、この protected
 * メソッド1つだけをオーバーライドすることで、同一 PHPUnit プロセス内で何が先に実行されたかに
 * 関わらずこのテストを正しく動かす.
 */
class ExposedCheckAutoUpdatesForLogging extends \WPMAR_Check_Auto_Updates {

	/**
	 * @var array<int,array{0:string,1:int}>
	 */
	public $logged = array();

	/**
	 * @param string $log_key ログエントリの識別子.
	 * @param int    $bytes   捕捉したバイト数.
	 * @return void
	 */
	protected function log_filter_output( $log_key, $bytes ) {
		$this->logged[] = array( $log_key, $bytes );
	}
}

/**
 * クラス docblock の「フィルター実行の隔離は2種類だけであり、それ以外はない」という契約を
 * 検証する: 例外と意図しない出力（echo/warning）は collect() の外に一切漏れてはならず、
 * 捕捉した出力バッファの中身はバイト数のみをログに残す.
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
	 * プラグイン inventory 1行と、それに対応する `update_plugins` site-transient の
	 * `response` バケット内のオファーを用意する。これにより has_offer=true になり
	 * `auto_update_plugin` フィルターが実行される.
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
