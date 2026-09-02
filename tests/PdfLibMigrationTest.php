<?php
/**
 * Unit tests for WPMAR_PDF_Installer::maybe_migrate() (1.5.5 Step 2).
 *
 * Each scenario runs in a fresh `php` CLI process (same technique as
 * tests/PdfGenerationTest.php and tests/PdfLibDirResolutionTest.php) because
 * WPMAR_PLUGIN_DIR / WP_CONTENT_DIR are constants that cannot be redefined
 * per-scenario within a single process.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Covers the three no-op guards, the successful move, and rename() failure
 * leaving the existing in-plugin layout untouched.
 */
final class PdfLibMigrationTest extends TestCase {

	/** @var string[] Temp directories created by the current test, removed in tearDown(). */
	private $created_dirs = array();

	protected function tearDown(): void {
		foreach ( $this->created_dirs as $dir ) {
			$this->rrmdir( $dir );
		}
		$this->created_dirs = array();
		parent::tearDown();
	}

	/**
	 * @param string $dir Directory to remove recursively.
	 * @return void
	 */
	private function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir( $path ) && ! is_link( $path ) ? $this->rrmdir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	/**
	 * @param string $value Value to embed as a PHP literal.
	 * @return string
	 */
	private function php_export( $value ) {
		return var_export( $value, true );
	}

	/**
	 * @return array{0:string,1:string} [plugin_dir, content_dir] absolute paths (both created, empty).
	 */
	private function make_fixture_dirs() {
		$unique      = uniqid( '', true );
		$plugin_dir  = rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-pdf-lib-migration-plugin-' . $unique;
		$content_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-pdf-lib-migration-content-' . $unique;
		mkdir( $plugin_dir, 0755, true );
		mkdir( $content_dir, 0755, true );
		$this->created_dirs[] = $plugin_dir;
		$this->created_dirs[] = $content_dir;

		return array( $plugin_dir, $content_dir );
	}

	/**
	 * Runs WPMAR_PDF_Installer::maybe_migrate() in a standalone process against
	 * the given plugin/content directories, then reports the resulting layout.
	 *
	 * @param string $plugin_dir  Absolute path passed as WPMAR_PLUGIN_DIR.
	 * @param string $content_dir Absolute path passed as WP_CONTENT_DIR.
	 * @return array<string,mixed>
	 */
	private function run_migration( $plugin_dir, $content_dir ) {
		$plugin_root = dirname( __DIR__ );
		$lib_dir     = rtrim( $content_dir, '/\\' ) . '/wpmar-pdf-lib';
		$script      = <<<PHP
<?php
define( 'ABSPATH', {$this->php_export( __DIR__ . '/fixtures/fake-root/' )} );
define( 'WPMAR_PLUGIN_DIR', {$this->php_export( rtrim( $plugin_dir, '/\\\\' ) . '/' )} );
define( 'WP_CONTENT_DIR', {$this->php_export( rtrim( $content_dir, '/\\\\' ) )} );
// A failed rename() logs via error_log(); redirected away from stdout/stderr
// so it can't corrupt the JSON this script prints below (shell_exec() below
// captures both streams together, and PHPUnit's own reporting is disabled
// here, so it would otherwise land right in the middle of the JSON output).
ini_set( 'error_log', {$this->php_export( sys_get_temp_dir() . '/wpmar-pdf-lib-migration-test-error.log' )} );
require {$this->php_export( __DIR__ . '/wp-stubs.php' )};
require {$this->php_export( $plugin_root . '/includes/admin/class-wpmar-pdf-installer.php' )};

\WPMAR_PDF_Installer::maybe_migrate();

echo json_encode(
	array(
		'plugin_vendor_exists'   => is_dir( {$this->php_export( rtrim( $plugin_dir, '/\\\\' ) . '/vendor' )} ),
		'plugin_fonts_exists'    => is_dir( {$this->php_export( rtrim( $plugin_dir, '/\\\\' ) . '/fonts' )} ),
		'external_vendor_exists' => is_dir( {$this->php_export( $lib_dir . '/vendor' )} ),
		'external_fonts_exists'  => is_dir( {$this->php_export( $lib_dir . '/fonts' )} ),
		'external_index_exists'  => is_file( {$this->php_export( $lib_dir . '/index.php' )} ),
	)
);
PHP;

		$output_path = tempnam( sys_get_temp_dir(), 'wpmar-pdf-lib-migration-standalone-' );
		file_put_contents( $output_path, $script );

		try {
			$output = (string) shell_exec( PHP_BINARY . ' ' . escapeshellarg( $output_path ) . ' 2>&1' );
		} finally {
			unlink( $output_path );
		}

		$decoded = json_decode( $output, true );
		self::assertIsArray( $decoded, 'Standalone process must print valid JSON; got: ' . $output );

		return $decoded;
	}

	// -------------------------------------------------------------------------
	// Guard 1 — already migrated.
	// -------------------------------------------------------------------------

	public function test_does_nothing_when_the_external_vendor_directory_already_exists(): void {
		list( $plugin_dir, $content_dir ) = $this->make_fixture_dirs();

		mkdir( $plugin_dir . '/vendor', 0755, true );
		touch( $plugin_dir . '/vendor/MARKER.txt' );
		mkdir( $content_dir . '/wpmar-pdf-lib/vendor', 0755, true );

		$result = $this->run_migration( $plugin_dir, $content_dir );

		self::assertTrue( $result['plugin_vendor_exists'], 'Guard must leave the in-plugin vendor/ untouched once external vendor/ already exists.' );
		self::assertTrue( $result['external_vendor_exists'] );
		self::assertFileExists( $plugin_dir . '/vendor/MARKER.txt', 'No move must be attempted at all — the pre-existing in-plugin file must survive.' );
	}

	// -------------------------------------------------------------------------
	// Guard 2 — development checkout (composer.json present).
	// -------------------------------------------------------------------------

	public function test_does_nothing_for_a_development_checkout_with_a_composer_json(): void {
		list( $plugin_dir, $content_dir ) = $this->make_fixture_dirs();

		mkdir( $plugin_dir . '/vendor', 0755, true );
		touch( $plugin_dir . '/composer.json' );

		$result = $this->run_migration( $plugin_dir, $content_dir );

		self::assertTrue( $result['plugin_vendor_exists'] );
		self::assertFalse( $result['external_vendor_exists'], 'A development checkout (composer.json present) must never have its vendor/ migrated.' );
	}

	// -------------------------------------------------------------------------
	// Guard 3 — nothing installed yet.
	// -------------------------------------------------------------------------

	public function test_does_nothing_when_the_plugin_has_no_vendor_directory(): void {
		list( $plugin_dir, $content_dir ) = $this->make_fixture_dirs();

		$result = $this->run_migration( $plugin_dir, $content_dir );

		self::assertFalse( $result['plugin_vendor_exists'] );
		self::assertFalse( $result['external_vendor_exists'] );
	}

	// -------------------------------------------------------------------------
	// Successful move.
	// -------------------------------------------------------------------------

	public function test_migrates_vendor_and_fonts_to_the_external_directory_and_writes_an_index_guard(): void {
		list( $plugin_dir, $content_dir ) = $this->make_fixture_dirs();

		mkdir( $plugin_dir . '/vendor', 0755, true );
		touch( $plugin_dir . '/vendor/autoload.php' );
		mkdir( $plugin_dir . '/fonts', 0755, true );
		touch( $plugin_dir . '/fonts/NotoSansJP-Regular.ttf' );

		$result = $this->run_migration( $plugin_dir, $content_dir );

		self::assertFalse( $result['plugin_vendor_exists'], 'vendor/ must be moved out of the plugin directory.' );
		self::assertFalse( $result['plugin_fonts_exists'], 'fonts/ must be moved out of the plugin directory.' );
		self::assertTrue( $result['external_vendor_exists'] );
		self::assertTrue( $result['external_fonts_exists'] );
		self::assertTrue( $result['external_index_exists'], 'A directory-listing guard index.php must be written into the new external directory.' );
		self::assertFileExists( $content_dir . '/wpmar-pdf-lib/vendor/autoload.php', 'The move must preserve file contents, not just directory names.' );
		self::assertFileExists( $content_dir . '/wpmar-pdf-lib/fonts/NotoSansJP-Regular.ttf' );
	}

	public function test_migrates_vendor_even_when_the_plugin_has_no_fonts_directory(): void {
		list( $plugin_dir, $content_dir ) = $this->make_fixture_dirs();

		mkdir( $plugin_dir . '/vendor', 0755, true );

		$result = $this->run_migration( $plugin_dir, $content_dir );

		self::assertFalse( $result['plugin_vendor_exists'] );
		self::assertTrue( $result['external_vendor_exists'] );
		self::assertFalse( $result['external_fonts_exists'], 'There was no fonts/ to migrate — none must be invented.' );
	}

	// -------------------------------------------------------------------------
	// rename() failure must not corrupt the existing layout.
	// -------------------------------------------------------------------------

	public function test_leaves_the_in_plugin_vendor_directory_intact_when_rename_fails(): void {
		list( $plugin_dir, $content_dir ) = $this->make_fixture_dirs();

		mkdir( $plugin_dir . '/vendor', 0755, true );
		touch( $plugin_dir . '/vendor/autoload.php' );
		mkdir( $plugin_dir . '/fonts', 0755, true );

		// Forces rename() to fail: renaming a directory onto an existing regular
		// file (not a directory) is rejected by the filesystem. is_dir() on this
		// path is false, so guard 1 ("already migrated") does not short-circuit —
		// the code reaches move_bundle/rename and fails there, exactly like a
		// real-world rename() failure (cross-filesystem wp-content, open_basedir).
		mkdir( $content_dir . '/wpmar-pdf-lib', 0755, true );
		touch( $content_dir . '/wpmar-pdf-lib/vendor' );

		$result = $this->run_migration( $plugin_dir, $content_dir );

		self::assertTrue( $result['plugin_vendor_exists'], 'A failed rename() must leave the in-plugin vendor/ exactly as it was.' );
		self::assertTrue( $result['plugin_fonts_exists'], 'fonts/ must not be touched when the vendor/ move already failed.' );
		self::assertFileExists( $plugin_dir . '/vendor/autoload.php' );
	}
}
