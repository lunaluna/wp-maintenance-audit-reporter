<?php
/**
 * Unit tests for WPMAR_Snapshot_Preview::current_user_can_view().
 *
 * The permission boundary for the snapshot preview section (WPMAR 1.5.3): on
 * multisite, only super admins may see it, because plugin/theme snapshots are
 * network-shared and the users snapshot carries plain-text email addresses —
 * neither of which a subsite admin's manage_options should expose. This is the
 * one guard that must not regress, so it gets its own test file per the plan.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wpmar-admin-menu.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wpmar-snapshot-preview.php';

/**
 * Exposes the protected current_user_can_view() for direct assertions
 * (same subclass pattern as ReportPreviewLinksTest.php's ExposedJobsRestLinks).
 */
final class ExposedSnapshotPreviewAccess extends \WPMAR_Snapshot_Preview {

	/**
	 * @return bool
	 */
	public static function call_current_user_can_view() {
		return self::current_user_can_view();
	}
}

/**
 * Covers WPMAR_Snapshot_Preview::current_user_can_view().
 */
final class SnapshotPreviewAccessTest extends TestCase {

	protected function tearDown(): void {
		unset(
			$GLOBALS['_wpmar_test_caps'],
			$GLOBALS['_wpmar_test_is_multisite'],
			$GLOBALS['_wpmar_test_is_super_admin']
		);
		parent::tearDown();
	}

	public function test_single_site_with_manage_options_is_allowed(): void {
		$GLOBALS['_wpmar_test_is_multisite'] = false;
		$GLOBALS['_wpmar_test_caps']         = array( 'manage_options' => true );

		self::assertTrue( ExposedSnapshotPreviewAccess::call_current_user_can_view() );
	}

	public function test_single_site_without_capability_is_denied(): void {
		$GLOBALS['_wpmar_test_is_multisite'] = false;
		$GLOBALS['_wpmar_test_caps']         = array( 'manage_options' => false );

		self::assertFalse( ExposedSnapshotPreviewAccess::call_current_user_can_view() );
	}

	public function test_multisite_subsite_admin_with_manage_options_is_denied(): void {
		// The exact scenario 3-3 exists for: a subsite admin holds manage_options
		// but must never see the network-shared plugins/themes or user emails.
		$GLOBALS['_wpmar_test_is_multisite']    = true;
		$GLOBALS['_wpmar_test_caps']            = array( 'manage_options' => true );
		$GLOBALS['_wpmar_test_is_super_admin']  = false;

		self::assertFalse( ExposedSnapshotPreviewAccess::call_current_user_can_view() );
	}

	public function test_multisite_super_admin_is_allowed(): void {
		$GLOBALS['_wpmar_test_is_multisite']   = true;
		$GLOBALS['_wpmar_test_caps']           = array( 'manage_options' => true );
		$GLOBALS['_wpmar_test_is_super_admin'] = true;

		self::assertTrue( ExposedSnapshotPreviewAccess::call_current_user_can_view() );
	}

	public function test_multisite_without_capability_or_super_admin_is_denied(): void {
		$GLOBALS['_wpmar_test_is_multisite']   = true;
		$GLOBALS['_wpmar_test_caps']           = array( 'manage_options' => false );
		$GLOBALS['_wpmar_test_is_super_admin'] = false;

		self::assertFalse( ExposedSnapshotPreviewAccess::call_current_user_can_view() );
	}
}
