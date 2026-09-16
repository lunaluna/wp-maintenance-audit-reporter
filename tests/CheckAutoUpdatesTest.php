<?php
/**
 * WPMAR_Check_Auto_Updates の純関数群に対する単体テスト（WordPress のブートストラップ不要）.
 *
 * プランのテストケース一覧
 * （`~/.claude/plans/wp-checksum-verifier-dazzling-meteor.md` の「テストケース」節）の番号と
 * 対応する回帰ガードには、各テストの docblock 冒頭にその番号を付している.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/checks/class-wpmar-check-auto-updates.php';

/**
 * evaluate_item()、evaluate_core()、resolve_site_gate()、normalize_offer()、
 * toggle_from_option()、update_source_from_id()、sanitize_version_token()、branch_of()、
 * core_branch_relation()、core_constant_code() を対象にする — いずれも WordPress の
 * ブートストラップなしで各分岐を直接検証する.
 */
final class CheckAutoUpdatesTest extends TestCase {

	// -------------------------------------------------------------------------
	// evaluate_item()
	// -------------------------------------------------------------------------

	/**
	 * 各テストが指定しなかった事実を既定値で埋める。これにより各テストは自分が検証したい
	 * 項目だけを指定すればよくなる.
	 *
	 * @param array<string,mixed> $overrides Facts to override.
	 * @return array<string,mixed>
	 */
	private function itemRaw( array $overrides = array() ): array {
		return array_merge(
			array(
				'key'                => 'demo/demo.php',
				'slug'               => 'demo',
				'label'              => 'Demo',
				'toggle'             => false,
				'offer_autoupdate'   => false,
				'type_enabled'       => true,
				'disable_autoupdate' => false,
				'filter_ran'         => true,
				'filter_error'       => false,
				'filter_result'      => false,
				'php_ok'             => true,
				'has_offer'          => true,
				'site_gate_blocked'  => false,
			),
			$overrides
		);
	}

	/** ① toggle=T, filter=T → on. */
	public function test_1_toggle_on_filter_true_is_on(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_item(
			$this->itemRaw(
				array(
					'toggle'        => true,
					'filter_result' => true,
				)
			)
		);
		self::assertSame( 'on', $out['state'] );
	}

	/** ② toggle=F, filter=F → off. */
	public function test_2_toggle_off_filter_false_is_off(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_item(
			$this->itemRaw(
				array(
					'toggle'        => false,
					'filter_result' => false,
				)
			)
		);
		self::assertSame( 'off', $out['state'] );
	}

	/** ③ toggle=F, filter=T → filter_forced_on (FAUC の実測パターン). */
	public function test_3_toggle_off_filter_true_is_filter_forced_on(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_item(
			$this->itemRaw(
				array(
					'toggle'        => false,
					'filter_result' => true,
				)
			)
		);
		self::assertSame( 'filter_forced_on', $out['state'] );
		self::assertTrue( $out['overridden'] );
	}

	/** ④ toggle=T, filter=F → filter_forced_off. */
	public function test_4_toggle_on_filter_false_is_filter_forced_off(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_item(
			$this->itemRaw(
				array(
					'toggle'        => true,
					'filter_result' => false,
				)
			)
		);
		self::assertSame( 'filter_forced_off', $out['state'] );
	}

	/**
	 * ⑤★ disable_autoupdate=T, toggle=T, filter=T → filter_forced_on、かつ toggle の実値(ON)が
	 * 保持されること（フィルターは disable_autoupdate より後に評価され、最終決定権を持つ —
	 * fact 1。レンダリング文言はこの toggle 値を括弧書きで併記する — Step 2 で検証）。
	 */
	public function test_5_disable_autoupdate_with_toggle_on_and_filter_true_is_filter_forced_on(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_item(
			$this->itemRaw(
				array(
					'toggle'             => true,
					'disable_autoupdate' => true,
					'filter_result'      => true,
				)
			)
		);
		self::assertSame( 'filter_forced_on', $out['state'] );
		self::assertTrue( $out['toggle'], 'toggle の実値(ON)がそのまま保持されること' );
	}

	/** ⑥ disable_autoupdate=T, filter=F → off（pre_filter=F なので overridden=F）. */
	public function test_6_disable_autoupdate_with_filter_false_is_off_without_override(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_item(
			$this->itemRaw(
				array(
					'toggle'             => true,
					'disable_autoupdate' => true,
					'filter_result'      => false,
				)
			)
		);
		self::assertSame( 'off', $out['state'] );
		self::assertFalse( $out['overridden'] );
	}

	/** ⑦★ type_enabled=F, offer_autoupdate=T, filter=T → on（type_disabled にならない）. */
	public function test_7_type_disabled_gate_does_not_block_offer_autoupdate(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_item(
			$this->itemRaw(
				array(
					'type_enabled'     => false,
					'offer_autoupdate' => true,
					'toggle'           => false,
					'filter_result'    => true,
				)
			)
		);
		self::assertSame( 'on', $out['state'] );
	}

	/**
	 * ⑧★ type_enabled=F, toggle=F, filter=T → filter_forced_on（フィルターは type ゲートを
	 * 貫通しつつ、pre_filter との差として検出される。overridden の定義は type_enabled の値に
	 * 関わらず一貫させる — ユーザー確認済み）。
	 */
	public function test_8_filter_penetrates_type_gate_and_is_reported_as_forced(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_item(
			$this->itemRaw(
				array(
					'type_enabled'     => false,
					'toggle'           => false,
					'offer_autoupdate' => false,
					'filter_result'    => true,
				)
			)
		);
		self::assertSame( 'filter_forced_on', $out['state'] );
		self::assertTrue( $out['overridden'] );
	}

	/**
	 * ⑨★ type_enabled=F, offer_autoupdate=F, toggle=T, filter 未登録（pre と同じ値を返す）
	 * → type_disabled.
	 */
	public function test_9_type_disabled_when_toggle_on_but_type_gate_off_and_filter_noop(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_item(
			$this->itemRaw(
				array(
					'type_enabled'     => false,
					'offer_autoupdate' => false,
					'toggle'           => true,
					// pre = false ( !offer_autoupdate, type_enabled=false のため toggle は無視 )。
					// 未登録フィルターは pre をそのまま返す。
					'filter_result'    => false,
				)
			)
		);
		self::assertSame( 'type_disabled', $out['state'] );
	}

	/**
	 * ⑨-b★ 同上だが toggle=F → off（"この設定は適用されません" の「設定」自体が無いため
	 * type_disabled にしない）。
	 */
	public function test_9b_off_when_toggle_also_off_and_type_gate_off(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_item(
			$this->itemRaw(
				array(
					'type_enabled'     => false,
					'offer_autoupdate' => false,
					'toggle'           => false,
					'filter_result'    => false,
				)
			)
		);
		self::assertSame( 'off', $out['state'] );
	}

	/** ⑩★ decision=T, php_ok=F → blocked_php（初版で到達不能だった分岐）. */
	public function test_10_blocked_php_reachable_when_decision_true_and_php_not_ok(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_item(
			$this->itemRaw(
				array(
					'toggle'        => true,
					'filter_result' => true,
					'php_ok'        => false,
				)
			)
		);
		self::assertSame( 'blocked_php', $out['state'] );
	}

	/** ⑪★ WP 要件未達（wp_ok=false）だが filter=T → on（WP 要件は抑止条件でない）. */
	public function test_11_wp_requirement_is_not_a_gate(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_item(
			$this->itemRaw(
				array(
					'toggle'        => true,
					'filter_result' => true,
					'requires_wp'   => '99.0',
					'wp_ok'         => false,
				)
			)
		);
		self::assertSame( 'on', $out['state'] );
	}

	/** ⑫★ filter コールバックが null を返す → decision=F（pre にフォールバックしない）. */
	public function test_12_null_filter_result_is_false_not_pre_filter(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_item(
			$this->itemRaw(
				array(
					'toggle'        => true,
					'filter_result' => null,
				)
			)
		);
		self::assertFalse( $out['decision'] );
		self::assertTrue( $out['pre_filter'] );
		self::assertTrue( $out['overridden'] );
	}

	/** ⑬★ filter が 0 / 1 / '' / 'yes' を返す → (bool) キャストどおり. */
	public function test_13_filter_result_follows_bool_cast(): void {
		$cases = array(
			0    => false,
			1    => true,
			''   => false,
			'yes' => true,
		);
		foreach ( $cases as $raw_result => $expected_decision ) {
			$out = \WPMAR_Check_Auto_Updates::evaluate_item(
				$this->itemRaw( array( 'filter_result' => $raw_result ) )
			);
			self::assertSame( $expected_decision, $out['decision'], 'filter_result=' . var_export( $raw_result, true ) );
		}
	}

	/** ⑭★ filter が Throwable を投げる → filter_error=T, decision=pre_filter, overridden=F. */
	public function test_14_filter_exception_falls_back_to_pre_filter(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_item(
			$this->itemRaw(
				array(
					'toggle'        => true,
					'filter_error'  => true,
					'filter_result' => null,
				)
			)
		);
		self::assertTrue( $out['decision'] );
		self::assertFalse( $out['overridden'] );
	}

	/** ⑮ site_gate_blocked=T → disabled_site（最優先。has_offer=F も同時に成立するケース）. */
	public function test_15_site_gate_blocked_wins_over_every_other_branch(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_item(
			$this->itemRaw(
				array(
					'site_gate_blocked' => true,
					'has_offer'         => false,
				)
			)
		);
		self::assertSame( 'disabled_site', $out['state'] );
	}

	/** ⑯★ site_gate_blocked=T（VCS ゲート由来であっても）→ disabled_site として state に反映される. */
	public function test_16_vcs_gate_reflected_as_disabled_site(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_item(
			$this->itemRaw(
				array(
					'site_gate_blocked' => true,
					'toggle'            => true,
					'filter_result'     => true,
				)
			)
		);
		self::assertSame( 'disabled_site', $out['state'] );
	}

	/** ⑰ has_offer=F → no_update_source かつ filter_ran=F. */
	public function test_17_no_offer_is_no_update_source_and_filter_not_run(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_item(
			$this->itemRaw(
				array(
					'has_offer'     => false,
					'filter_ran'    => false,
					'filter_result' => null,
				)
			)
		);
		self::assertSame( 'no_update_source', $out['state'] );
		self::assertFalse( $out['filter_ran'] );
	}

	/** ⑰-b★ filter が pre_filter と同じ値を返す → overridden=F（既知の限界）. */
	public function test_17b_filter_matching_pre_filter_is_not_detected_as_override(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_item(
			$this->itemRaw(
				array(
					'toggle'        => true,
					'filter_result' => true, // pre_filter も true になるケース。
				)
			)
		);
		self::assertFalse( $out['overridden'] );
		self::assertSame( 'on', $out['state'] );
	}

	// -------------------------------------------------------------------------
	// resolve_site_gate()
	// -------------------------------------------------------------------------

	/** ⑱ file_mod_allowed=F → updater_disabled=T, reason='file_mod'. */
	public function test_18_file_mod_not_allowed(): void {
		$out = \WPMAR_Check_Auto_Updates::resolve_site_gate(
			array(
				'file_mod_allowed' => false,
			)
		);
		self::assertTrue( $out['updater_disabled'] );
		self::assertSame( 'file_mod', $out['disabled_reason']['plugin'] );
	}

	/** ⑲ constant_disabled=T（フィルターも同じ値を通す）→ reason='constant'. */
	public function test_19_constant_disabled_reason(): void {
		$out = \WPMAR_Check_Auto_Updates::resolve_site_gate(
			array(
				'constant_disabled' => true,
				'filter_disabled'   => true,
			)
		);
		self::assertTrue( $out['updater_disabled'] );
		self::assertSame( 'constant', $out['disabled_reason']['core'] );
	}

	/** ⑳ filter_disabled=T（定数は F）→ reason='filter'. */
	public function test_20_filter_disabled_reason(): void {
		$out = \WPMAR_Check_Auto_Updates::resolve_site_gate(
			array(
				'constant_disabled' => false,
				'filter_disabled'   => true,
			)
		);
		self::assertTrue( $out['updater_disabled'] );
		self::assertSame( 'filter', $out['disabled_reason']['theme'] );
	}

	/** ⑳-b★ installing=T → updater_disabled=T, reason='installing'. */
	public function test_20b_installing_reason(): void {
		$out = \WPMAR_Check_Auto_Updates::resolve_site_gate(
			array(
				'installing' => true,
			)
		);
		self::assertTrue( $out['updater_disabled'] );
		self::assertSame( 'installing', $out['disabled_reason']['plugin'] );
	}

	/** ㉑ vcs_checkout.plugin=T のみ → updater_disabled=F だが site_gate_blocked.plugin=T. */
	public function test_21_vcs_checkout_blocks_only_its_type(): void {
		$out = \WPMAR_Check_Auto_Updates::resolve_site_gate(
			array(
				'vcs_checkout' => array(
					'core'   => false,
					'plugin' => true,
					'theme'  => false,
				),
			)
		);
		self::assertFalse( $out['updater_disabled'] );
		self::assertTrue( $out['site_gate_blocked']['plugin'] );
		self::assertSame( 'vcs', $out['disabled_reason']['plugin'] );
	}

	/** ㉑-b★ vcs_checkout.plugin=T かつ vcs_checkout.core=F → site_gate_blocked.core=F. */
	public function test_21b_vcs_checkout_does_not_bleed_into_core(): void {
		$out = \WPMAR_Check_Auto_Updates::resolve_site_gate(
			array(
				'vcs_checkout' => array(
					'core'   => false,
					'plugin' => true,
					'theme'  => false,
				),
			)
		);
		self::assertFalse( $out['site_gate_blocked']['core'] );
	}

	// -------------------------------------------------------------------------
	// normalize_offer()
	// -------------------------------------------------------------------------

	/** ㉒ stdClass 入力. */
	public function test_22_stdclass_offer_is_normalized_to_array(): void {
		$entry           = new \stdClass();
		$entry->id       = 'w.org/plugins/demo';
		$entry->autoupdate = true;

		$out = \WPMAR_Check_Auto_Updates::normalize_offer( $entry, 'plugin' );

		self::assertIsArray( $out );
		self::assertSame( 'w.org/plugins/demo', $out['id'] );
		self::assertTrue( $out['autoupdate'] );
	}

	/** ㉓★ array 入力が ㉒ と同一構造（型非対称の回帰ガード）. */
	public function test_23_array_offer_matches_stdclass_shape(): void {
		$as_array = array(
			'id'         => 'w.org/plugins/demo',
			'autoupdate' => true,
		);

		$out = \WPMAR_Check_Auto_Updates::normalize_offer( $as_array, 'theme' );

		self::assertSame( $as_array, $out );
	}

	/** ㉔ null / スカラー / 空配列 → null. */
	public function test_24_empty_or_scalar_offer_is_null(): void {
		self::assertNull( \WPMAR_Check_Auto_Updates::normalize_offer( null, 'plugin' ) );
		self::assertNull( \WPMAR_Check_Auto_Updates::normalize_offer( 'not-an-object', 'plugin' ) );
		self::assertNull( \WPMAR_Check_Auto_Updates::normalize_offer( array(), 'plugin' ) );
	}

	// -------------------------------------------------------------------------
	// toggle_from_option()
	// -------------------------------------------------------------------------

	/** ㉕ false 返却（オプション不在）→ 空配列扱い. */
	public function test_25_false_option_is_treated_as_empty_list(): void {
		self::assertFalse( \WPMAR_Check_Auto_Updates::toggle_from_option( false, 'demo/demo.php' ) );
	}

	public function test_25b_toggle_true_when_key_present_in_list(): void {
		self::assertTrue( \WPMAR_Check_Auto_Updates::toggle_from_option( array( 'demo/demo.php' ), 'demo/demo.php' ) );
	}

	// -------------------------------------------------------------------------
	// update_source_from_id()
	// -------------------------------------------------------------------------

	/** ㉖ w.org/plugins/x → w.org. */
	public function test_26_wporg_source(): void {
		self::assertSame( 'w.org', \WPMAR_Check_Auto_Updates::update_source_from_id( 'w.org/plugins/x' ) );
	}

	/** ㉗ github.com/o/r → github.com. */
	public function test_27_github_source(): void {
		self::assertSame( 'github.com', \WPMAR_Check_Auto_Updates::update_source_from_id( 'github.com/o/r' ) );
	}

	/** ㉘ 空 → 空. */
	public function test_28_empty_id_is_empty(): void {
		self::assertSame( '', \WPMAR_Check_Auto_Updates::update_source_from_id( '' ) );
	}

	/** ㉙★ 改行 / | / Markdown 記法を含む id → 除去される. */
	public function test_29_newlines_and_pipes_are_stripped(): void {
		$out = \WPMAR_Check_Auto_Updates::update_source_from_id( "evil\n|**bold**" );
		self::assertStringNotContainsString( "\n", $out );
		self::assertStringNotContainsString( '|', $out );
		self::assertStringNotContainsString( '*', $out );
	}

	/** ㉚★ 64 文字超の id → 切り詰められる. */
	public function test_30_long_id_is_truncated_to_64_chars(): void {
		$out = \WPMAR_Check_Auto_Updates::update_source_from_id( str_repeat( 'a', 100 ) );
		self::assertSame( 64, strlen( $out ) );
	}

	/** ㉚-b★ https://evil.example/x → evil.example（https にならない。スキームを先に剥がす）. */
	public function test_30b_scheme_is_stripped_before_splitting(): void {
		self::assertSame( 'evil.example', \WPMAR_Check_Auto_Updates::update_source_from_id( 'https://evil.example/x' ) );
	}

	// -------------------------------------------------------------------------
	// sanitize_version_token()
	// -------------------------------------------------------------------------

	public function test_sanitize_version_token_strips_and_truncates(): void {
		self::assertSame( '8.1.0', \WPMAR_Check_Auto_Updates::sanitize_version_token( '8.1.0' ) );
		// 改行と | は除去されるが、英数字自体は version token として妥当なので残る。
		self::assertSame( 'evil', \WPMAR_Check_Auto_Updates::sanitize_version_token( "\n|evil" ) );
		self::assertSame( 32, strlen( \WPMAR_Check_Auto_Updates::sanitize_version_token( str_repeat( '1', 100 ) ) ) );
	}

	// -------------------------------------------------------------------------
	// branch_of()
	// -------------------------------------------------------------------------

	/** ㊵ branch_of('7.2-alpha') → 7.2. */
	public function test_35_branch_of_strips_suffix(): void {
		self::assertSame( '7.2', \WPMAR_Check_Auto_Updates::branch_of( '7.2-alpha' ) );
		self::assertSame( '6.5', \WPMAR_Check_Auto_Updates::branch_of( '6.5.2' ) );
		self::assertSame( '', \WPMAR_Check_Auto_Updates::branch_of( '' ) );
	}

	// -------------------------------------------------------------------------
	// core_branch_relation()
	// -------------------------------------------------------------------------

	public function test_core_branch_relation_same_version_is_none(): void {
		self::assertSame(
			'none',
			\WPMAR_Check_Auto_Updates::core_branch_relation( '6.5.2', '6.5.2', '6.5', '6.5' )
		);
	}

	public function test_core_branch_relation_same_branch_is_same(): void {
		self::assertSame(
			'same',
			\WPMAR_Check_Auto_Updates::core_branch_relation( '6.5.1', '6.5.2', '6.5', '6.5' )
		);
	}

	public function test_core_branch_relation_new_branch_is_major(): void {
		self::assertSame(
			'major',
			\WPMAR_Check_Auto_Updates::core_branch_relation( '6.5.2', '6.6.0', '6.5', '6.6' )
		);
	}

	public function test_core_branch_relation_older_offer_is_none(): void {
		self::assertSame(
			'none',
			\WPMAR_Check_Auto_Updates::core_branch_relation( '6.6.0', '6.5.2', '6.6', '6.5' )
		);
	}

	// -------------------------------------------------------------------------
	// core_constant_code()
	// -------------------------------------------------------------------------

	public function test_core_constant_code_undefined(): void {
		self::assertSame( 'undefined', \WPMAR_Check_Auto_Updates::core_constant_code( false, null ) );
	}

	public function test_core_constant_code_all_variants(): void {
		self::assertSame( 'all', \WPMAR_Check_Auto_Updates::core_constant_code( true, true ) );
		self::assertSame( 'all', \WPMAR_Check_Auto_Updates::core_constant_code( true, 'beta' ) );
		self::assertSame( 'all', \WPMAR_Check_Auto_Updates::core_constant_code( true, 'rc' ) );
		self::assertSame( 'all', \WPMAR_Check_Auto_Updates::core_constant_code( true, 'development' ) );
		self::assertSame( 'all', \WPMAR_Check_Auto_Updates::core_constant_code( true, 'branch-development' ) );
	}

	public function test_core_constant_code_minor_and_false(): void {
		self::assertSame( 'minor', \WPMAR_Check_Auto_Updates::core_constant_code( true, 'minor' ) );
		self::assertSame( 'false', \WPMAR_Check_Auto_Updates::core_constant_code( true, false ) );
	}

	// -------------------------------------------------------------------------
	// format_core_constant_raw()
	// -------------------------------------------------------------------------

	public function test_format_core_constant_raw(): void {
		self::assertSame( '', \WPMAR_Check_Auto_Updates::format_core_constant_raw( false, null ) );
		self::assertSame( 'true', \WPMAR_Check_Auto_Updates::format_core_constant_raw( true, true ) );
		self::assertSame( 'false', \WPMAR_Check_Auto_Updates::format_core_constant_raw( true, false ) );
		self::assertSame( 'minor', \WPMAR_Check_Auto_Updates::format_core_constant_raw( true, 'minor' ) );
	}

	// -------------------------------------------------------------------------
	// evaluate_core()
	// -------------------------------------------------------------------------

	/**
	 * 各テストが指定しなかった事実を既定値で埋める.
	 *
	 * @param array<string,mixed> $overrides Facts to override.
	 * @return array<string,mixed>
	 */
	private function coreRaw( array $overrides = array() ): array {
		return array_merge(
			array(
				'site_gate_blocked' => false,
				'has_offer'         => false,
				'decision'          => false,
				'branch_relation'   => 'none',
				'php_ok'            => true,
				'mysql_ok'          => true,
				'raw'               => array(
					'options'          => array(
						'dev'   => 'enabled',
						'minor' => 'enabled',
						'major' => 'unset',
					),
					'constant'         => 'undefined',
					'critical_failure' => false,
				),
			),
			$overrides
		);
	}

	/** ㉛ WP 既定（major='unset'）→ minor（オファーなし・静的表示）. */
	public function test_31_default_options_yield_minor(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_core( $this->coreRaw() );
		self::assertSame( 'minor', $out['state'] );
	}

	/** ㉜ major='enabled' → major. */
	public function test_32_major_enabled_yields_major(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_core(
			$this->coreRaw(
				array(
					'raw' => array(
						'options'          => array(
							'dev'   => 'enabled',
							'minor' => 'enabled',
							'major' => 'enabled',
						),
						'constant'         => 'undefined',
						'critical_failure' => false,
					),
				)
			)
		);
		self::assertSame( 'major', $out['state'] );
	}

	/** ㉝ 全て非 enabled → disabled. */
	public function test_33_all_disabled_options_yield_disabled(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_core(
			$this->coreRaw(
				array(
					'raw' => array(
						'options'          => array(
							'dev'   => 'disabled',
							'minor' => 'disabled',
							'major' => 'unset',
						),
						'constant'         => 'undefined',
						'critical_failure' => false,
					),
				)
			)
		);
		self::assertSame( 'disabled', $out['state'] );
	}

	/** ㉞ constant='minor' + major='enabled' → minor（定数が優先）. */
	public function test_34_constant_minor_wins_over_option_major(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_core(
			$this->coreRaw(
				array(
					'raw' => array(
						'options'          => array(
							'dev'   => 'enabled',
							'minor' => 'enabled',
							'major' => 'enabled',
						),
						'constant'         => 'minor',
						'critical_failure' => false,
					),
				)
			)
		);
		self::assertSame( 'minor', $out['state'] );
		self::assertSame( 'constant', $out['source'] );
	}

	/** ㉟ constant='false' → disabled. */
	public function test_34b_constant_false_disables_everything(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_core(
			$this->coreRaw(
				array(
					'raw' => array(
						'options'          => array(
							'dev'   => 'enabled',
							'minor' => 'enabled',
							'major' => 'enabled',
						),
						'constant'         => 'false',
						'critical_failure' => false,
					),
				)
			)
		);
		self::assertSame( 'disabled', $out['state'] );
	}

	/**
	 * ㊱ 実際のオファーに対しフィルターが major を強制 ON（should_update_to_version() の戻り値が
	 * decision として渡ってくる想定）→ major, overridden=T, source='filter'.
	 */
	public function test_36_offer_decision_true_on_major_branch_is_forced_major(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_core(
			$this->coreRaw(
				array(
					'has_offer'       => true,
					'decision'        => true,
					'branch_relation' => 'major',
					'raw'             => array(
						'options'          => array(
							'dev'   => 'enabled',
							'minor' => 'enabled',
							'major' => 'unset', // 静的には major は無効。
						),
						'constant'         => 'undefined',
						'critical_failure' => false,
					),
				)
			)
		);
		self::assertSame( 'major', $out['state'] );
		self::assertTrue( $out['overridden'] );
		self::assertSame( 'filter', $out['source'] );
	}

	/** ㊲ critical_failure=T → blocked_failure. */
	public function test_37_critical_failure_blocks(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_core(
			$this->coreRaw(
				array(
					'raw' => array(
						'options'          => array(
							'dev'   => 'enabled',
							'minor' => 'enabled',
							'major' => 'enabled',
						),
						'constant'         => 'undefined',
						'critical_failure' => true,
					),
				)
			)
		);
		self::assertSame( 'blocked_failure', $out['state'] );
	}

	/** ㊳ オファーの php_version 未達 → blocked_requirements. */
	public function test_38_offer_php_requirement_not_met_blocks(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_core(
			$this->coreRaw(
				array(
					'has_offer'       => true,
					'decision'        => true,
					'branch_relation' => 'major',
					'php_ok'          => false,
				)
			)
		);
		self::assertSame( 'blocked_requirements', $out['state'] );
	}

	/** ㊴ site_gate_blocked=T → disabled_site（最優先）. */
	public function test_39_site_gate_blocked_wins(): void {
		$out = \WPMAR_Check_Auto_Updates::evaluate_core(
			$this->coreRaw(
				array(
					'site_gate_blocked' => true,
					'has_offer'         => true,
					'decision'          => true,
					'branch_relation'   => 'major',
				)
			)
		);
		self::assertSame( 'disabled_site', $out['state'] );
	}
}
