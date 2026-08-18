<?php
/**
 * Unit tests for WPMAR_GitHub_Updater.
 *
 * Covers the zipball-fallback removal (a release with no matching plugin
 * asset must report "no update available" rather than installing GitHub's
 * auto-generated zipball, whose inner directory name differs from the
 * plugin directory and would break plugin activation on install).
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-github-updater.php';

class GitHubUpdaterTest extends TestCase {

	/**
	 * @param string $name Method name.
	 * @return ReflectionMethod
	 */
	private function updater_method( $name ) {
		$method = new ReflectionMethod( \WPMAR_GitHub_Updater::class, $name );
		$method->setAccessible( true );
		return $method;
	}

	public function test_extract_zip_url_selects_the_plugin_asset_by_name() {
		$body = array(
			'assets' => array(
				array(
					'name'                 => 'wp-maintenance-audit-reporter.1.4.1.zip',
					'browser_download_url' => 'https://example.com/wp-maintenance-audit-reporter.1.4.1.zip',
				),
			),
		);

		$url = $this->updater_method( 'extract_zip_url' )->invoke( null, $body );

		$this->assertSame( 'https://example.com/wp-maintenance-audit-reporter.1.4.1.zip', $url );
	}

	public function test_extract_zip_url_returns_null_when_only_an_unrelated_asset_exists() {
		// Regression test: must not fall back to $body['zipball_url'].
		$body = array(
			'assets'      => array(
				array(
					'name'                 => 'vendor-pdf.zip',
					'browser_download_url' => 'https://example.com/vendor-pdf.zip',
				),
			),
			'zipball_url' => 'https://example.com/lunaluna-wp-maintenance-audit-reporter-abc123.zip',
		);

		$url = $this->updater_method( 'extract_zip_url' )->invoke( null, $body );

		$this->assertNull( $url );
	}

	public function test_extract_zip_url_returns_null_when_assets_are_empty() {
		$url = $this->updater_method( 'extract_zip_url' )->invoke( null, array( 'assets' => array() ) );

		$this->assertNull( $url );
	}

	public function test_extract_zip_url_returns_null_when_assets_are_missing() {
		$url = $this->updater_method( 'extract_zip_url' )->invoke( null, array() );

		$this->assertNull( $url );
	}

	public function test_extract_zip_url_selects_the_plugin_asset_regardless_of_order() {
		$body = array(
			'assets' => array(
				array(
					'name'                 => 'vendor-pdf.zip',
					'browser_download_url' => 'https://example.com/vendor-pdf.zip',
				),
				array(
					'name'                 => 'wp-maintenance-audit-reporter.1.4.1.zip',
					'browser_download_url' => 'https://example.com/wp-maintenance-audit-reporter.1.4.1.zip',
				),
				array(
					'name'                 => 'wp-maintenance-audit-reporter.1.4.1.zip.sha256',
					'browser_download_url' => 'https://example.com/wp-maintenance-audit-reporter.1.4.1.zip.sha256',
				),
			),
		);

		$url = $this->updater_method( 'extract_zip_url' )->invoke( null, $body );

		$this->assertSame( 'https://example.com/wp-maintenance-audit-reporter.1.4.1.zip', $url );
	}

	/**
	 * @dataProvider provide_tags_to_normalize
	 * @param string $tag Raw tag name.
	 */
	public function test_normalize_version_strips_a_leading_v( $tag ) {
		$version = $this->updater_method( 'normalize_version' )->invoke( null, $tag );

		$this->assertSame( '1.4.1', $version );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function provide_tags_to_normalize() {
		return array(
			'no prefix'      => array( '1.4.1' ),
			'lowercase v'    => array( 'v1.4.1' ),
			'uppercase v'    => array( 'V1.4.1' ),
		);
	}

	public function test_build_plugin_update_object_has_the_required_keys() {
		if ( ! defined( 'WPMAR_PLUGIN_BASENAME' ) ) {
			define( 'WPMAR_PLUGIN_BASENAME', 'wp-maintenance-audit-reporter/wp-maintenance-audit-reporter.php' );
		}

		$release = array(
			'version'      => '1.4.1',
			'zip_url'      => 'https://example.com/wp-maintenance-audit-reporter.1.4.1.zip',
			'body'         => 'Release notes.',
			'published_at' => '2026-08-18T00:00:00Z',
		);

		$object = $this->updater_method( 'build_plugin_update_object' )->invoke( null, $release );

		$this->assertSame( 'wp-maintenance-audit-reporter', $object->slug );
		$this->assertSame( WPMAR_PLUGIN_BASENAME, $object->plugin );
		$this->assertSame( '1.4.1', $object->new_version );
		$this->assertSame( 'https://example.com/wp-maintenance-audit-reporter.1.4.1.zip', $object->package );
	}
}
