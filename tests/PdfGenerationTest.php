<?php
/**
 * Unit tests for WPMAR_PDF_Writer's PDF generation path.
 *
 * tests/PdfWriterSafeModeTest.php already covers the shared Markdown→HTML
 * sanitizer ({@see WPMAR_PDF_Writer::markdown_to_safe_html_fragment()}); this
 * file covers the generation path around it instead: the client-body source
 * selector, the missing-library guard, a real end-to-end PDF render (skipped
 * when mpdf/Parsedown genuinely aren't installed), temp-directory cleanup,
 * the `allow_local_file_access` hardening, Japanese-font fallback, and
 * filename-slug sanitization.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

// WPMAR_Private_Storage::configured_base_dir() falls back to WP_CONTENT_DIR
// when WPMAR_PRIVATE_STORAGE_DIR isn't set; define both to isolated temp
// locations the same way tests/PrivateStorageResolveTest.php does.
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-pdf-generation-test-content' );
}

if ( ! defined( 'WPMAR_PRIVATE_STORAGE_DIR' ) ) {
	define( 'WPMAR_PRIVATE_STORAGE_DIR', rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-pdf-generation-test-storage' );
}

// write_pdf_from_markdown() reads WPMAR_PLUGIN_DIR . 'fonts' for the bundled
// NotoSansJP faces; point it at the real plugin root so the "font available"
// path is the one exercised by the real-generation test below (matches
// production — this plugin does ship fonts/NotoSansJP-*.ttf).
if ( ! defined( 'WPMAR_PLUGIN_DIR' ) ) {
	define( 'WPMAR_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-private-storage.php';
require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-pdf-writer.php';

/**
 * Covers WPMAR_PDF_Writer::markdown_body_for_client_pdf() / write_pdf_from_markdown().
 */
final class PdfGenerationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->rrmdir( WPMAR_PRIVATE_STORAGE_DIR );
		mkdir( WPMAR_PRIVATE_STORAGE_DIR, 0755, true );

		$GLOBALS['_wpmar_test_options']         = array();
		$GLOBALS['_wpmar_test_is_multisite']    = false;
		$GLOBALS['_wpmar_test_current_blog_id'] = 1;
	}

	protected function tearDown(): void {
		$this->rrmdir( WPMAR_PRIVATE_STORAGE_DIR );
		unset(
			$GLOBALS['_wpmar_test_options'],
			$GLOBALS['_wpmar_test_is_multisite'],
			$GLOBALS['_wpmar_test_current_blog_id']
		);
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

	// -------------------------------------------------------------------------
	// markdown_body_for_client_pdf()
	// -------------------------------------------------------------------------

	public function test_markdown_body_for_client_pdf_trims_the_client_body(): void {
		$body = \WPMAR_PDF_Writer::markdown_body_for_client_pdf(
			array( 'body_client_md' => "  \n# タイトル\n本文\n\n" )
		);

		self::assertSame( "# タイトル\n本文", $body );
	}

	public function test_markdown_body_for_client_pdf_is_empty_when_key_is_missing(): void {
		self::assertSame( '', \WPMAR_PDF_Writer::markdown_body_for_client_pdf( array() ) );
	}

	// -------------------------------------------------------------------------
	// write_pdf_from_markdown() — missing-library guard
	// -------------------------------------------------------------------------

	/**
	 * mpdf/mpdf + Parsedown are real Composer dependencies, always loadable in
	 * this environment once referenced — so, same technique as
	 * tests/ReportPreviewLinksTest.php's PDF-unavailable case, this spawns a
	 * genuinely independent `php` CLI process that never references
	 * \Mpdf\Mpdf / \Parsedown at all, so is_available() is deterministically
	 * false there regardless of what any other test in this suite has loaded.
	 */
	public function test_write_pdf_from_markdown_returns_wp_error_when_library_is_unavailable(): void {
		$plugin_root = dirname( __DIR__ );
		$storage_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-pdf-unavailable-storage-' . uniqid( '', true );
		$script      = <<<PHP
<?php
define( 'ABSPATH', {$this->php_export( __DIR__ . '/fixtures/fake-root/' )} );
define( 'WP_CONTENT_DIR', {$this->php_export( $storage_dir . '-content' )} );
define( 'WPMAR_PRIVATE_STORAGE_DIR', {$this->php_export( $storage_dir )} );
require {$this->php_export( __DIR__ . '/wp-stubs.php' )};
require {$this->php_export( $plugin_root . '/includes/storage/class-wpmar-private-storage.php' )};
require {$this->php_export( $plugin_root . '/includes/storage/class-wpmar-pdf-writer.php' )};

\$result = WPMAR_PDF_Writer::write_pdf_from_markdown( '# タイトル', 'report' );
echo json_encode(
	array(
		'library_loaded' => class_exists( 'WPMAR_PDF_Writer', false ) && \\WPMAR_PDF_Writer::is_available(),
		'is_wp_error'    => is_wp_error( \$result ),
		'error_code'     => is_wp_error( \$result ) ? \$result->get_error_code() : null,
	)
);
PHP;

		$output = $this->run_standalone_script( $script );
		$decoded = json_decode( (string) $output, true );

		self::assertIsArray( $decoded, 'Standalone process must print valid JSON; got: ' . $output );
		self::assertFalse( $decoded['library_loaded'] );
		self::assertTrue( $decoded['is_wp_error'] );
		self::assertSame( 'wpmar_pdf_missing_libs', $decoded['error_code'] );
	}

	// -------------------------------------------------------------------------
	// Real generation (skipped when mpdf/Parsedown truly aren't installed)
	// -------------------------------------------------------------------------

	public function test_real_generation_produces_a_readable_pdf_with_a_private_relative_path(): void {
		if ( ! \WPMAR_PDF_Writer::is_available() ) {
			self::markTestSkipped( 'mpdf/mpdf + Parsedown are not installed in this environment.' );
		}

		$result = \WPMAR_PDF_Writer::write_pdf_from_markdown( "# 保守レポート\n\n日本語の本文です。改ざんは検出されませんでした。", 'client-report' );

		self::assertIsString( $result, 'Expected a private:-prefixed path, got: ' . ( is_wp_error( $result ) ? $result->get_error_message() : 'non-string' ) );
		self::assertStringStartsWith( 'private:', $result );

		$relative = substr( $result, strlen( 'private:' ) );
		$absolute = rtrim( \WPMAR_Private_Storage::base_dir(), '/\\' ) . '/' . ltrim( $relative, '/\\' );

		self::assertFileExists( $absolute );
		$bytes = file_get_contents( $absolute );
		self::assertNotFalse( $bytes );
		self::assertStringStartsWith( '%PDF-', $bytes );
		self::assertGreaterThan( 0, strlen( $bytes ) );
	}

	public function test_real_generation_cleans_up_its_mpdf_temp_directory(): void {
		if ( ! \WPMAR_PDF_Writer::is_available() ) {
			self::markTestSkipped( 'mpdf/mpdf + Parsedown are not installed in this environment.' );
		}

		\WPMAR_PDF_Writer::write_pdf_from_markdown( '# 見出し', 'cleanup-check' );

		$tmp_dir = \WPMAR_Private_Storage::tmp_dir();
		self::assertIsString( $tmp_dir );

		$leftovers = array_filter(
			(array) scandir( $tmp_dir ),
			static function ( $item ) {
				return '.' !== $item && '..' !== $item && '.htaccess' !== $item && 'index.php' !== $item;
			}
		);

		self::assertSame( array(), array_values( $leftovers ), 'mPDF temp workspace must be fully cleaned up after a successful render.' );
	}

	public function test_missing_notojp_fonts_fall_back_to_sun_exta_without_throwing(): void {
		$plugin_root  = dirname( __DIR__ );
		$fontless_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-pdf-fontless-plugin-' . uniqid( '', true );
		mkdir( $fontless_dir, 0755, true );
		$storage_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-pdf-fontless-storage-' . uniqid( '', true );

		$script = <<<PHP
<?php
define( 'ABSPATH', {$this->php_export( __DIR__ . '/fixtures/fake-root/' )} );
define( 'WP_CONTENT_DIR', {$this->php_export( $storage_dir . '-content' )} );
define( 'WPMAR_PRIVATE_STORAGE_DIR', {$this->php_export( $storage_dir )} );
define( 'WPMAR_PLUGIN_DIR', {$this->php_export( $fontless_dir . '/' )} );
require {$this->php_export( $plugin_root . '/vendor/autoload.php' )};
require {$this->php_export( __DIR__ . '/wp-stubs.php' )};
require {$this->php_export( $plugin_root . '/includes/storage/class-wpmar-private-storage.php' )};
require {$this->php_export( $plugin_root . '/includes/storage/class-wpmar-pdf-writer.php' )};

if ( ! WPMAR_PDF_Writer::is_available() ) {
	echo json_encode( array( 'skipped' => true ) );
	exit;
}

\$result = WPMAR_PDF_Writer::write_pdf_from_markdown( '# Title', 'fontless' );
echo json_encode(
	array(
		'skipped'  => false,
		'is_error' => is_wp_error( \$result ),
		'message'  => is_wp_error( \$result ) ? \$result->get_error_message() : '',
	)
);
PHP;

		$output  = $this->run_standalone_script( $script );
		$decoded = json_decode( (string) $output, true );

		$this->rrmdir( $fontless_dir );
		$this->rrmdir( $storage_dir );
		$this->rrmdir( $storage_dir . '-content' );

		self::assertIsArray( $decoded, 'Standalone process must print valid JSON; got: ' . $output );
		if ( ! empty( $decoded['skipped'] ) ) {
			self::markTestSkipped( 'mpdf/mpdf + Parsedown are not installed in this environment.' );
		}

		self::assertFalse( $decoded['is_error'], 'Missing NotoSansJP fonts must fall back to sun-exta, not fail: ' . $decoded['message'] );
	}

	// -------------------------------------------------------------------------
	// allow_local_file_access hardening (source-level regression guard —
	// there is no seam to intercept mPDF's internal constructor config)
	// -------------------------------------------------------------------------

	public function test_source_disables_mpdf_local_file_access(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/storage/class-wpmar-pdf-writer.php' );

		self::assertMatchesRegularExpression(
			"/'allow_local_file_access'\\s*=>\\s*false/",
			$source,
			'write_pdf_from_markdown() must keep allow_local_file_access disabled in the $mpdf_config it builds.'
		);
	}

	// -------------------------------------------------------------------------
	// Filename slug sanitization
	// -------------------------------------------------------------------------

	public function test_japanese_basename_sanitizes_to_the_report_fallback_slug(): void {
		if ( ! \WPMAR_PDF_Writer::is_available() ) {
			self::markTestSkipped( 'mpdf/mpdf + Parsedown are not installed in this environment.' );
		}

		$result = \WPMAR_PDF_Writer::write_pdf_from_markdown( '# 見出し', '保守レポート/日本語' );

		self::assertIsString( $result );
		self::assertStringContainsString( '/report-', $result, 'A basename with no ASCII alnum/-/_ characters must fall back to the "report" slug.' );
	}

	/**
	 * PHP source literal for a string, so a generated standalone script embeds
	 * paths as valid PHP regardless of characters they contain.
	 *
	 * @param string $value Value to embed.
	 * @return string
	 */
	private function php_export( $value ) {
		return var_export( $value, true );
	}

	/**
	 * Runs $script in a fresh `php` CLI process and returns its stdout+stderr.
	 *
	 * @param string $script Full PHP source (including opening `<?php`).
	 * @return string
	 */
	private function run_standalone_script( $script ) {
		// No `.php` suffix appended: `php <path>` runs a file regardless of its
		// extension, and appending one would require a *second* path derived
		// from tempnam()'s — leaving the original (already-created, now unused)
		// temp file behind unlinked.
		$script_path = tempnam( sys_get_temp_dir(), 'wpmar-pdf-standalone-' );
		file_put_contents( $script_path, $script );

		try {
			return (string) shell_exec( PHP_BINARY . ' ' . escapeshellarg( $script_path ) . ' 2>&1' );
		} finally {
			unlink( $script_path );
		}
	}
}
