<?php
/**
 * PHPUnit coverage for WordPress.org directory version comparison.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Asserts semver ordering used in theme/plugin report sections.
 *
 * @coversNothing
 */
final class DirectoryVersionStatusTest extends TestCase {

	/**
	 * Bootstraps runner class without WordPress.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
		}
		require_once dirname( __DIR__ ) . '/includes/class-wpmar-runner.php';
	}

	/**
	 * Invokes protected static helper.
	 *
	 * @param string $installed Installed version.
	 * @param string $latest    Directory version.
	 * @return string
	 */
	private function status( $installed, $latest ) {
		$method = new ReflectionMethod( \WPMAR_Runner::class, 'directory_version_status' );
		$method->setAccessible( true );

		return (string) $method->invoke( null, $installed, $latest );
	}

	/**
	 * @return void
	 */
	public function test_update_available_when_installed_is_older(): void {
		self::assertSame( 'update_available', $this->status( '1.3', '1.4' ) );
	}

	/**
	 * @return void
	 */
	public function test_current_when_versions_match(): void {
		self::assertSame( 'current', $this->status( '1.4', '1.4' ) );
	}

	/**
	 * @return void
	 */
	public function test_data_error_when_installed_is_newer_than_directory(): void {
		self::assertSame( 'data_error', $this->status( '1.5', '1.4' ) );
	}

	/**
	 * @return void
	 */
	public function test_unknown_when_either_version_is_empty(): void {
		self::assertSame( 'unknown', $this->status( '', '1.4' ) );
		self::assertSame( 'unknown', $this->status( '1.4', '' ) );
	}
}
