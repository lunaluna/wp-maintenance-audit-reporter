<?php
/**
 * Unit tests for WPMAR_PDF_Installer's directory resolution (1.5.5 Step 1).
 *
 * vendor_dir() / fonts_dir() / autoload_path() must prefer the in-plugin
 * `vendor/` and `fonts/` directories (development checkouts, sites not yet
 * migrated) and fall back to the external `wpmar-pdf-lib` directory
 * ({@see wpmar_pdf_lib_dir()}) otherwise. Each case runs in a fresh `php` CLI
 * process (same technique as tests/PdfGenerationTest.php) because
 * WPMAR_PLUGIN_DIR and WP_CONTENT_DIR are constants that cannot be redefined
 * per-scenario within a single process.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Covers WPMAR_PDF_Installer::vendor_dir() / fonts_dir() / autoload_path().
 */
final class PdfLibDirResolutionTest extends TestCase {

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
	 * @param string $script Full PHP source (including opening `<?php`).
	 * @return string
	 */
	private function run_standalone_script( $script ) {
		$script_path = tempnam( sys_get_temp_dir(), 'wpmar-pdf-lib-dir-standalone-' );
		file_put_contents( $script_path, $script );

		try {
			return (string) shell_exec( PHP_BINARY . ' ' . escapeshellarg( $script_path ) . ' 2>&1' );
		} finally {
			unlink( $script_path );
		}
	}

	/**
	 * @param bool $plugin_vendor   Whether to create vendor/ (and fonts/) inside the plugin dir.
	 * @param bool $external_vendor Whether to create vendor/ (and fonts/) under the external lib dir.
	 * @return array{0:string,1:string} [plugin_dir, external_lib_dir] absolute paths.
	 */
	private function make_fixture_dirs( $plugin_vendor, $external_vendor ) {
		$unique      = uniqid( '', true );
		$plugin_dir  = rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-pdf-lib-dir-plugin-' . $unique;
		$content_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-pdf-lib-dir-content-' . $unique;
		mkdir( $plugin_dir, 0755, true );
		mkdir( $content_dir, 0755, true );
		$this->created_dirs[] = $plugin_dir;
		$this->created_dirs[] = $content_dir;

		if ( $plugin_vendor ) {
			mkdir( $plugin_dir . '/vendor', 0755, true );
			mkdir( $plugin_dir . '/fonts', 0755, true );
			touch( $plugin_dir . '/vendor/autoload.php' );
		}
		if ( $external_vendor ) {
			mkdir( $content_dir . '/wpmar-pdf-lib/vendor', 0755, true );
			mkdir( $content_dir . '/wpmar-pdf-lib/fonts', 0755, true );
			touch( $content_dir . '/wpmar-pdf-lib/vendor/autoload.php' );
		}

		return array( $plugin_dir, $content_dir );
	}

	/**
	 * @param string $plugin_dir  Absolute path passed as WPMAR_PLUGIN_DIR.
	 * @param string $content_dir Absolute path passed as WP_CONTENT_DIR.
	 * @return array{vendor_dir:string,fonts_dir:string,autoload_path:string}
	 */
	private function resolve( $plugin_dir, $content_dir ) {
		$plugin_root = dirname( __DIR__ );
		$script      = <<<PHP
<?php
define( 'ABSPATH', {$this->php_export( __DIR__ . '/fixtures/fake-root/' )} );
define( 'WPMAR_PLUGIN_DIR', {$this->php_export( rtrim( $plugin_dir, '/\\\\' ) . '/' )} );
define( 'WP_CONTENT_DIR', {$this->php_export( rtrim( $content_dir, '/\\\\' ) )} );
require {$this->php_export( __DIR__ . '/wp-stubs.php' )};
require {$this->php_export( $plugin_root . '/includes/admin/class-wpmar-pdf-installer.php' )};

echo json_encode(
	array(
		'vendor_dir'    => \WPMAR_PDF_Installer::vendor_dir(),
		'fonts_dir'     => \WPMAR_PDF_Installer::fonts_dir(),
		'autoload_path' => \WPMAR_PDF_Installer::autoload_path(),
	)
);
PHP;

		$output  = $this->run_standalone_script( $script );
		$decoded = json_decode( (string) $output, true );

		self::assertIsArray( $decoded, 'Standalone process must print valid JSON; got: ' . $output );

		return $decoded;
	}

	public function test_resolves_to_the_in_plugin_directories_when_both_locations_have_the_bundle(): void {
		list( $plugin_dir, $content_dir ) = $this->make_fixture_dirs( true, true );

		$resolved = $this->resolve( $plugin_dir, $content_dir );

		self::assertSame( $plugin_dir . '/vendor', $resolved['vendor_dir'] );
		self::assertSame( $plugin_dir . '/fonts', $resolved['fonts_dir'] );
		self::assertSame( $plugin_dir . '/vendor/autoload.php', $resolved['autoload_path'] );
	}

	public function test_resolves_to_the_in_plugin_directories_when_only_the_plugin_has_the_bundle(): void {
		list( $plugin_dir, $content_dir ) = $this->make_fixture_dirs( true, false );

		$resolved = $this->resolve( $plugin_dir, $content_dir );

		self::assertSame( $plugin_dir . '/vendor', $resolved['vendor_dir'] );
		self::assertSame( $plugin_dir . '/fonts', $resolved['fonts_dir'] );
		self::assertSame( $plugin_dir . '/vendor/autoload.php', $resolved['autoload_path'] );
	}

	public function test_falls_back_to_the_external_lib_directory_when_only_it_has_the_bundle(): void {
		list( $plugin_dir, $content_dir ) = $this->make_fixture_dirs( false, true );

		$resolved = $this->resolve( $plugin_dir, $content_dir );

		self::assertSame( $content_dir . '/wpmar-pdf-lib/vendor', $resolved['vendor_dir'] );
		self::assertSame( $content_dir . '/wpmar-pdf-lib/fonts', $resolved['fonts_dir'] );
		self::assertSame( $content_dir . '/wpmar-pdf-lib/vendor/autoload.php', $resolved['autoload_path'] );
	}

	public function test_falls_back_to_the_external_lib_directory_path_when_neither_location_has_the_bundle(): void {
		list( $plugin_dir, $content_dir ) = $this->make_fixture_dirs( false, false );

		$resolved = $this->resolve( $plugin_dir, $content_dir );

		self::assertSame( $content_dir . '/wpmar-pdf-lib/vendor', $resolved['vendor_dir'] );
		self::assertSame( $content_dir . '/wpmar-pdf-lib/fonts', $resolved['fonts_dir'] );
		self::assertSame( $content_dir . '/wpmar-pdf-lib/vendor/autoload.php', $resolved['autoload_path'] );
	}
}
