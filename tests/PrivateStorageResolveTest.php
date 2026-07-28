<?php
/**
 * Unit tests for WPMAR_Private_Storage (S-1: unauthenticated report/PDF/log disclosure).
 *
 * Covers base-directory resolution (configured dir, multisite `site-{id}/` split,
 * write fallback + notice flag), `private:` / legacy path resolution (including
 * traversal and symlink-escape rejection), and token generation.
 *
 * Runs with process isolation because `WP_CONTENT_DIR` / `WPMAR_PRIVATE_STORAGE_DIR`
 * are PHP constants. Note that PHPUnit's isolation replays constants defined by an
 * earlier test into every later isolated process in this class, so both are defined
 * exactly once, at file scope, to directories that persist for the whole class —
 * each test resets that directory's permissions/contents in setUp()/tearDown()
 * instead of pointing the constant somewhere new.
 *
 * @package WPMAR\Tests
 *
 * @runTestsInSeparateProcesses
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

require_once __DIR__ . '/wp-stubs.php';

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-private-storage-test-content' );
}

if ( ! defined( 'WPMAR_PRIVATE_STORAGE_DIR' ) ) {
	define( 'WPMAR_PRIVATE_STORAGE_DIR', rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-private-storage-test-configured' );
}

require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-md-writer.php';
require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-private-storage.php';

class PrivateStorageResolveTest extends TestCase {

	/** @var string */
	private $uploads_base;

	protected function setUp(): void {
		parent::setUp();

		$this->rrmdir( WPMAR_PRIVATE_STORAGE_DIR );
		mkdir( WPMAR_PRIVATE_STORAGE_DIR, 0755, true );

		$this->uploads_base = rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-pstest-uploads-' . uniqid( '', true );
		mkdir( $this->uploads_base, 0777, true );

		$GLOBALS['_wpmar_test_upload_basedir']  = $this->uploads_base;
		$GLOBALS['_wpmar_test_options']         = array();
		$GLOBALS['_wpmar_test_is_multisite']    = false;
		$GLOBALS['_wpmar_test_current_blog_id'] = 1;
	}

	protected function tearDown(): void {
		// Some tests chmod this read-only; always restore before the next test/cleanup.
		chmod( WPMAR_PRIVATE_STORAGE_DIR, 0755 );
		$this->rrmdir( WPMAR_PRIVATE_STORAGE_DIR );
		$this->rrmdir( WP_CONTENT_DIR . '/wpmar-private' );
		$this->rrmdir( $this->uploads_base );
		unset(
			$GLOBALS['_wpmar_test_upload_basedir'],
			$GLOBALS['_wpmar_test_options'],
			$GLOBALS['_wpmar_test_is_multisite'],
			$GLOBALS['_wpmar_test_current_blog_id']
		);
		parent::tearDown();
	}

	private function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- test cleanup only.
		@chmod( $dir, 0755 );
		foreach ( scandir( $dir ) as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	public function test_base_dir_uses_configured_directory_and_seeds_protection_files() {
		$base = \WPMAR_Private_Storage::base_dir();

		$this->assertIsString( $base );
		$this->assertStringStartsWith( rtrim( WPMAR_PRIVATE_STORAGE_DIR, '/\\' ), rtrim( $base, '/\\' ) );
		$this->assertFileExists( $base . '.htaccess' );
		$this->assertFileExists( $base . 'index.php' );
		$this->assertFalse( \WPMAR_Private_Storage::is_fallback_active() );
	}

	public function test_base_dir_splits_by_site_id_on_multisite() {
		$GLOBALS['_wpmar_test_is_multisite']    = true;
		$GLOBALS['_wpmar_test_current_blog_id'] = 7;

		$base = \WPMAR_Private_Storage::base_dir();

		$this->assertStringContainsString( 'site-7', $base );
	}

	public function test_base_dir_falls_back_to_uploads_when_configured_dir_is_not_writable() {
		chmod( WPMAR_PRIVATE_STORAGE_DIR, 0500 ); // read + execute only, no write.

		// Under some CI/dev environments running as root, chmod is not enforced;
		// skip rather than assert a false negative.
		if ( is_writable( WPMAR_PRIVATE_STORAGE_DIR ) ) {
			$this->markTestSkipped( 'Cannot simulate a non-writable directory as the current user (likely root).' );
		}

		$base = \WPMAR_Private_Storage::base_dir();

		$this->assertStringStartsWith( rtrim( $this->uploads_base, '/\\' ) . '/wpmar', rtrim( $base, '/\\' ) );
		$this->assertTrue( \WPMAR_Private_Storage::is_fallback_active() );
	}

	public function test_reports_pdf_logs_tmp_dirs_are_created_and_protected() {
		foreach ( array( 'reports_dir', 'pdf_dir', 'logs_dir', 'tmp_dir' ) as $method ) {
			$dir = \WPMAR_Private_Storage::$method();
			$this->assertIsString( $dir, "$method() should return a string" );
			$this->assertDirectoryExists( $dir );
			$this->assertFileExists( $dir . '.htaccess', "$method() should seed .htaccess" );
			$this->assertFileExists( $dir . 'index.php', "$method() should seed index.php" );
		}
	}

	public function test_generate_token_is_20_alphanumeric_characters() {
		$token = \WPMAR_Private_Storage::generate_token();

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{20}$/', $token );
	}

	public function test_relative_for_storage_round_trips_through_resolve() {
		$dir  = \WPMAR_Private_Storage::reports_dir();
		$file = $dir . 'wpmar-report-example-' . \WPMAR_Private_Storage::generate_token() . '.md';
		file_put_contents( $file, 'body' );

		$stored = \WPMAR_Private_Storage::relative_for_storage( $file );

		$this->assertStringStartsWith( 'private:reports/', $stored );
		$this->assertSame( $file, \WPMAR_Private_Storage::resolve( $stored ) );
	}

	public function test_resolve_falls_back_to_default_location_for_a_private_path_missing_from_the_configured_dir() {
		// Simulates a file written before WPMAR_PRIVATE_STORAGE_DIR was defined/changed:
		// it physically lives under the plugin's hard-coded default location, while
		// base_dir() now resolves to the (different) configured directory.
		$default_dir = WP_CONTENT_DIR . '/wpmar-private/reports/';
		mkdir( $default_dir, 0777, true );
		$file = $default_dir . 'wpmar-report-example-TOKEN000000000001.md';
		file_put_contents( $file, 'body written under the previous default location' );

		$resolved = \WPMAR_Private_Storage::resolve( 'private:reports/wpmar-report-example-TOKEN000000000001.md' );

		$this->assertSame( wp_normalize_path( $file ), $resolved );
	}

	public function test_resolve_falls_back_to_uploads_fallback_location_for_a_private_path_written_during_a_fallback_episode() {
		// Simulates a file written by the uploads/ write fallback while the configured
		// directory was temporarily unwritable: the DB value already carries the
		// current `private:` prefix, but the file sits under wp_upload_dir() instead.
		$fallback_dir = $this->uploads_base . '/wpmar/pdf/';
		mkdir( $fallback_dir, 0777, true );
		$file = $fallback_dir . 'wpmar-report-example-TOKEN000000000002.pdf';
		file_put_contents( $file, 'pdf body written during a fallback episode' );

		$resolved = \WPMAR_Private_Storage::resolve( 'private:pdf/wpmar-report-example-TOKEN000000000002.pdf' );

		$this->assertSame( wp_normalize_path( $file ), $resolved );
	}

	public function test_resolve_prefers_the_configured_directory_over_the_fallback_locations() {
		$relative     = 'reports/wpmar-report-example-TOKEN000000000003.md';
		$current_file = \WPMAR_Private_Storage::reports_dir() . basename( $relative );
		file_put_contents( $current_file, 'current body' );

		$default_dir = WP_CONTENT_DIR . '/wpmar-private/reports/';
		mkdir( $default_dir, 0777, true );
		file_put_contents( $default_dir . basename( $relative ), 'stale body from the default location' );

		$resolved = \WPMAR_Private_Storage::resolve( 'private:' . $relative );

		$this->assertSame( wp_normalize_path( $current_file ), $resolved );
	}

	public function test_resolve_rejects_traversal_in_private_prefixed_path() {
		$this->assertSame( '', \WPMAR_Private_Storage::resolve( 'private:reports/../../etc/passwd' ) );
	}

	public function test_resolve_rejects_traversal_in_legacy_path() {
		$this->assertSame( '', \WPMAR_Private_Storage::resolve( 'wpmar/../../../etc/passwd' ) );
	}

	public function test_resolve_returns_empty_string_for_empty_input() {
		$this->assertSame( '', \WPMAR_Private_Storage::resolve( '' ) );
	}

	public function test_resolve_supports_legacy_upload_relative_format() {
		$legacy_dir = $this->uploads_base . '/wpmar';
		mkdir( $legacy_dir, 0777, true );
		$legacy_file = $legacy_dir . '/wpmar-report-example.md';
		file_put_contents( $legacy_file, 'legacy body' );

		$resolved = \WPMAR_Private_Storage::resolve( 'wpmar/wpmar-report-example.md' );

		$this->assertSame( wp_normalize_path( $legacy_file ), $resolved );
	}

	public function test_resolve_rejects_symlink_escaping_the_base_directory() {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() unavailable in this environment.' );
		}

		$outside_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-pstest-outside-' . uniqid( '', true );
		mkdir( $outside_dir, 0777, true );
		$secret = $outside_dir . '/secret.txt';
		file_put_contents( $secret, 'top secret' );

		$reports_dir = \WPMAR_Private_Storage::reports_dir();
		$link        = $reports_dir . 'escape-link.md';

		if ( ! @symlink( $secret, $link ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$this->rrmdir( $outside_dir );
			$this->markTestSkipped( 'symlink() failed in this environment (e.g. no permission).' );
		}

		$resolved = \WPMAR_Private_Storage::resolve( 'private:reports/escape-link.md' );

		$this->assertSame( '', $resolved );

		unlink( $link );
		$this->rrmdir( $outside_dir );
	}

	public function test_delete_removes_the_resolved_file() {
		$dir  = \WPMAR_Private_Storage::reports_dir();
		$file = $dir . 'to-delete-' . \WPMAR_Private_Storage::generate_token() . '.md';
		file_put_contents( $file, 'body' );
		$stored = \WPMAR_Private_Storage::relative_for_storage( $file );

		\WPMAR_Private_Storage::delete( $stored );

		$this->assertFileDoesNotExist( $file );
	}

	public function test_delete_is_a_noop_for_an_unresolvable_path() {
		// Must not throw/warn for a value that fails resolution entirely.
		\WPMAR_Private_Storage::delete( '../../etc/passwd' );
		$this->addToAssertionCount( 1 );
	}
}
