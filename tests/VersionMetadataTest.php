<?php
/**
 * Guards release metadata consistency used by the release workflow.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Ensures version values stay aligned across release-critical files.
 *
 * @coversNothing
 */
final class VersionMetadataTest extends TestCase {

	/**
	 * Repository root path.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname( __DIR__ );
	}

	/**
	 * Extracts the plugin header Version value.
	 *
	 * @return string
	 */
	private function pluginHeaderVersion(): string {
		$contents = file_get_contents( $this->root() . '/wp-maintenance-audit-reporter.php' );

		$this->assertIsString( $contents );
		$this->assertSame( 1, preg_match( '/^[[:space:]]*\\*[[:space:]]*Version:[[:space:]]*(.+)$/m', $contents, $matches ) );

		return trim( $matches[1] );
	}

	/**
	 * Extracts the WPMAR_VERSION constant value.
	 *
	 * @return string
	 */
	private function pluginConstantVersion(): string {
		$contents = file_get_contents( $this->root() . '/wp-maintenance-audit-reporter.php' );

		$this->assertIsString( $contents );
		$this->assertSame( 1, preg_match( "/define\\([[:space:]]*'WPMAR_VERSION'[[:space:]]*,[[:space:]]*'([^']+)'[[:space:]]*\\)/", $contents, $matches ) );

		return trim( $matches[1] );
	}

	/**
	 * Verifies release metadata versions remain in sync.
	 *
	 * @return void
	 */
	public function test_release_versions_stay_in_sync(): void {
		$composer = json_decode( file_get_contents( $this->root() . '/composer.json' ), true );

		$this->assertIsArray( $composer );
		$this->assertArrayHasKey( 'version', $composer );
		$this->assertSame( $this->pluginConstantVersion(), $this->pluginHeaderVersion() );
		$this->assertSame( $this->pluginHeaderVersion(), $composer['version'] );
	}
}
