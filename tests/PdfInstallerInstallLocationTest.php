<?php
/**
 * Unit tests for WPMAR_PDF_Installer's install-location selection (1.5.5 Step 3).
 *
 * Covers preflight_check() and resolve_install_dir(): both must prefer the
 * external wpmar_pdf_lib_dir() location and fall back to the plugin directory
 * when wp-content isn't writable, without ever hard-failing just because the
 * preferred location isn't available.
 *
 * Each scenario runs in a fresh `php` CLI process (same technique as
 * tests/PdfLibDirResolutionTest.php and tests/PdfLibMigrationTest.php)
 * because WPMAR_PLUGIN_DIR / WP_CONTENT_DIR are constants that cannot be
 * redefined per-scenario within a single process, and because a chmod()
 * probe needs a directory nothing else in this process has already opened.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Covers WPMAR_PDF_Installer::preflight_check() / resolve_install_dir()
 * (both private; invoked via Reflection inside the standalone script).
 */
final class PdfInstallerInstallLocationTest extends TestCase {

	/** @var string[] Temp directories created by the current test, removed in tearDown(). */
	private $created_dirs = array();

	protected function tearDown(): void {
		foreach ( $this->created_dirs as $dir ) {
			// A read-only probe directory must be restored to a removable mode first.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- test cleanup only.
			@chmod( $dir, 0755 );
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
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- test cleanup only.
		@chmod( $dir, 0755 );
		foreach ( scandir( $dir ) as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir( $path ) ? $this->rrmdir( $path ) : unlink( $path );
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
	 * @return array{0:string,1:string} [plugin_dir, content_dir] absolute paths (both created, empty, writable).
	 */
	private function make_fixture_dirs() {
		$unique      = uniqid( '', true );
		$plugin_dir  = rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-pdf-install-loc-plugin-' . $unique;
		$content_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-pdf-install-loc-content-' . $unique;
		mkdir( $plugin_dir, 0755, true );
		mkdir( $content_dir, 0755, true );
		$this->created_dirs[] = $plugin_dir;
		$this->created_dirs[] = $content_dir;

		return array( $plugin_dir, $content_dir );
	}

	/**
	 * Runs $php_call (a snippet returning a JSON-encodable value into $result)
	 * against the given plugin/content directories in a standalone process.
	 *
	 * @param string $plugin_dir  Absolute path passed as WPMAR_PLUGIN_DIR.
	 * @param string $content_dir Absolute path passed as WP_CONTENT_DIR.
	 * @param string $php_call    PHP statement(s) assigning into `$result`.
	 * @return mixed Decoded JSON.
	 */
	private function run_standalone( $plugin_dir, $content_dir, $php_call ) {
		$plugin_root = dirname( __DIR__ );
		$script      = <<<PHP
<?php
define( 'ABSPATH', {$this->php_export( __DIR__ . '/fixtures/fake-root/' )} );
define( 'WPMAR_PLUGIN_DIR', {$this->php_export( rtrim( $plugin_dir, '/\\\\' ) . '/' )} );
define( 'WPMAR_VERSION', '0.0.0-test' );
define( 'WP_CONTENT_DIR', {$this->php_export( rtrim( $content_dir, '/\\\\' ) )} );
// A read-only wp-content probe makes the wp_mkdir_p() stub's mkdir() emit a
// PHP Warning to stdout (unlike the real wp_mkdir_p(), the stub doesn't
// silence it) — suppressed so it can't corrupt the JSON this script prints
// below; the standalone process's exit/JSON output is what each test asserts
// on, not PHP's own warning display.
error_reporting( 0 );
require {$this->php_export( __DIR__ . '/wp-stubs.php' )};
require {$this->php_export( $plugin_root . '/includes/admin/class-wpmar-pdf-installer.php' )};

{$php_call}

echo json_encode( \$result );
PHP;

		$script_path = tempnam( sys_get_temp_dir(), 'wpmar-pdf-install-loc-standalone-' );
		file_put_contents( $script_path, $script );

		try {
			$output = (string) shell_exec( PHP_BINARY . ' ' . escapeshellarg( $script_path ) . ' 2>&1' );
		} finally {
			unlink( $script_path );
		}

		$decoded = json_decode( $output, true );
		self::assertNotNull( $decoded, 'Standalone process must print valid JSON; got: ' . $output );

		return $decoded;
	}

	/**
	 * Reflection call used inside every generated script to reach the two
	 * private methods under test.
	 */
	private const CALL_RESOLVE = <<<'PHP'
$method = new \ReflectionMethod( \WPMAR_PDF_Installer::class, 'resolve_install_dir' );
$method->setAccessible( true );
$result = $method->invoke( null );
PHP;

	private const CALL_PREFLIGHT = <<<'PHP'
$method = new \ReflectionMethod( \WPMAR_PDF_Installer::class, 'preflight_check' );
$method->setAccessible( true );
$outcome = $method->invoke( null );
$result  = array(
	'ok'      => true !== $outcome ? false : true,
	'is_true' => true === $outcome,
	'code'    => is_wp_error( $outcome ) ? $outcome->get_error_code() : null,
);
PHP;

	// -------------------------------------------------------------------------
	// resolve_install_dir()
	// -------------------------------------------------------------------------

	public function test_resolve_install_dir_prefers_the_external_lib_directory_when_writable(): void {
		list( $plugin_dir, $content_dir ) = $this->make_fixture_dirs();

		$result = $this->run_standalone( $plugin_dir, $content_dir, self::CALL_RESOLVE );

		self::assertSame( $content_dir . '/wpmar-pdf-lib/', $result );
		self::assertDirectoryExists( $content_dir . '/wpmar-pdf-lib', 'resolve_install_dir() must create the external directory it resolves to.' );
	}

	public function test_resolve_install_dir_falls_back_to_the_plugin_directory_when_wp_content_is_not_writable(): void {
		list( $plugin_dir, $content_dir ) = $this->make_fixture_dirs();

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- simulating a read-only wp-content for this test only.
		chmod( $content_dir, 0500 );
		if ( is_writable( $content_dir ) ) {
			chmod( $content_dir, 0755 );
			self::markTestSkipped( 'Cannot simulate a non-writable directory as the current user (likely root).' );
		}

		$result = $this->run_standalone( $plugin_dir, $content_dir, self::CALL_RESOLVE );

		chmod( $content_dir, 0755 ); // Restore before tearDown()'s rrmdir().

		self::assertSame( rtrim( $plugin_dir, '/\\' ) . '/', $result );
	}

	// -------------------------------------------------------------------------
	// preflight_check()
	// -------------------------------------------------------------------------

	public function test_preflight_check_passes_when_the_external_location_is_writable(): void {
		list( $plugin_dir, $content_dir ) = $this->make_fixture_dirs();

		$result = $this->run_standalone( $plugin_dir, $content_dir, self::CALL_PREFLIGHT );

		self::assertTrue( $result['is_true'] );
	}

	public function test_preflight_check_falls_back_to_the_plugin_directory_instead_of_failing(): void {
		list( $plugin_dir, $content_dir ) = $this->make_fixture_dirs();

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- simulating a read-only wp-content for this test only.
		chmod( $content_dir, 0500 );
		if ( is_writable( $content_dir ) ) {
			chmod( $content_dir, 0755 );
			self::markTestSkipped( 'Cannot simulate a non-writable directory as the current user (likely root).' );
		}

		$result = $this->run_standalone( $plugin_dir, $content_dir, self::CALL_PREFLIGHT );

		chmod( $content_dir, 0755 );

		self::assertTrue( $result['is_true'], 'wp-content being unwritable must not fail preflight when the plugin directory itself is writable.' );
	}

	public function test_preflight_check_fails_when_neither_location_is_writable(): void {
		list( $plugin_dir, $content_dir ) = $this->make_fixture_dirs();

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- simulating read-only directories for this test only.
		chmod( $content_dir, 0500 );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- see above.
		chmod( $plugin_dir, 0500 );
		if ( is_writable( $content_dir ) || is_writable( $plugin_dir ) ) {
			chmod( $content_dir, 0755 );
			chmod( $plugin_dir, 0755 );
			self::markTestSkipped( 'Cannot simulate non-writable directories as the current user (likely root).' );
		}

		$result = $this->run_standalone( $plugin_dir, $content_dir, self::CALL_PREFLIGHT );

		chmod( $content_dir, 0755 );
		chmod( $plugin_dir, 0755 );

		self::assertFalse( $result['is_true'] );
		self::assertSame( 'wpmar_not_writable', $result['code'] );
	}
}
