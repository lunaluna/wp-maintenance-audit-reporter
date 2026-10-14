<?php
/**
 * 自動更新設定のスナップショット差分(1.7.0 Step 4)のユニットテスト.
 *
 * 次の 3 点を固定する.
 * - 収集した自動更新の状態を、スナップショット用の「キー => 文字列」マップに畳み込むこと
 * - 前回分がない(初回)ときは、この次元の差分を出さないこと(初回導入時のノイズ対策)
 * - 差分の行は管理者向けだけに出し、クライアント向けの変更履歴には漏らさないこと
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
require_once dirname( __DIR__ ) . '/includes/class-wpmar-runner.php';

/**
 * WPMAR_Runner の protected メソッドを、テストから呼べるように公開する.
 */
final class ExposedSnapshotDiffRunner extends \WPMAR_Runner {

	/**
	 * @param array<string,mixed> $facts 収集したデータセット.
	 * @return array<string,array<string,mixed>>
	 */
	public function canonical( array $facts ) {
		return $this->canonical_snapshots_from_report( $facts );
	}

	/**
	 * @param array<string,mixed> $before 前回のスナップショット.
	 * @param array<string,mixed> $fresh  今回のスナップショット.
	 * @return array{0:int,1:string,2:string}
	 */
	public function diff( array $before, array $fresh ) {
		return $this->difference_summary( $before, $fresh );
	}
}

/**
 * 自動更新のスナップショットマップと、差分の出方を検証する.
 */
final class AutoUpdateSnapshotDiffTest extends TestCase {

	/**
	 * 差分比較に必要な最小限の「前回/今回」のスナップショット(自動更新以外).
	 *
	 * @return array<string,mixed>
	 */
	private function base_snapshots(): array {
		return array(
			'core'    => array(
				'version' => '7.0.2',
				'locale'  => 'ja',
			),
			'themes'  => array( 'astra' => '4.8' ),
			'plugins' => array( 'akismet' => '5.3' ),
			'users'   => array( '1' => 'a@example.test|administrator' ),
		);
	}

	/**
	 * 収集済みの自動更新データ(必要な項目だけ).
	 *
	 * @param array<string,mixed> $override 上書きする項目(site / core / plugins / themes の単位).
	 * @return array<string,mixed>
	 */
	private function auto_updates( array $override = array() ): array {
		$base = array(
			'site'    => array(
				'site_gate_blocked'    => array(
					'core'   => false,
					'plugin' => false,
					'theme'  => false,
				),
				'plugins_type_enabled' => true,
				'themes_type_enabled'  => true,
				// has_filter() が真なだけのフック。判定にもスナップショットにも使わない.
				'filters_present'      => array( 'auto_update_plugin' ),
			),
			'core'    => array(
				'state'      => 'minor',
				'overridden' => array(
					'dev'   => false,
					'minor' => false,
					'major' => false,
				),
			),
			'plugins' => array(
				'akismet/akismet.php' => array(
					'state'      => 'on',
					'overridden' => false,
				),
				'hello.php'           => array(
					'state'      => 'off',
					'overridden' => false,
				),
			),
			'themes'  => array(
				'astra' => array(
					'state'      => 'off',
					'overridden' => false,
				),
			),
		);

		return array_replace( $base, $override );
	}

	// -------------------------------------------------------------------------------
	// canonical_snapshots_from_report() / snapshot_map()
	// -------------------------------------------------------------------------------

	/**
	 * 後方互換ガード: auto_updates キーがないデータセットでは、バケットを増やさない.
	 * 増やすと、収集に失敗した回に空のスナップショットが保存され、前回分が消える.
	 *
	 * @return void
	 */
	public function test_canonical_has_no_auto_updates_bucket_without_the_dataset_key(): void {
		$runner = new ExposedSnapshotDiffRunner();

		$pairs = $runner->canonical( array( 'core' => array( 'version' => '7.0.2' ) ) );

		self::assertSame( array( 'core', 'themes', 'plugins', 'users' ), array_keys( $pairs ) );
	}

	/**
	 * @return void
	 */
	public function test_canonical_adds_the_auto_updates_bucket_with_site_and_item_states(): void {
		$runner = new ExposedSnapshotDiffRunner();

		$pairs = $runner->canonical( array( 'auto_updates' => $this->auto_updates() ) );

		self::assertArrayHasKey( 'auto_updates', $pairs );
		self::assertSame(
			array(
				'_site'                    => 'core:minor|gate:ok|plugins:on|themes:on|overrides:none',
				'plugin:akismet/akismet.php' => 'on',
				'plugin:hello.php'         => 'off',
				'theme:astra'              => 'off',
			),
			$pairs['auto_updates']
		);
	}

	/**
	 * `overrides:` は、実際に上書き(overridden === true)が起きた種別だけを記録する.
	 * has_filter() が真なだけ(filters_present)では記録しない(無害なフィルターの追加で
	 * 差分が出るのを避ける).
	 *
	 * @return void
	 */
	public function test_site_overrides_record_only_actual_overrides_not_present_filters(): void {
		// filters_present はあるが overridden は全部 false(auto_updates() の既定).
		$none = \WPMAR_Check_Auto_Updates::snapshot_map( $this->auto_updates() );
		self::assertSame( 'core:minor|gate:ok|plugins:on|themes:on|overrides:none', $none['_site'] );

		$override                                = $this->auto_updates();
		$override['plugins']['hello.php']['overridden'] = true;
		$override['themes']['astra']['overridden']      = true;
		$override['core']['overridden']['major']        = true;
		$map = \WPMAR_Check_Auto_Updates::snapshot_map( $override );

		// 種別は固定の順(core, plugin, theme)で並ぶ.
		self::assertSame( 'core:minor|gate:ok|plugins:on|themes:on|overrides:core,plugin,theme', $map['_site'] );
	}

	/**
	 * サイトゲートは、ブロックされている種別を順に記録する(1 つに潰さない).
	 *
	 * @return void
	 */
	public function test_site_gate_lists_blocked_types(): void {
		$data                                   = $this->auto_updates();
		$data['site']['site_gate_blocked']['plugin'] = true;
		$data['site']['plugins_type_enabled']        = false;

		$map = \WPMAR_Check_Auto_Updates::snapshot_map( $data );

		self::assertSame( 'core:minor|gate:blocked(plugin)|plugins:off|themes:on|overrides:none', $map['_site'] );
	}

	/**
	 * キーに含まれる `|` と制御文字は取り除く(スナップショットのプレビューの表を壊さない).
	 *
	 * @return void
	 */
	public function test_item_keys_drop_pipes_and_control_characters(): void {
		$data            = $this->auto_updates();
		$data['plugins'] = array(
			"evil|dir/ev\nil.php" => array(
				'state'      => 'on',
				'overridden' => false,
			),
		);

		$map = \WPMAR_Check_Auto_Updates::snapshot_map( $data );

		self::assertArrayHasKey( 'plugin:evildir/evil.php', $map );
	}

	// -------------------------------------------------------------------------------
	// difference_summary()
	// -------------------------------------------------------------------------------

	/**
	 * 受け入れ基準 6: 前回の auto_updates がないとき(初回導入)に、変更履歴へノイズを出さない.
	 * 他の 4 バケットには前回分があるので $has_prior は真になるが、この次元だけをスキップする.
	 *
	 * @return void
	 */
	public function test_first_run_without_prior_auto_updates_snapshot_produces_no_diff_noise(): void {
		$runner = new ExposedSnapshotDiffRunner();

		$before = $this->base_snapshots(); // auto_updates キーなし(旧バージョンで保存されたスナップショット).
		$fresh  = $this->base_snapshots();
		$fresh['auto_updates'] = \WPMAR_Check_Auto_Updates::snapshot_map( $this->auto_updates() );

		list( $count, $operator, $client ) = $runner->diff( $before, $fresh );

		self::assertSame( 0, $count );
		self::assertStringContainsString( '差分は検出されませんでした', $operator );
		self::assertStringNotContainsString( '自動更新', $operator );
		self::assertStringContainsString( '差分は検出されませんでした', $client );
	}

	/**
	 * 前回の auto_updates が null(行なし)や空配列でも同じ.
	 *
	 * @return void
	 */
	public function test_null_or_empty_prior_auto_updates_is_skipped(): void {
		$runner = new ExposedSnapshotDiffRunner();

		$fresh                 = $this->base_snapshots();
		$fresh['auto_updates'] = \WPMAR_Check_Auto_Updates::snapshot_map( $this->auto_updates() );

		foreach ( array( null, array() ) as $prior ) {
			$before                 = $this->base_snapshots();
			$before['auto_updates'] = $prior;

			list( $count ) = $runner->diff( $before, $fresh );

			self::assertSame( 0, $count );
		}
	}

	/**
	 * 受け入れ基準 7: 2 回目以降、自動更新の設定を変えると、変更履歴に 1 行出る.
	 * 管理者向けだけに出し、クライアント向けには出さない.
	 *
	 * @return void
	 */
	public function test_state_change_appears_once_for_operator_and_never_for_client(): void {
		$runner = new ExposedSnapshotDiffRunner();

		$before                 = $this->base_snapshots();
		$before['auto_updates'] = \WPMAR_Check_Auto_Updates::snapshot_map( $this->auto_updates() );

		// hello.php が、フィルターで強制的に有効になった.
		$changed                                        = $this->auto_updates();
		$changed['plugins']['hello.php']['state']      = 'filter_forced_on';
		$changed['plugins']['hello.php']['overridden'] = true;
		$fresh                                          = $this->base_snapshots();
		$fresh['auto_updates']                          = \WPMAR_Check_Auto_Updates::snapshot_map( $changed );

		list( $count, $operator, $client ) = $runner->diff( $before, $fresh );

		// plugin:hello.php の状態変化 1 行 + _site の overrides の変化 1 行.
		self::assertSame( 2, $count );
		self::assertStringContainsString( 'hello.php', $operator );
		self::assertStringContainsString( '自動更新', $operator );
		self::assertSame( 1, substr_count( $operator, 'hello.php' ) );

		// クライアント向けには、自動更新の文言も、プラグインのパスも出ない.
		self::assertStringNotContainsString( '自動更新', $client );
		self::assertStringNotContainsString( 'hello.php', $client );
		// 本文が空にならず、「差分なし」になる(クライアント向けの表示は件数ではなく本文で崩れる).
		self::assertStringContainsString( '差分は検出されませんでした', $client );
	}

	/**
	 * 変化がなければ、管理者向け・クライアント向けとも「差分は検出されませんでした」になる.
	 *
	 * @return void
	 */
	public function test_identical_snapshots_produce_no_auto_update_lines(): void {
		$runner = new ExposedSnapshotDiffRunner();

		$map                    = \WPMAR_Check_Auto_Updates::snapshot_map( $this->auto_updates() );
		$before                 = $this->base_snapshots();
		$before['auto_updates'] = $map;
		$fresh                  = $this->base_snapshots();
		$fresh['auto_updates']  = $map;

		list( $count, $operator, $client ) = $runner->diff( $before, $fresh );

		self::assertSame( 0, $count );
		self::assertStringContainsString( '差分は検出されませんでした', $operator );
		self::assertStringContainsString( '差分は検出されませんでした', $client );
	}

	/**
	 * 新規インストール・削除は、プラグイン/テーマの在庫の差分ですでに報告される.
	 * 自動更新の差分としては重ねて数えない(同じ出来事が 2 行にならないようにする).
	 *
	 * @return void
	 */
	public function test_added_or_removed_items_are_not_double_counted(): void {
		$runner = new ExposedSnapshotDiffRunner();

		$before_data = $this->auto_updates();
		$before                 = $this->base_snapshots();
		$before['auto_updates'] = \WPMAR_Check_Auto_Updates::snapshot_map( $before_data );

		$after_data                              = $this->auto_updates();
		$after_data['plugins']['new-one/new.php'] = array(
			'state'      => 'on',
			'overridden' => false,
		);
		unset( $after_data['plugins']['hello.php'] );
		$fresh                 = $this->base_snapshots();
		$fresh['auto_updates'] = \WPMAR_Check_Auto_Updates::snapshot_map( $after_data );

		list( $count, $operator ) = $runner->diff( $before, $fresh );

		self::assertSame( 0, $count );
		self::assertStringNotContainsString( 'new-one', $operator );
	}

	/**
	 * 自動更新の差分と、既存の差分(プラグインのバージョン更新)が同時にあっても、
	 * クライアント向けは既存の差分だけを出す.
	 *
	 * @return void
	 */
	public function test_client_changelog_keeps_existing_lines_when_auto_update_also_changes(): void {
		$runner = new ExposedSnapshotDiffRunner();

		$before                 = $this->base_snapshots();
		$before['auto_updates'] = \WPMAR_Check_Auto_Updates::snapshot_map( $this->auto_updates() );

		$changed                                  = $this->auto_updates();
		$changed['themes']['astra']['state']      = 'on';
		$fresh                                    = $this->base_snapshots();
		$fresh['plugins']['akismet']              = '5.4';
		$fresh['auto_updates']                    = \WPMAR_Check_Auto_Updates::snapshot_map( $changed );

		list( $count, $operator, $client ) = $runner->diff( $before, $fresh );

		self::assertSame( 2, $count );
		self::assertStringContainsString( 'akismet', $client );
		self::assertStringNotContainsString( '自動更新', $client );
		self::assertStringNotContainsString( '差分は検出されませんでした', $client );
		self::assertStringContainsString( '自動更新', $operator );
		self::assertStringContainsString( 'astra', $operator );
	}
}
