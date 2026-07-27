<?php
/**
 * Unit tests for WPMAR_PDF_Installer checksum verification (S-4).
 *
 * Covers the reported issue: `expected_sha256()` returned an empty string by
 * default, so `verify_checksum()` was a no-op and vendor-pdf.zip installed
 * with no integrity check unless an operator manually pinned a constant.
 *
 * Runs with process isolation because `WPMAR_PLUGIN_DIR` and (in one test)
 * `WPMAR_PDF_VENDOR_ZIP_SHA256` are PHP constants and cannot be redefined.
 * Note that PHPUnit's isolation replays constants defined by an earlier test
 * into every later isolated process in this class — so `WPMAR_PLUGIN_DIR` is
 * defined exactly once, at file scope, to a directory that outlives every
 * single test (only the `vendor-pdf.sha256` file inside it is added/removed
 * per test), and the one test that defines `WPMAR_PDF_VENDOR_ZIP_SHA256` is
 * kept last so that leak can't affect the other tests in this class.
 *
 * @package WPMAR\Tests
 *
 * @runTestsInSeparateProcesses
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

require_once __DIR__ . '/wp-stubs.php';

if ( ! defined( 'WPMAR_PLUGIN_DIR' ) ) {
	define( 'WPMAR_PLUGIN_DIR', rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-pdf-installer-checksum-test/' );
}

if ( ! is_dir( WPMAR_PLUGIN_DIR ) ) {
	mkdir( WPMAR_PLUGIN_DIR, 0777, true );
}

require_once dirname( __DIR__ ) . '/includes/admin/class-wpmar-pdf-installer.php';

class PdfInstallerChecksumTest extends TestCase {

	/** @var string */
	private $bundled_sha_path;

	protected function setUp(): void {
		parent::setUp();
		$this->bundled_sha_path = WPMAR_PLUGIN_DIR . 'vendor-pdf.sha256';
		if ( is_file( $this->bundled_sha_path ) ) {
			unlink( $this->bundled_sha_path );
		}
	}

	protected function tearDown(): void {
		if ( is_file( $this->bundled_sha_path ) ) {
			unlink( $this->bundled_sha_path );
		}
		parent::tearDown();
	}

	/**
	 * @param string $name Method name.
	 * @return ReflectionMethod
	 */
	private function installer_method( $name ) {
		$method = new ReflectionMethod( \WPMAR_PDF_Installer::class, $name );
		$method->setAccessible( true );
		return $method;
	}

	public function test_expected_sha256_is_empty_on_a_source_checkout() {
		// No WPMAR_PDF_VENDOR_ZIP_SHA256 constant, no bundled vendor-pdf.sha256
		// file: this is what `git clone` / local development looks like.
		$expected = $this->installer_method( 'expected_sha256' )->invoke( null );

		$this->assertSame( '', $expected );
	}

	public function test_expected_sha256_reads_the_bundled_release_file_by_default() {
		$sha = str_repeat( 'a1', 32 );
		file_put_contents( $this->bundled_sha_path, $sha . "\n" );

		$expected = $this->installer_method( 'expected_sha256' )->invoke( null );

		$this->assertSame( $sha, $expected );
	}

	public function test_expected_sha256_ignores_a_malformed_bundled_file() {
		file_put_contents( $this->bundled_sha_path, 'not-a-sha256' );

		$expected = $this->installer_method( 'expected_sha256' )->invoke( null );

		$this->assertSame( '', $expected );
	}

	public function test_verify_checksum_is_a_noop_without_an_expected_digest() {
		$zip = WPMAR_PLUGIN_DIR . 'fixture-noop.zip';
		file_put_contents( $zip, 'not actually a zip, contents are irrelevant here' );

		$result = $this->installer_method( 'verify_checksum' )->invoke( null, $zip );

		$this->assertTrue( $result );
		unlink( $zip );
	}

	public function test_verify_checksum_passes_when_the_digest_matches_the_bundled_file() {
		$zip = WPMAR_PLUGIN_DIR . 'fixture-match.zip';
		file_put_contents( $zip, 'vendor-pdf.zip contents' );
		file_put_contents( $this->bundled_sha_path, hash_file( 'sha256', $zip ) );

		$result = $this->installer_method( 'verify_checksum' )->invoke( null, $zip );

		$this->assertTrue( $result );
		unlink( $zip );
	}

	public function test_verify_checksum_fails_when_the_archive_was_tampered_with() {
		$zip = WPMAR_PLUGIN_DIR . 'fixture-tampered.zip';
		file_put_contents( $zip, 'vendor-pdf.zip contents' );
		file_put_contents( $this->bundled_sha_path, hash( 'sha256', 'a different payload' ) );

		$result = $this->installer_method( 'verify_checksum' )->invoke( null, $zip );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wpmar_zip_checksum', $result->get_error_code() );
		unlink( $zip );
	}

	/**
	 * Kept last in this file: WPMAR_PDF_VENDOR_ZIP_SHA256 is a constant and,
	 * once defined here, PHPUnit's process isolation carries it into every
	 * later isolated test in this class.
	 */
	public function test_expected_sha256_constant_overrides_the_bundled_file() {
		file_put_contents( $this->bundled_sha_path, str_repeat( 'a1', 32 ) );
		$override = str_repeat( 'b2', 32 );
		define( 'WPMAR_PDF_VENDOR_ZIP_SHA256', $override );

		$expected = $this->installer_method( 'expected_sha256' )->invoke( null );

		$this->assertSame( $override, $expected );
	}
}
