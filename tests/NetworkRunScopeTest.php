<?php
/**
 * PHPUnit coverage for WPMAR_Network_Admin_Menu::network_run_args() (1.5.4 Step 4).
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wpmar-network-admin-menu.php';

/**
 * Exposes the protected static network_run_args() for direct assertions.
 */
final class ExposedNetworkAdminMenuRunArgs extends \WPMAR_Network_Admin_Menu {

	/**
	 * @param string               $action 'dry_run' or 'full_run'.
	 * @param array<string,mixed>  $scope  Result of read_run_scope(); ignored for 'full_run'.
	 * @return array<string,mixed>
	 */
	public static function callNetworkRunArgs( $action, array $scope ) {
		return self::network_run_args( $action, $scope );
	}
}

/**
 * Asserts that "run now" never honours the one-shot scope radio while dry runs still do
 * (1.5.4 Step 4: the existing scope radio was narrowed to dry-run-only).
 *
 * @coversNothing
 */
final class NetworkRunScopeTest extends TestCase {

	/**
	 * #1 - dry_run carries same_setting through as before.
	 *
	 * @return void
	 */
	public function test_dry_run_keeps_same_setting(): void {
		$args = ExposedNetworkAdminMenuRunArgs::callNetworkRunArgs(
			'dry_run',
			array(
				'same_setting'   => true,
				'target_blog_id' => 0,
			)
		);

		self::assertArrayHasKey( 'same_setting', $args );
		self::assertTrue( $args['same_setting'] );
	}

	/**
	 * #2 - dry_run carries target_blog_id through as before.
	 *
	 * @return void
	 */
	public function test_dry_run_keeps_target_blog_id(): void {
		$args = ExposedNetworkAdminMenuRunArgs::callNetworkRunArgs(
			'dry_run',
			array(
				'same_setting'   => false,
				'target_blog_id' => 7,
			)
		);

		self::assertArrayHasKey( 'target_blog_id', $args );
		self::assertSame( 7, $args['target_blog_id'] );
	}

	/**
	 * #3 - full_run never includes same_setting, regardless of what the scope radio held.
	 *
	 * @return void
	 */
	public function test_full_run_excludes_same_setting(): void {
		$args = ExposedNetworkAdminMenuRunArgs::callNetworkRunArgs(
			'full_run',
			array(
				'same_setting'   => true,
				'target_blog_id' => 0,
			)
		);

		self::assertArrayNotHasKey( 'same_setting', $args );
	}

	/**
	 * #4 - full_run never includes target_blog_id, regardless of what the scope radio held.
	 *
	 * @return void
	 */
	public function test_full_run_excludes_target_blog_id(): void {
		$args = ExposedNetworkAdminMenuRunArgs::callNetworkRunArgs(
			'full_run',
			array(
				'same_setting'   => false,
				'target_blog_id' => 7,
			)
		);

		self::assertArrayNotHasKey( 'target_blog_id', $args );
	}

	/**
	 * #5 - full_run's other args (dry/triggered_by) are unaffected by the scope narrowing.
	 *
	 * @return void
	 */
	public function test_full_run_keeps_other_args(): void {
		$args = ExposedNetworkAdminMenuRunArgs::callNetworkRunArgs( 'full_run', array() );

		self::assertFalse( $args['dry'] );
		self::assertSame( 'manual_network', $args['triggered_by'] );
	}
}
