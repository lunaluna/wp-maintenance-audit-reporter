<?php
/**
 * Unit tests for WPMAR_Jobs_REST::build_result_links() / download_url().
 *
 * Covers the augmentation the jobs-status REST endpoint applies to a finished
 * runner result before it reaches the admin polling UI: stripping the bulky
 * `dry_preview` blob from dry-run payloads, attaching `report_url` +
 * nonce-signed download links for real runs, choosing between a PDF and a
 * client-Markdown download depending on whether the PDF library is present,
 * and leaving network-rollup results (no single report id) untouched.
 *
 * The "PDF library unavailable" case can't be reproduced in-process: mpdf/mpdf
 * and Parsedown are real Composer dependencies of this plugin, and once a class
 * is declared in a PHP process there is no way to undeclare it (verified that
 * even @runTestsInSeparateProcesses doesn't help here — PHPUnit's process
 * isolation still leaks a sibling test's `require` into the next one depending
 * on method run order). That one test spawns a genuinely independent `php` CLI
 * process instead; see its docblock.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

if ( ! defined( 'WPMAR_REPORTS_PAGE_SLUG' ) ) {
	define( 'WPMAR_REPORTS_PAGE_SLUG', 'wpmar-reports' );
}

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-loopback-detector.php';
require_once dirname( __DIR__ ) . '/includes/api/class-wpmar-jobs-rest.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wpmar-admin-menu.php';

/**
 * Exposes the protected result-augmentation internals for direct assertions.
 */
final class ExposedJobsRestLinks extends \WPMAR_Jobs_REST {

	/**
	 * @param array<string,mixed> $result Decoded runner result.
	 * @return array<string,mixed>
	 */
	public static function callBuildResultLinks( array $result ) {
		return self::build_result_links( $result );
	}
}

/**
 * Covers WPMAR_Jobs_REST::build_result_links() and WPMAR_Admin_Menu::consume_dry_run_brevity().
 */
final class ReportPreviewLinksTest extends TestCase {

	public function test_dry_run_result_drops_the_bulky_dry_preview_but_keeps_dry_brevity(): void {
		$result = ExposedJobsRestLinks::callBuildResultLinks(
			array(
				'dry_preview' => array( 'themes' => array( 'inventory' => array( array( 'slug' => 'x' ) ) ) ),
				'dry_brevity' => '{"site":"x"}',
			)
		);

		self::assertArrayNotHasKey( 'dry_preview', $result );
		self::assertSame( '{"site":"x"}', $result['dry_brevity'] );
	}

	public function test_full_run_result_gets_a_report_url_with_page_and_report_id(): void {
		$result = ExposedJobsRestLinks::callBuildResultLinks(
			array(
				'report_id' => 7,
				'mail_sent' => true,
				'status'    => 'success',
			)
		);

		self::assertArrayHasKey( 'report_url', $result );
		self::assertStringContainsString( 'page=' . \WPMAR_REPORTS_PAGE_SLUG, $result['report_url'] );
		self::assertStringContainsString( 'report_id=7', $result['report_url'] );
	}

	public function test_md_download_nonce_action_matches_the_reports_page_verifier(): void {
		$result = ExposedJobsRestLinks::callBuildResultLinks( array( 'report_id' => 42 ) );

		self::assertArrayHasKey( 'md', $result['downloads'] );

		// WPMAR_Reports_Page::maybe_stream_report_download() verifies with
		// check_admin_referer( 'wpmar_dl_' . $type . '_' . $id ) — reproduce that
		// exact action string here so a rename on either side breaks this test.
		$expected_nonce = wp_create_nonce( 'wpmar_dl_md_42' );

		self::assertStringContainsString( '_wpnonce=' . $expected_nonce, $result['downloads']['md'] );
	}

	public function test_pdf_library_available_offers_a_pdf_download(): void {
		require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-pdf-writer.php';
		self::assertTrue( \WPMAR_PDF_Writer::is_available(), 'Precondition: mpdf/mpdf + Parsedown must be installed for this test to be meaningful.' );

		$result = ExposedJobsRestLinks::callBuildResultLinks( array( 'report_id' => 1 ) );

		self::assertArrayHasKey( 'pdf', $result['downloads'] );
		self::assertArrayNotHasKey( 'client_md', $result['downloads'] );
	}

	/**
	 * Simulating "the PDF library isn't installed" can't be done in-process: mpdf/mpdf
	 * and Parsedown are real Composer dependencies of this plugin, and once a class is
	 * declared in a PHP process there's no way to undeclare it — @runTestsInSeparateProcesses
	 * alone isn't enough either (verified empirically: PHPUnit's process-isolation runner
	 * still leaks a sibling test's `require` into this one depending on method run order).
	 * So this spawns a genuinely independent `php` CLI process that never references
	 * WPMAR_PDF_Writer / \Mpdf\Mpdf / \Parsedown at all, making class_exists('WPMAR_PDF_Writer')
	 * false there regardless of what any other test in this suite has already loaded.
	 */
	public function test_pdf_library_unavailable_falls_back_to_client_markdown_download(): void {
		$plugin_root = dirname( __DIR__ );
		$script      = <<<PHP
<?php
define( 'ABSPATH', {$this->php_export( __DIR__ . '/fixtures/fake-root/' )} );
define( 'WPMAR_REPORTS_PAGE_SLUG', 'wpmar-reports' );
require {$this->php_export( __DIR__ . '/wp-stubs.php' )};
require {$this->php_export( $plugin_root . '/includes/class-wpmar-loopback-detector.php' )};
require {$this->php_export( $plugin_root . '/includes/api/class-wpmar-jobs-rest.php' )};

class StandaloneExposedJobsRestLinks extends WPMAR_Jobs_REST {
	public static function callBuildResultLinks( array \$result ) {
		return self::build_result_links( \$result );
	}
}

\$result = StandaloneExposedJobsRestLinks::callBuildResultLinks( array( 'report_id' => 1 ) );
echo json_encode(
	array(
		'pdf_writer_loaded' => class_exists( 'WPMAR_PDF_Writer', false ),
		'has_pdf'            => array_key_exists( 'pdf', \$result['downloads'] ),
		'has_client_md'      => array_key_exists( 'client_md', \$result['downloads'] ),
	)
);
PHP;

		$script_path = tempnam( sys_get_temp_dir(), 'wpmar-pdf-unavailable-' ) . '.php';
		file_put_contents( $script_path, $script );

		try {
			$output = shell_exec( PHP_BINARY . ' ' . escapeshellarg( $script_path ) . ' 2>&1' );
		} finally {
			unlink( $script_path );
		}

		$decoded = json_decode( (string) $output, true );

		self::assertIsArray( $decoded, 'Standalone process must print valid JSON; got: ' . $output );
		self::assertFalse( $decoded['pdf_writer_loaded'], 'WPMAR_PDF_Writer must never have been referenced in the standalone process.' );
		self::assertTrue( $decoded['has_client_md'] );
		self::assertFalse( $decoded['has_pdf'] );
	}

	/**
	 * PHP source literal for a string, so the generated standalone script embeds
	 * paths as valid PHP regardless of characters they contain.
	 *
	 * @param string $value Value to embed.
	 * @return string
	 */
	private function php_export( $value ) {
		return var_export( $value, true );
	}

	public function test_non_positive_report_id_returns_the_result_unmodified(): void {
		// Network rollups don't map to a single report row.
		$original = array(
			'skipped' => false,
			'status'  => 'success',
		);

		$result = ExposedJobsRestLinks::callBuildResultLinks( $original );

		self::assertSame( $original, $result );
		self::assertArrayNotHasKey( 'report_url', $result );
		self::assertArrayNotHasKey( 'downloads', $result );
	}

	public function test_consume_dry_run_brevity_returns_the_stashed_value_once_then_empty(): void {
		$property = new \ReflectionProperty( \WPMAR_Admin_Menu::class, 'dry_run_brevity_inline' );
		$property->setAccessible( true );
		$property->setValue( null, '{"site":"x"}' );

		self::assertSame( '{"site":"x"}', \WPMAR_Admin_Menu::consume_dry_run_brevity() );
		self::assertSame( '', \WPMAR_Admin_Menu::consume_dry_run_brevity(), 'A second consume in the same request must come back empty.' );
	}

	public function test_consume_dry_run_brevity_treats_whitespace_only_as_empty(): void {
		$property = new \ReflectionProperty( \WPMAR_Admin_Menu::class, 'dry_run_brevity_inline' );
		$property->setAccessible( true );
		$property->setValue( null, "  \n\t " );

		self::assertSame( '', \WPMAR_Admin_Menu::consume_dry_run_brevity() );
	}
}
