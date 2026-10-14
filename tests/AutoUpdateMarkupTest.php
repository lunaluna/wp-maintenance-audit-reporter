<?php
/**
 * Unit tests for the 1.7.0 auto-update wording in
 * WPMAR_Runner::render_operator_markup() / render_client_markup().
 *
 * Cases already covered by tests/OperatorMarkupTest.php (section presence,
 * domain gate, changelog wording, etc.) are not repeated here — this file only
 * exercises the `auto_updates` facts added in 1.7.0
 * (WPMAR_Check_Auto_Updates::collect()).
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/checks/class-wpmar-check-performance.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-runner.php';

/**
 * Covers the auto-update prose in both audiences' core/theme/plugin sections.
 */
final class AutoUpdateMarkupTest extends TestCase {

	/** @var array<string,mixed> */
	private $dataset_full;

	protected function setUp(): void {
		parent::setUp();

		$this->dataset_full = require __DIR__ . '/fixtures/dataset-full.php';

		$GLOBALS['_wpmar_test_site_transients']         = array();
		$GLOBALS['_wpmar_test_filters']                 = array();
		$GLOBALS['_wpmar_test_apply_filters_functional'] = false;
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['_wpmar_test_site_transients'],
			$GLOBALS['_wpmar_test_filters'],
			$GLOBALS['_wpmar_test_apply_filters_functional']
		);
		parent::tearDown();
	}

	/**
	 * @param array<string,mixed> $core_overrides `auto_updates.core` にマージする上書き値.
	 * @return array<string,mixed>
	 */
	private function dataset_with_core_auto( array $core_overrides ): array {
		$dataset                         = $this->dataset_full;
		$dataset['auto_updates']['core'] = array_merge( $dataset['auto_updates']['core'], $core_overrides );

		return $dataset;
	}

	/**
	 * @param string              $key      プラグインの basename.
	 * @param array<string,mixed> $overrides `auto_updates.plugins[$key]` にマージする上書き値.
	 * @return array<string,mixed>
	 */
	private function dataset_with_plugin_auto( string $key, array $overrides ): array {
		$dataset                                    = $this->dataset_full;
		$dataset['auto_updates']['plugins'][ $key ] = array_merge( $dataset['auto_updates']['plugins'][ $key ], $overrides );

		return $dataset;
	}

	/**
	 * @param string              $key      テーマの slug.
	 * @param array<string,mixed> $overrides `auto_updates.themes[$key]` にマージする上書き値.
	 * @return array<string,mixed>
	 */
	private function dataset_with_theme_auto( string $key, array $overrides ): array {
		$dataset                                   = $this->dataset_full;
		$dataset['auto_updates']['themes'][ $key ] = array_merge( $dataset['auto_updates']['themes'][ $key ], $overrides );

		return $dataset;
	}

	// -------------------------------------------------------------------------
	// コアの文言
	// -------------------------------------------------------------------------

	public function test_core_major_wording(): void {
		$dataset = $this->dataset_with_core_auto( array( 'state' => 'major' ) );

		$operator = \WPMAR_Runner::render_operator_markup( $dataset, '', true, 0, 10 );

		self::assertStringContainsString(
			'自動更新：WordPress 本体は自動でメジャーアップデートに設定されています。',
			$operator
		);
	}

	public function test_core_minor_wording(): void {
		$dataset  = $this->dataset_with_core_auto( array( 'state' => 'minor' ) );
		$operator = \WPMAR_Runner::render_operator_markup( $dataset, '', true, 0, 10 );

		self::assertStringContainsString(
			'WordPress 本体は自動でマイナーアップデート（セキュリティ・保守リリース）のみ行う設定です。',
			$operator
		);
	}

	public function test_core_disabled_site_wording_includes_reason(): void {
		$dataset = $this->dataset_full;
		$dataset['auto_updates']['core']['state']                     = 'disabled_site';
		$dataset['auto_updates']['site']['disabled_reason']['core']   = 'constant';

		$operator = \WPMAR_Runner::render_operator_markup( $dataset, '', true, 0, 10 );

		self::assertStringContainsString(
			'このサイトでは自動更新機能そのものが無効化されているため、WordPress 本体は自動更新されません。',
			$operator
		);
		self::assertStringContainsString(
			'　　（AUTOMATIC_UPDATER_DISABLED 定数で無効化されています）',
			$operator
		);
	}

	public function test_core_blocked_failure_wording(): void {
		$dataset  = $this->dataset_with_core_auto( array( 'state' => 'blocked_failure' ) );
		$operator = \WPMAR_Runner::render_operator_markup( $dataset, '', true, 0, 10 );

		self::assertStringContainsString(
			'過去に自動更新が致命的に失敗した記録が残っているため、自動更新は行われません。',
			$operator
		);
	}

	public function test_core_constant_source_note(): void {
		$dataset = $this->dataset_with_core_auto(
			array(
				'state'  => 'minor',
				'source' => 'constant',
				'raw'    => array_merge(
					$this->dataset_full['auto_updates']['core']['raw'],
					array(
						'constant'     => 'minor',
						'constant_raw' => 'minor',
					)
				),
			)
		);

		$operator = \WPMAR_Runner::render_operator_markup( $dataset, '', true, 0, 10 );

		self::assertStringContainsString(
			'（wp-config.php の WP_AUTO_UPDATE_CORE 定数（minor）により制御されています。',
			$operator
		);
	}

	// -------------------------------------------------------------------------
	// プラグインの文言
	// -------------------------------------------------------------------------

	public function test_plugin_on_wording(): void {
		$dataset  = $this->dataset_with_plugin_auto( 'sample-active-plugin/sample-active-plugin.php', array( 'state' => 'on' ) );
		$operator = \WPMAR_Runner::render_operator_markup( $dataset, '', true, 0, 10 );

		self::assertStringContainsString(
			'　このプラグインは自動アップデートが有効化されています。',
			$operator
		);
	}

	/**
	 * ★ toggle=T, disable_autoupdate=T, filter=T → filter_forced_on の文言に
	 * （管理画面のトグル: ON）が出ること.
	 */
	public function test_plugin_filter_forced_on_wording_reports_actual_toggle_value(): void {
		$dataset = $this->dataset_with_plugin_auto(
			'sample-active-plugin/sample-active-plugin.php',
			array(
				'state'              => 'filter_forced_on',
				'toggle'             => true,
				'disable_autoupdate' => true,
			)
		);

		$operator = \WPMAR_Runner::render_operator_markup( $dataset, '', true, 0, 10 );

		self::assertStringContainsString(
			'auto_update_plugin フィルターにより、WordPress の通常判定では無効だった自動アップデートが有効化されています。（管理画面のトグル: ON）',
			$operator
		);
	}

	/**
	 * ★ toggle=F, offer_autoupdate=T のケース（state='on'）で、文言が誤ってトグル ON と
	 * 書かないこと（'on' の文言はトグル値に一切触れない）.
	 */
	public function test_plugin_on_via_offer_autoupdate_does_not_mention_toggle(): void {
		$dataset = $this->dataset_with_plugin_auto(
			'sample-active-plugin/sample-active-plugin.php',
			array(
				'state'            => 'on',
				'toggle'           => false,
				'offer_autoupdate' => true,
			)
		);

		$operator = \WPMAR_Runner::render_operator_markup( $dataset, '', true, 0, 10 );

		self::assertStringContainsString(
			'このプラグインは自動アップデートが有効化されています。',
			$operator
		);
		self::assertStringNotContainsString( 'トグル: ON', $operator );
		self::assertStringNotContainsString( 'トグル: OFF', $operator );
	}

	public function test_plugin_type_disabled_wording(): void {
		$dataset  = $this->dataset_with_plugin_auto( 'sample-active-plugin/sample-active-plugin.php', array( 'state' => 'type_disabled' ) );
		$operator = \WPMAR_Runner::render_operator_markup( $dataset, '', true, 0, 10 );

		self::assertStringContainsString(
			'サイト全体でプラグインの自動アップデートが無効化されているため、この設定は適用されません。',
			$operator
		);
	}

	public function test_plugin_update_source_suffix_for_non_wporg_source(): void {
		$dataset  = $this->dataset_with_plugin_auto(
			'wp-maintenance-audit-reporter/wp-maintenance-audit-reporter.php',
			array(
				'state'         => 'off',
				'update_source' => 'github.com',
			)
		);
		$operator = \WPMAR_Runner::render_operator_markup( $dataset, '', true, 0, 10 );

		self::assertStringContainsString( '（更新元: github.com）', $operator );
	}

	// -------------------------------------------------------------------------
	// テーマの文言
	// -------------------------------------------------------------------------

	public function test_theme_off_wording(): void {
		$dataset  = $this->dataset_with_theme_auto( 'sample-theme', array( 'state' => 'off' ) );
		$operator = \WPMAR_Runner::render_operator_markup( $dataset, '', true, 0, 10 );

		self::assertStringContainsString(
			'　このテーマは自動アップデートが無効です。アップデートは手動で適用する必要があります。',
			$operator
		);
	}

	// -------------------------------------------------------------------------
	// 表示位置・後方互換・クライアント境界
	// -------------------------------------------------------------------------

	/** 自動更新の行がチェックサム行より前に出ること. */
	public function test_auto_update_line_appears_before_checksum_line(): void {
		$dataset  = $this->dataset_with_plugin_auto( 'sample-mismatch-plugin/plugin.php', array( 'state' => 'on' ) );
		$operator = \WPMAR_Runner::render_operator_markup( $dataset, '', true, 0, 10 );

		$auto_pos     = strpos( $operator, 'このプラグインは自動アップデートが有効化されています。' );
		$checksum_pos = strpos( $operator, 'の以下のファイルに変更が見つかりました' );

		self::assertNotFalse( $auto_pos );
		self::assertNotFalse( $checksum_pos );
		self::assertLessThan( $checksum_pos, $auto_pos );
	}

	/**
	 * ★ `auto_updates` キーがない dataset（1.6.0 以前）では、1.6.0 と同一の出力になる
	 * （自動更新関連の行が一切出ない）こと.
	 */
	public function test_missing_auto_updates_key_is_backward_compatible(): void {
		$dataset = $this->dataset_full;
		unset( $dataset['auto_updates'] );

		$operator = \WPMAR_Runner::render_operator_markup( $dataset, '', true, 0, 10 );

		self::assertStringNotContainsString( '自動更新：', $operator );
		self::assertStringNotContainsString( '自動アップデート', $operator );
	}

	/** ★ クライアント body に自動更新の文言が一切漏れないこと. */
	public function test_auto_update_wording_never_reaches_the_client_body(): void {
		$dataset = $this->dataset_with_core_auto( array( 'state' => 'major' ) );
		$dataset = array_replace_recursive(
			$dataset,
			array(
				'auto_updates' => array(
					'plugins' => array(
						'sample-active-plugin/sample-active-plugin.php' => array( 'state' => 'on' ),
					),
					'themes'  => array(
						'sample-theme' => array( 'state' => 'on' ),
					),
				),
			)
		);

		$operator = \WPMAR_Runner::render_operator_markup( $dataset, '', true, 0, 10 );
		$client   = \WPMAR_Runner::render_client_markup( $dataset, '', 0, true );

		self::assertStringContainsString( '自動更新：', $operator );
		self::assertStringContainsString( '自動アップデートが有効化されています', $operator );

		self::assertStringNotContainsString( '自動更新：', $client );
		self::assertStringNotContainsString( '自動アップデート', $client );
	}
}
