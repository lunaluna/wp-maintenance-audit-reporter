<?php
/**
 * Unit tests for WPMAR_Snapshot_Preview::to_markdown().
 *
 * A pure function (no $wpdb, no current-blog dependency), so this is the primary
 * regression test for the table layout consumed by both the per-site system
 * status screen and the network cross-site view (WPMAR 1.5.3).
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Covers WPMAR_Snapshot_Preview::to_markdown().
 */
final class SnapshotPreviewMarkdownTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
		}

		require_once __DIR__ . '/wp-stubs.php';
		require_once dirname( __DIR__ ) . '/includes/admin/class-wpmar-snapshot-preview.php';
	}

	/**
	 * @param int                  $id      Row id.
	 * @param string               $at      captured_at, 'Y-m-d H:i:s'.
	 * @param array<string,mixed> $payload Decoded payload.
	 * @return array{id:int,captured_at:string,payload:array<string,mixed>}
	 */
	private function row( $id, $at, array $payload ) {
		return array(
			'id'          => $id,
			'captured_at' => $at,
			'payload'     => $payload,
		);
	}

	// -------------------------------------------------------------------------
	// 1. Column headings per known type
	// -------------------------------------------------------------------------

	public function test_core_uses_core_column_headings(): void {
		$md = \WPMAR_Snapshot_Preview::to_markdown(
			array( 'core' => array( $this->row( 1, '2026-06-01 00:00:00', array( 'version' => '7.1' ) ) ) )
		);

		self::assertStringContainsString( 'WordPress コア（core）', $md );
		self::assertStringContainsString( '| 項目 | 値 |', $md );
	}

	public function test_themes_uses_slug_version_column_headings(): void {
		$md = \WPMAR_Snapshot_Preview::to_markdown(
			array( 'themes' => array( $this->row( 1, '2026-06-01 00:00:00', array( 'snow-monkey' => '31.0.1' ) ) ) )
		);

		self::assertStringContainsString( 'テーマ（themes）', $md );
		self::assertStringContainsString( '| スラッグ | バージョン |', $md );
	}

	public function test_plugins_uses_slug_version_column_headings(): void {
		$md = \WPMAR_Snapshot_Preview::to_markdown(
			array( 'plugins' => array( $this->row( 1, '2026-06-01 00:00:00', array( 'akismet' => '5.7.2' ) ) ) )
		);

		self::assertStringContainsString( 'プラグイン（plugins）', $md );
		self::assertStringContainsString( '| スラッグ | バージョン |', $md );
	}

	public function test_users_uses_id_signature_column_headings(): void {
		$md = \WPMAR_Snapshot_Preview::to_markdown(
			array( 'users' => array( $this->row( 1, '2026-06-01 00:00:00', array( '2' => 'a@example.test|administrator' ) ) ) )
		);

		self::assertStringContainsString( 'ユーザー（users）', $md );
		self::assertStringContainsString( '| ユーザー ID | シグネチャ（メールアドレス｜権限） |', $md );
	}

	// -------------------------------------------------------------------------
	// 2 & 3. Generation ordering + captured_at/id in the heading
	// -------------------------------------------------------------------------

	public function test_two_generations_render_newest_first_as_latest_then_previous(): void {
		$md = \WPMAR_Snapshot_Preview::to_markdown(
			array(
				'core' => array(
					$this->row( 41, '2026-07-27 08:52:46', array( 'version' => '7.1' ) ),
					$this->row( 17, '2026-06-26 01:28:03', array( 'version' => '7.0.2' ) ),
				),
			)
		);

		$latest_pos   = strpos( $md, '### 最新 — 2026-07-27 08:52:46 UTC（id 41）' );
		$previous_pos = strpos( $md, '### 前回 — 2026-06-26 01:28:03 UTC（id 17）' );

		self::assertIsInt( $latest_pos );
		self::assertIsInt( $previous_pos );
		self::assertLessThan( $previous_pos, $latest_pos, '「最新」は「前回」より前に出る' );
	}

	// -------------------------------------------------------------------------
	// 4. Single generation
	// -------------------------------------------------------------------------

	public function test_single_generation_omits_previous_heading_and_shows_notice(): void {
		$md = \WPMAR_Snapshot_Preview::to_markdown(
			array( 'core' => array( $this->row( 41, '2026-07-27 08:52:46', array( 'version' => '7.1' ) ) ) )
		);

		self::assertStringNotContainsString( '### 前回', $md );
		self::assertStringContainsString( '前回のスナップショットはまだありません（監査の実行が 1 回のみ）。', $md );
	}

	// -------------------------------------------------------------------------
	// 5. Zero rows for one type
	// -------------------------------------------------------------------------

	public function test_type_with_zero_rows_shows_no_record_notice(): void {
		$md = \WPMAR_Snapshot_Preview::to_markdown(
			array(
				'core'    => array( $this->row( 1, '2026-06-01 00:00:00', array( 'version' => '7.1' ) ) ),
				'plugins' => array(),
			)
		);

		self::assertStringContainsString( '記録がありません。', $md );
	}

	// -------------------------------------------------------------------------
	// 6. Empty payload
	// -------------------------------------------------------------------------

	public function test_empty_payload_shows_no_items_notice(): void {
		$md = \WPMAR_Snapshot_Preview::to_markdown(
			array( 'core' => array( $this->row( 1, '2026-06-01 00:00:00', array() ) ) )
		);

		self::assertStringContainsString( '項目がありません。', $md );
	}

	// -------------------------------------------------------------------------
	// 7. Unknown type falls back to generic headings
	// -------------------------------------------------------------------------

	public function test_unknown_type_uses_generic_key_value_headings_and_shows_raw_type_name(): void {
		$md = \WPMAR_Snapshot_Preview::to_markdown(
			array( 'future_dimension' => array( $this->row( 1, '2026-06-01 00:00:00', array( 'foo' => 'bar' ) ) ) )
		);

		self::assertStringContainsString( '## future_dimension', $md );
		self::assertStringContainsString( '| キー | 値 |', $md );
	}

	// -------------------------------------------------------------------------
	// 8. All types empty
	// -------------------------------------------------------------------------

	public function test_all_types_empty_shows_single_top_level_notice_only(): void {
		$md = \WPMAR_Snapshot_Preview::to_markdown(
			array(
				'core'    => array(),
				'plugins' => array(),
			)
		);

		self::assertStringContainsString(
			'スナップショットはまだ保存されていません。監査を実行し、手動実行の場合は『スナップショットを保存する（差分比較用）』にチェックしてください。',
			$md
		);
		self::assertStringNotContainsString( '##', $md, '型ごとの節は出さない' );
	}

	public function test_empty_by_type_array_also_shows_top_level_notice(): void {
		$md = \WPMAR_Snapshot_Preview::to_markdown( array() );

		self::assertStringContainsString( 'スナップショットはまだ保存されていません。', $md );
	}

	// -------------------------------------------------------------------------
	// 9. site_label
	// -------------------------------------------------------------------------

	public function test_site_label_line_hidden_when_empty(): void {
		$md = \WPMAR_Snapshot_Preview::to_markdown(
			array( 'core' => array( $this->row( 1, '2026-06-01 00:00:00', array( 'version' => '7.1' ) ) ) ),
			array( 'site_label' => '' )
		);

		self::assertStringNotContainsString( 'サイト:', $md );
	}

	public function test_site_label_line_shown_when_present(): void {
		$md = \WPMAR_Snapshot_Preview::to_markdown(
			array( 'core' => array( $this->row( 1, '2026-06-01 00:00:00', array( 'version' => '7.1' ) ) ) ),
			array( 'site_label' => '所沢店（blog_id 3）' )
		);

		self::assertStringContainsString( 'サイト: 所沢店（blog_id 3）', $md );
	}

	// -------------------------------------------------------------------------
	// 10 & 11. Cell escaping
	// -------------------------------------------------------------------------

	public function test_pipe_in_cell_is_escaped(): void {
		$md = \WPMAR_Snapshot_Preview::to_markdown(
			array( 'users' => array( $this->row( 1, '2026-06-01 00:00:00', array( '2' => 'a@example.test|administrator' ) ) ) )
		);

		self::assertStringContainsString( 'a@example.test\\|administrator', $md );
	}

	public function test_newline_in_cell_is_collapsed_to_space(): void {
		$md = \WPMAR_Snapshot_Preview::to_markdown(
			array( 'core' => array( $this->row( 1, '2026-06-01 00:00:00', array( 'note' => "line1\nline2" ) ) ) )
		);

		self::assertStringContainsString( '| note | line1 line2 |', $md );
		self::assertStringNotContainsString( "line1\nline2", $md, '生の改行がテーブル行を割ってはいけない' );
	}

	// -------------------------------------------------------------------------
	// 12. Non-scalar cell value
	// -------------------------------------------------------------------------

	public function test_array_value_is_json_encoded(): void {
		$md = \WPMAR_Snapshot_Preview::to_markdown(
			array( 'core' => array( $this->row( 1, '2026-06-01 00:00:00', array( 'extra' => array( 'a', 'b' ) ) ) ) )
		);

		self::assertStringContainsString( '["a","b"]', $md );
	}

	// -------------------------------------------------------------------------
	// 13. No HTML in output
	// -------------------------------------------------------------------------

	public function test_output_contains_no_html_tags(): void {
		// Payload values are intentionally plain here: to_markdown() never escapes
		// its input (that's the output layer's job, via esc_html() in a later
		// step), so this only asserts that the *structure* it builds — headings,
		// bullets, table syntax — is Markdown, never HTML markup of its own.
		$md = \WPMAR_Snapshot_Preview::to_markdown(
			array(
				'core'  => array( $this->row( 1, '2026-06-01 00:00:00', array( 'version' => '7.1' ) ) ),
				'users' => array( $this->row( 2, '2026-06-01 00:00:00', array( '2' => 'a@example.test|administrator' ) ) ),
			),
			array( 'site_label' => '所沢店（blog_id 3）' )
		);

		self::assertDoesNotMatchRegularExpression( '/<[a-zA-Z!\/][^>]*>/', $md );
	}
}
