<?php
/**
 * PHPUnit coverage for WordPress.org directory version comparison.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Asserts semver ordering used in theme/plugin report sections.
 *
 * @coversNothing
 */
final class DirectoryVersionStatusTest extends TestCase {

	/**
	 * Bootstraps runner class without WordPress.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
		}
		// get_site_transient() と sanitize_text_field() のスタブ. テストで更新トランジェントを
		// 読む処理が使う. テスト全体では他のテストが先に読み込むので気づかれなかったが、
		// このファイルだけを実行すると未定義で失敗していた.
		require_once __DIR__ . '/wp-stubs.php';
		require_once dirname( __DIR__ ) . '/includes/class-wpmar-runner.php';
		// 更新トランジェントの型差(stdClass と配列)を吸収する正規化関数を使うため読み込む.
		require_once dirname( __DIR__ ) . '/includes/checks/class-wpmar-check-auto-updates.php';
	}

	/**
	 * 各テストの前後で更新トランジェントのスタブを空にする.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['_wpmar_test_site_transients']['update_plugins'], $GLOBALS['_wpmar_test_site_transients']['update_themes'] );
		parent::tearDown();
	}

	/**
	 * Invokes protected static helper.
	 *
	 * @param string $installed Installed version.
	 * @param string $latest    Directory version.
	 * @return string
	 */
	private function status( $installed, $latest ) {
		$method = new ReflectionMethod( \WPMAR_Runner::class, 'directory_version_status' );
		$method->setAccessible( true );

		return (string) $method->invoke( null, $installed, $latest );
	}

	/**
	 * @return void
	 */
	public function test_update_available_when_installed_is_older(): void {
		self::assertSame( 'update_available', $this->status( '1.3', '1.4' ) );
	}

	/**
	 * @return void
	 */
	public function test_current_when_versions_match(): void {
		self::assertSame( 'current', $this->status( '1.4', '1.4' ) );
	}

	/**
	 * @return void
	 */
	public function test_data_error_when_installed_is_newer_than_directory(): void {
		self::assertSame( 'data_error', $this->status( '1.5', '1.4' ) );
	}

	/**
	 * @return void
	 */
	public function test_unknown_when_either_version_is_empty(): void {
		self::assertSame( 'unknown', $this->status( '', '1.4' ) );
		self::assertSame( 'unknown', $this->status( '1.4', '' ) );
	}

	/**
	 * 更新待ちバージョンを返す protected static メソッドを呼び出す.
	 *
	 * @param string $method  'pending_plugin_new_version' または 'pending_theme_new_version'.
	 * @param string $key     プラグインの basename またはテーマの stylesheet.
	 * @return string
	 */
	private function pending( $method, $key ) {
		$m = new ReflectionMethod( \WPMAR_Runner::class, $method );
		$m->setAccessible( true );

		return (string) $m->invoke( null, $key );
	}

	/**
	 * 回帰ガード: update_plugins の response の要素は WordPress が必ず stdClass にする.
	 * 1.6.0 以前は is_array() で判定していたため、プラグインに対して常に空文字を返していた.
	 *
	 * @return void
	 */
	public function test_pending_plugin_version_is_read_from_stdclass_entry(): void {
		$GLOBALS['_wpmar_test_site_transients']['update_plugins'] = (object) array(
			'response' => array(
				'akismet/akismet.php' => (object) array( 'new_version' => '5.4' ),
			),
		);

		self::assertSame( '5.4', $this->pending( 'pending_plugin_new_version', 'akismet/akismet.php' ) );
	}

	/**
	 * 型差を吸収する正規化を通すので、配列の要素でも同じ結果になる.
	 *
	 * @return void
	 */
	public function test_pending_plugin_version_is_read_from_array_entry(): void {
		$GLOBALS['_wpmar_test_site_transients']['update_plugins'] = (object) array(
			'response' => array(
				'akismet/akismet.php' => array( 'new_version' => '5.4' ),
			),
		);

		self::assertSame( '5.4', $this->pending( 'pending_plugin_new_version', 'akismet/akismet.php' ) );
	}

	/**
	 * update_themes の response の要素は WordPress が配列にする(従来から正しく動いていた側).
	 * 正規化経由にした後も、同じ結果になること.
	 *
	 * @return void
	 */
	public function test_pending_theme_version_is_read_from_array_and_stdclass_entry(): void {
		$GLOBALS['_wpmar_test_site_transients']['update_themes'] = (object) array(
			'response' => array(
				'twentytwentyfive' => array( 'new_version' => '1.4' ),
				'astra'            => (object) array( 'new_version' => '4.8' ),
			),
		);

		self::assertSame( '1.4', $this->pending( 'pending_theme_new_version', 'twentytwentyfive' ) );
		self::assertSame( '4.8', $this->pending( 'pending_theme_new_version', 'astra' ) );
	}

	/**
	 * 更新待ちがない・トランジェントが想定外の形のときは、空文字を返す.
	 *
	 * @return void
	 */
	public function test_pending_version_is_empty_when_no_pending_update(): void {
		// トランジェントが存在しない(get_site_transient() が false を返す).
		self::assertSame( '', $this->pending( 'pending_plugin_new_version', 'akismet/akismet.php' ) );
		self::assertSame( '', $this->pending( 'pending_theme_new_version', 'astra' ) );

		// response に該当キーがない(no_update 側にだけある場合など).
		$GLOBALS['_wpmar_test_site_transients']['update_plugins'] = (object) array(
			'response'  => array(),
			'no_update' => array( 'akismet/akismet.php' => (object) array( 'new_version' => '5.3' ) ),
		);
		self::assertSame( '', $this->pending( 'pending_plugin_new_version', 'akismet/akismet.php' ) );

		// オブジェクトでないトランジェント / new_version がない要素 / 空のキー.
		$GLOBALS['_wpmar_test_site_transients']['update_plugins'] = 'broken';
		self::assertSame( '', $this->pending( 'pending_plugin_new_version', 'akismet/akismet.php' ) );

		$GLOBALS['_wpmar_test_site_transients']['update_plugins'] = (object) array(
			'response' => array( 'akismet/akismet.php' => (object) array( 'package' => 'x' ) ),
		);
		self::assertSame( '', $this->pending( 'pending_plugin_new_version', 'akismet/akismet.php' ) );
		self::assertSame( '', $this->pending( 'pending_plugin_new_version', '' ) );
	}
}
