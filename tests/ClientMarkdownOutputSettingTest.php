<?php
/**
 * クライアント向け Markdown のファイル保存設定 (output.client_md_enabled, 1.8.0) のテスト.
 *
 * 単一サイト (WPMAR_Settings) とネットワーク (WPMAR_Network_Settings) の両方で、
 * 既定値・フォームからの保存・1.8.0 より前に保存した設定 (キーが無い) の扱いを確かめる.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-network-settings.php';

/**
 * Covers output.client_md_enabled in both settings envelopes.
 *
 * @coversNothing
 */
final class ClientMarkdownOutputSettingTest extends TestCase {

	/**
	 * テストごとにオプションの保存先を空にする.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wpmar_test_options']      = array();
		$GLOBALS['_wpmar_test_site_options'] = array();
		$GLOBALS['_wpmar_test_is_multisite'] = true;
	}

	// -------------------------------------------------------------------------
	// 単一サイト
	// -------------------------------------------------------------------------

	/**
	 * 既定値はオフ. 既存の md_enabled / pdf_enabled の既定値は変えていないこと.
	 *
	 * @return void
	 */
	public function test_site_defaults_keep_client_md_off_and_existing_outputs_on(): void {
		$output = \WPMAR_Settings::defaults()['output'];

		self::assertFalse( $output['client_md_enabled'] );
		self::assertTrue( $output['md_enabled'] );
		self::assertTrue( $output['pdf_enabled'] );
	}

	/**
	 * チェックボックスにチェックがあればオン、無ければ (POST に無ければ) オフになること.
	 *
	 * @return void
	 */
	public function test_site_merge_form_input_follows_the_checkbox(): void {
		$checked = \WPMAR_Settings::merge_form_input(
			array( 'wpmar_client_md_enabled' => 'on' ),
			\WPMAR_Settings::defaults()
		);
		self::assertTrue( $checked['output']['client_md_enabled'] );

		$unchecked = \WPMAR_Settings::merge_form_input( array(), $checked );
		self::assertFalse( $unchecked['output']['client_md_enabled'] );
	}

	/**
	 * 1.8.0 より前に保存した output (キーが無い) は、オフとして扱われること.
	 * get_all() の wp_parse_args() は 1 段目しかマージしないので、既定値は入らずキーが無いまま.
	 * 呼び出し側は ! empty() で判定するので、キーが無い = オフになる.
	 *
	 * @return void
	 */
	public function test_site_settings_saved_before_1_8_0_treat_client_md_as_off(): void {
		$GLOBALS['_wpmar_test_options']['wpmar_settings'] = array(
			'output' => array(
				'md_enabled'  => true,
				'pdf_enabled' => true,
			),
		);

		$settings = \WPMAR_Settings::get_all();

		self::assertTrue( empty( $settings['output']['client_md_enabled'] ) );
		self::assertTrue( $settings['output']['md_enabled'] );
	}

	// -------------------------------------------------------------------------
	// ネットワーク
	// -------------------------------------------------------------------------

	/**
	 * ネットワークの既定値もオフ.
	 *
	 * @return void
	 */
	public function test_network_defaults_keep_client_md_off(): void {
		self::assertFalse( \WPMAR_Network_Settings::defaults()['output']['client_md_enabled'] );
	}

	/**
	 * 1.8.0 より前に保存したネットワーク設定でも、normalize() で false が補われること.
	 *
	 * @return void
	 */
	public function test_network_settings_saved_before_1_8_0_get_client_md_backfilled_as_false(): void {
		$GLOBALS['_wpmar_test_site_options']['wpmar_network_settings'] = array(
			'output' => array(
				'md_enabled'  => true,
				'pdf_enabled' => false,
			),
		);

		$settings = \WPMAR_Network_Settings::get_all();

		self::assertArrayHasKey( 'client_md_enabled', $settings['output'] );
		self::assertFalse( $settings['output']['client_md_enabled'] );
		self::assertFalse( $settings['output']['pdf_enabled'] );
	}

	/**
	 * ネットワークの設定画面でも、チェックボックスに従ってオン・オフになること.
	 *
	 * @return void
	 */
	public function test_network_merge_form_input_follows_the_checkbox(): void {
		$curr = \WPMAR_Network_Settings::get_all();

		$checked = \WPMAR_Network_Settings::merge_form_input(
			array( 'wpmar_client_md_enabled' => 'on' ),
			$curr
		);
		self::assertTrue( $checked['output']['client_md_enabled'] );

		$unchecked = \WPMAR_Network_Settings::merge_form_input( array(), $checked );
		self::assertFalse( $unchecked['output']['client_md_enabled'] );
	}
}
