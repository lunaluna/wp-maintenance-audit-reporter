<?php
/**
 * Unit tests for uninstall.php's PDF library cleanup (1.5.5 Step 4).
 *
 * Runs in a standalone `php` CLI process (same technique as
 * tests/PdfLibMigrationTest.php) with the trailing execution trigger
 * (`if ( is_multisite() ) { ... } else { ... }` at the bottom of uninstall.php)
 * stripped out: that block runs the full table/option/upload cleanup, which
 * needs $wpdb / get_sites() stubs this suite doesn't provide and which isn't
 * what these tests are about — only wpmar_uninstall_delete_pdf_lib() itself
 * (called once, unconditionally, at the very end of the real file) is under
 * test here.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Covers wpmar_uninstall_delete_pdf_lib() / wpmar_uninstall_pdf_lib_dir().
 */
final class UninstallPdfLibTest extends TestCase {

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
	 * uninstall.php with its trailing multisite/single-site execution trigger
	 * stripped off — everything up to (and including) the last function
	 * definition survives unchanged.
	 *
	 * @return string
	 */
	private function uninstall_functions_only_source() {
		$source   = file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
		$stripped = preg_replace( '/\nif \( is_multisite\(\) \) \{.*$/s', '', (string) $source );

		self::assertNotSame( $source, $stripped, 'The trailing execution trigger in uninstall.php was not found — has its shape changed?' );

		return $stripped;
	}

	/**
	 * @param string      $content_dir Absolute path passed as WP_CONTENT_DIR.
	 * @param string|null $filter_body PHP source for an add_filter() callback body
	 *                                 returning the wpmar_pdf_lib_delete_on_uninstall
	 *                                 value, or null to leave the default (true) in effect.
	 * @return array<string,mixed>
	 */
	private function run_uninstall_pdf_lib_delete( $content_dir, $filter_body = null ) {
		$functions = $this->uninstall_functions_only_source();
		// Strip the opening `<?php` tag: it's being spliced into a larger script below.
		$functions = preg_replace( '/^<\?php/', '', $functions, 1 );

		$filter_registration = null !== $filter_body
			? "add_filter( 'wpmar_pdf_lib_delete_on_uninstall', function () { {$filter_body} } );"
			: '';

		$script = <<<PHP
<?php
define( 'WP_UNINSTALL_PLUGIN', true );
define( 'ABSPATH', {$this->php_export( __DIR__ . '/fixtures/fake-root/' )} );
define( 'WP_CONTENT_DIR', {$this->php_export( rtrim( $content_dir, '/\\\\' ) )} );
require {$this->php_export( __DIR__ . '/wp-stubs.php' )};

\$GLOBALS['_wpmar_test_apply_filters_functional'] = true;
\$GLOBALS['_wpmar_test_filters']                  = array();

{$filter_registration}

{$functions}

wpmar_uninstall_delete_pdf_lib();

echo json_encode(
	array(
		'lib_dir_exists' => is_dir( {$this->php_export( rtrim( $content_dir, '/\\\\' ) . '/wpmar-pdf-lib' )} ),
	)
);
PHP;

		$script_path = tempnam( sys_get_temp_dir(), 'wpmar-uninstall-pdf-lib-standalone-' );
		file_put_contents( $script_path, $script );

		try {
			$output = (string) shell_exec( PHP_BINARY . ' ' . escapeshellarg( $script_path ) . ' 2>&1' );
		} finally {
			unlink( $script_path );
		}

		$decoded = json_decode( $output, true );
		self::assertIsArray( $decoded, 'Standalone process must print valid JSON; got: ' . $output );

		return $decoded;
	}

	/**
	 * @return string Absolute path to a fresh, empty WP_CONTENT_DIR fixture.
	 */
	private function make_content_dir() {
		$content_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-uninstall-pdf-lib-content-' . uniqid( '', true );
		mkdir( $content_dir, 0755, true );
		$this->created_dirs[] = $content_dir;

		return $content_dir;
	}

	public function test_deletes_the_external_pdf_lib_directory_by_default(): void {
		$content_dir = $this->make_content_dir();
		mkdir( $content_dir . '/wpmar-pdf-lib/vendor', 0755, true );
		touch( $content_dir . '/wpmar-pdf-lib/vendor/autoload.php' );

		$result = $this->run_uninstall_pdf_lib_delete( $content_dir );

		self::assertFalse( $result['lib_dir_exists'], 'The default (no filter registered) must delete the library directory.' );
	}

	public function test_does_nothing_when_the_directory_does_not_exist(): void {
		$content_dir = $this->make_content_dir();

		$result = $this->run_uninstall_pdf_lib_delete( $content_dir );

		self::assertFalse( $result['lib_dir_exists'] );
	}

	public function test_does_not_delete_when_the_delete_on_uninstall_filter_returns_false(): void {
		$content_dir = $this->make_content_dir();
		mkdir( $content_dir . '/wpmar-pdf-lib/vendor', 0755, true );

		$result = $this->run_uninstall_pdf_lib_delete( $content_dir, 'return false;' );

		self::assertTrue( $result['lib_dir_exists'], 'A filter returning false must keep the library directory intact.' );
	}

	public function test_respects_the_wpmar_pdf_lib_dir_filter_when_resolving_what_to_delete(): void {
		$content_dir = $this->make_content_dir();
		$relocated   = $content_dir . '/custom-pdf-lib';
		mkdir( $relocated . '/vendor', 0755, true );
		// The default location must survive untouched — only the filtered path is targeted.
		mkdir( $content_dir . '/wpmar-pdf-lib/vendor', 0755, true );

		$functions = $this->uninstall_functions_only_source();
		$functions = preg_replace( '/^<\?php/', '', $functions, 1 );

		$script = <<<PHP
<?php
define( 'WP_UNINSTALL_PLUGIN', true );
define( 'ABSPATH', {$this->php_export( __DIR__ . '/fixtures/fake-root/' )} );
define( 'WP_CONTENT_DIR', {$this->php_export( rtrim( $content_dir, '/\\\\' ) )} );
require {$this->php_export( __DIR__ . '/wp-stubs.php' )};

\$GLOBALS['_wpmar_test_apply_filters_functional'] = true;
\$GLOBALS['_wpmar_test_filters']                  = array();

add_filter( 'wpmar_pdf_lib_dir', function () {
	return {$this->php_export( $relocated . '/' )};
} );

{$functions}

wpmar_uninstall_delete_pdf_lib();

echo json_encode(
	array(
		'relocated_exists' => is_dir( {$this->php_export( $relocated )} ),
		'default_exists'   => is_dir( {$this->php_export( $content_dir . '/wpmar-pdf-lib' )} ),
	)
);
PHP;

		$script_path = tempnam( sys_get_temp_dir(), 'wpmar-uninstall-pdf-lib-filter-standalone-' );
		file_put_contents( $script_path, $script );

		try {
			$output = (string) shell_exec( PHP_BINARY . ' ' . escapeshellarg( $script_path ) . ' 2>&1' );
		} finally {
			unlink( $script_path );
		}

		$decoded = json_decode( $output, true );
		self::assertIsArray( $decoded, 'Standalone process must print valid JSON; got: ' . $output );

		self::assertFalse( $decoded['relocated_exists'], 'The filtered (relocated) directory must be the one deleted.' );
		self::assertTrue( $decoded['default_exists'], 'The default-location directory must be left untouched once the filter points elsewhere.' );
	}
}
