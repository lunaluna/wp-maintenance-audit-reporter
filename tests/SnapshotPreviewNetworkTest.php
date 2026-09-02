<?php
/**
 * Unit tests for the network cross-site snapshot view's pure helpers
 * (WPMAR 1.5.3, Step 4).
 *
 * switch_to_blog() itself has no precedent anywhere in this repository's test
 * suite (see tests/NetworkMultisiteTest.php's docblock), so per the plan this
 * covers only the logic reachable without a real blog switch: the blog-id
 * allow-list check, the <select> option builder, and the "table missing"
 * short-circuit. WPMAR_Network::on_blog()'s actual switching is verified only
 * against a real multisite install (plan section 6-3).
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-snapshot-repository.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wpmar-snapshot-preview.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wpmar-network-system-status-page.php';

/**
 * Exposes the protected static helpers for direct assertions (same subclass
 * pattern as ExposedSnapshotPreviewAccess in SnapshotPreviewAccessTest.php).
 */
final class ExposedNetworkSnapshotPreview extends \WPMAR_Network_System_Status_Page {

	/**
	 * @param mixed          $requested Raw `$_GET` value.
	 * @param array<int,int> $allowed   Permitted blog ids.
	 * @return int
	 */
	public static function call_sanitize_selected_blog_id( $requested, array $allowed ) {
		return self::sanitize_selected_blog_id( $requested, $allowed );
	}

	/**
	 * @param array<int,int> $allowed Permitted blog ids.
	 * @return array<int,string>
	 */
	public static function call_snapshot_site_choices( array $allowed ) {
		return self::snapshot_site_choices( $allowed );
	}
}

/**
 * Covers WPMAR_Network_System_Status_Page::sanitize_selected_blog_id() /
 * snapshot_site_choices(), and WPMAR_Snapshot_Preview::markdown_for_repository()'s
 * table-missing branch.
 */
final class SnapshotPreviewNetworkTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['_wpmar_test_sites'], $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// sanitize_selected_blog_id()
	// -------------------------------------------------------------------------

	public function test_blog_id_not_in_allow_list_is_rejected(): void {
		self::assertSame( 0, ExposedNetworkSnapshotPreview::call_sanitize_selected_blog_id( '99', array( 1, 3, 21 ) ) );
	}

	public function test_missing_blog_id_two_is_rejected(): void {
		// blog_id 2 is a real, documented gap in the alpine-dealer.local fixture
		// (never allocated) - it must never be treated as valid just because it
		// looks like a plausible id.
		self::assertSame( 0, ExposedNetworkSnapshotPreview::call_sanitize_selected_blog_id( '2', array( 1, 3, 21 ) ) );
	}

	public function test_non_numeric_negative_zero_and_empty_are_all_rejected(): void {
		$allowed = array( 1, 3, 21 );

		self::assertSame( 0, ExposedNetworkSnapshotPreview::call_sanitize_selected_blog_id( 'not-a-number', $allowed ) );
		// absint( -3 ) would be 3 (an allowed id) - sanitize_selected_blog_id() must
		// not use absint() for exactly this reason.
		self::assertSame( 0, ExposedNetworkSnapshotPreview::call_sanitize_selected_blog_id( '-3', $allowed ) );
		self::assertSame( 0, ExposedNetworkSnapshotPreview::call_sanitize_selected_blog_id( '0', $allowed ) );
		self::assertSame( 0, ExposedNetworkSnapshotPreview::call_sanitize_selected_blog_id( '', $allowed ) );
	}

	public function test_allowed_blog_id_is_returned_as_is(): void {
		self::assertSame( 3, ExposedNetworkSnapshotPreview::call_sanitize_selected_blog_id( '3', array( 1, 3, 21 ) ) );
	}

	public function test_empty_allow_list_always_rejects(): void {
		self::assertSame( 0, ExposedNetworkSnapshotPreview::call_sanitize_selected_blog_id( '1', array() ) );
	}

	// -------------------------------------------------------------------------
	// snapshot_site_choices()
	// -------------------------------------------------------------------------

	public function test_site_choices_pair_blog_id_with_site_name(): void {
		$GLOBALS['_wpmar_test_sites'] = array(
			(object) array(
				'blog_id'  => 3,
				'blogname' => '所沢店',
			),
		);

		$choices = ExposedNetworkSnapshotPreview::call_snapshot_site_choices( array( 3 ) );

		self::assertSame( array( 3 => '所沢店（blog_id 3）' ), $choices );
	}

	public function test_site_choices_falls_back_to_placeholder_when_name_unresolvable(): void {
		$GLOBALS['_wpmar_test_sites'] = array();

		$choices = ExposedNetworkSnapshotPreview::call_snapshot_site_choices( array( 21 ) );

		self::assertSame( array( 21 => 'Blog #21（blog_id 21）' ), $choices );
	}

	// -------------------------------------------------------------------------
	// WPMAR_Snapshot_Preview::markdown_for_repository() — table-missing branch
	// -------------------------------------------------------------------------

	public function test_markdown_for_repository_reports_missing_table(): void {
		$db              = new \WPMAR_Test_Fake_Wpdb();
		$db->var_returns = array( 0 ); // table_exists()'s get_var() sees no matching table.
		$GLOBALS['wpdb'] = $db;

		$repo = new \WPMAR_Snapshot_Repository();

		self::assertSame(
			'* このサイトにはスナップショットのテーブルがまだ作成されていません。',
			\WPMAR_Snapshot_Preview::markdown_for_repository( $repo )
		);
	}
}
