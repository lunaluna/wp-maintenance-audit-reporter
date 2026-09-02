<?php
/**
 * PHPUnit coverage for the `wp wpmar audit run --network` sync confirmation gate.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Asserts which --network run() invocations require WP_CLI::confirm() before
 * falling into WPMAR_Network_Runner's single-process per-site loop.
 *
 * @coversNothing
 */
final class CliAuditSyncConfirmTest extends TestCase {

	/**
	 * Bootstraps the command class without a real WP-CLI process.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
		}
		require_once __DIR__ . '/wp-stubs.php';
		require_once dirname( __DIR__ ) . '/includes/cli/class-wpmar-cli-audit-command.php';
	}

	/**
	 * Invokes protected static helper.
	 *
	 * @param bool $network      --network flag.
	 * @param bool $async        --async flag.
	 * @param int  $target_id    --id=<blog_id> value (0 when absent).
	 * @param bool $same_setting --same-setting flag.
	 * @return bool
	 */
	private function needs_confirm( $network, $async, $target_id, $same_setting ) {
		$method = new ReflectionMethod( \WPMAR_CLI_Audit_Command::class, 'needs_sync_network_confirm' );
		$method->setAccessible( true );

		return (bool) $method->invoke( null, $network, $async, $target_id, $same_setting );
	}

	/**
	 * @return void
	 */
	public function test_true_for_plain_network_run(): void {
		self::assertTrue( $this->needs_confirm( true, false, 0, false ) );
	}

	/**
	 * @return void
	 */
	public function test_false_when_async(): void {
		self::assertFalse( $this->needs_confirm( true, true, 0, false ) );
	}

	/**
	 * @return void
	 */
	public function test_false_when_targeting_single_blog_id(): void {
		self::assertFalse( $this->needs_confirm( true, false, 3, false ) );
	}

	/**
	 * @return void
	 */
	public function test_false_when_same_setting(): void {
		self::assertFalse( $this->needs_confirm( true, false, 0, true ) );
	}

	/**
	 * @return void
	 */
	public function test_false_when_not_network(): void {
		self::assertFalse( $this->needs_confirm( false, false, 0, false ) );
	}
}
