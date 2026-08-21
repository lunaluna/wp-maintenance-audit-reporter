<?php
/**
 * Unit tests for WPMAR_Runner::render_operator_markup() (admin-facing Markdown body).
 *
 * Covers section presence for a fully-populated dataset, the domain-gate-NG
 * disclaimer, execution-duration formatting, the changelog section's zero
 * vs. non-zero wording, the `wpmar_report_sections` extensibility filter,
 * pipe-character escaping in the Markdown users table, and an information
 * boundary regression: server internals (PHP/MySQL version, WP_MEMORY_LIMIT,
 * environment type) must only ever reach the operator body, never the client
 * one.
 *
 * Note: checksum mismatch file paths are intentionally shared between the
 * operator and client bodies (both go through render_checksum_client_section()) —
 * that is existing, deliberate behaviour, not a boundary this file tests for.
 *
 * Cases already covered by tests/DirectoryVersionStatusTest.php (per-item
 * theme/plugin version-comparison wording) are not repeated here.
 *
 * render_operator_markup()'s parameter order is
 * (facts, changelog, gate, changelog_size, duration_sec) — note `gate` comes
 * BEFORE `changelog_size`, the reverse of render_client_markup()'s
 * (facts, changelog, changelog_size, gate). Mixing the two up silently passes
 * (both are scalars PHP coerces), so every call below spells out the argument
 * name in a leading comment to keep that straight.
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
 * Covers WPMAR_Runner::render_operator_markup() / render_client_markup().
 */
final class OperatorMarkupTest extends TestCase {

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
	 * @param array<string,mixed> $facts          Dataset.
	 * @param string              $changelog      Diff body.
	 * @param bool                $gate           Domain gate result.
	 * @param int                 $changelog_size Diff count.
	 * @param int                 $duration_sec   Duration in seconds.
	 * @return string
	 */
	private function render( array $facts, $changelog, $gate, $changelog_size, $duration_sec ) {
		return \WPMAR_Runner::render_operator_markup( $facts, $changelog, $gate, $changelog_size, $duration_sec );
	}

	public function test_all_expected_sections_are_present_for_a_full_dataset(): void {
		$markup = $this->render( $this->dataset_full, '* 変更あり', true, 3, 90 );

		foreach (
			array(
				'# 【WordPress 本体】',
				'# 【テーマファイル】',
				'# 【プラグインファイル】',
				'# 【サーバー関連情報】',
				'# 【ユーザー情報】',
				'## 【前回スナップショットからの差分】',
				'# 【運用・セキュリティ】',
				'# 【オプション：データベースサイズ】',
				'## 【実行時間】',
				'## 【コマンドラインツール】',
			) as $heading
		) {
			self::assertStringContainsString( $heading, $markup, "Missing expected section heading: {$heading}" );
		}
	}

	public function test_domain_gate_failure_note_appears_when_gate_is_false(): void {
		$markup = $this->render( $this->dataset_full, '', false, 0, 10 );

		self::assertStringContainsString(
			'※ ドメインゲートにより、この実行ではスナップショットは更新されていません。',
			$markup
		);
	}

	public function test_domain_gate_success_omits_the_failure_note(): void {
		$markup = $this->render( $this->dataset_full, '', true, 0, 10 );

		self::assertStringNotContainsString(
			'※ ドメインゲートにより、この実行ではスナップショットは更新されていません。',
			$markup
		);
	}

	public function test_duration_is_reflected_in_the_execution_section(): void {
		$markup = $this->render( $this->dataset_full, '', true, 0, 125 );

		self::assertStringContainsString( '2 分 5 秒', $markup );
	}

	public function test_changelog_section_shows_zero_wording_when_no_changes(): void {
		$markup = $this->render( $this->dataset_full, '', true, 0, 10 );

		self::assertStringContainsString( '件数: 0', $markup );
		self::assertStringContainsString( '差分は検出されませんでした。', $markup );
	}

	public function test_changelog_section_shows_the_diff_body_when_changes_exist(): void {
		$markup = $this->render( $this->dataset_full, '* テーマ Sample Theme を 30.0.4 -> 30.0.5 に更新', true, 1, 10 );

		self::assertStringContainsString( '件数: 1', $markup );
		self::assertStringNotContainsString( '差分は検出されませんでした。', $markup );
		self::assertStringContainsString( 'テーマ Sample Theme を 30.0.4 -> 30.0.5 に更新', $markup );
	}

	public function test_wpmar_report_sections_filter_can_append_extra_markdown(): void {
		$GLOBALS['_wpmar_test_apply_filters_functional'] = true;
		add_filter(
			'wpmar_report_sections',
			static function ( $extras, $context ) {
				self::assertSame( 'operator', $context['audience'] );
				$extras[] = array( 'markdown' => '### カスタム拡張セクション' );
				return $extras;
			}
		);

		$markup = $this->render( $this->dataset_full, '', true, 0, 10 );

		self::assertStringContainsString( '### カスタム拡張セクション', $markup );
	}

	public function test_no_registered_filter_adds_no_extra_markdown(): void {
		$markup = $this->render( $this->dataset_full, '', true, 0, 10 );

		self::assertStringNotContainsString( 'カスタム拡張セクション', $markup );
	}

	public function test_users_table_escapes_pipe_characters_in_display_name(): void {
		$dataset                             = $this->dataset_full;
		$dataset['users'][0]['display_name'] = 'サンプル|管理者';

		$markup = $this->render( $dataset, '', true, 0, 10 );

		self::assertStringContainsString( 'サンプル\\|管理者', $markup );
		// A raw unescaped pipe here would have split the Markdown table cell.
		self::assertStringNotContainsString( 'サンプル|管理者', $markup );
	}

	public function test_server_internals_are_operator_only_and_never_reach_the_client_body(): void {
		$operator_markup = $this->render( $this->dataset_full, '', true, 0, 10 );
		$client_markup    = \WPMAR_Runner::render_client_markup( $this->dataset_full, '', 0, true );

		self::assertStringContainsString( '# 【サーバー関連情報】', $operator_markup );
		self::assertStringContainsString( 'PHP バージョン: 8.4.18', $operator_markup );
		self::assertStringContainsString( 'MySQL サーバー報告バージョン: 8.4.0', $operator_markup );

		self::assertStringNotContainsString( '# 【サーバー関連情報】', $client_markup );
		self::assertStringNotContainsString( 'PHP バージョン: 8.4.18', $client_markup );
		self::assertStringNotContainsString( 'MySQL サーバー報告バージョン: 8.4.0', $client_markup );
	}
}
