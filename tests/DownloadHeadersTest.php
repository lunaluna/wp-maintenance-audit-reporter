<?php
/**
 * Unit tests for WPMAR_Download_Headers (S-6: nosniff on every download response).
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-download-headers.php';

/**
 * @covers \WPMAR_Download_Headers
 */
class DownloadHeadersTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wpmar_test_nocache_headers_called'] = false;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_wpmar_test_nocache_headers_called'] );
		parent::tearDown();
	}

	public function test_attachment_headers_include_nosniff_and_content_disposition() {
		$headers = \WPMAR_Download_Headers::attachment_headers(
			'text/plain; charset=utf-8',
			'wpmar-report-example-1.md'
		);

		$this->assertContains( 'X-Content-Type-Options: nosniff', $headers );
		$this->assertContains( 'Content-Type: text/plain; charset=utf-8', $headers );
		$this->assertContains(
			'Content-Disposition: attachment; filename="wpmar-report-example-1.md"',
			$headers
		);
	}

	public function test_attachment_headers_include_content_length_when_given() {
		$headers = \WPMAR_Download_Headers::attachment_headers( 'application/pdf', 'report.pdf', 1234 );

		$this->assertContains( 'Content-Length: 1234', $headers );
	}

	public function test_attachment_headers_omit_content_length_when_false() {
		$headers = \WPMAR_Download_Headers::attachment_headers( 'application/zip', 'reports.zip', false );

		foreach ( $headers as $header ) {
			$this->assertStringNotContainsString( 'Content-Length:', $header );
		}
	}

	public function test_attachment_headers_sanitize_filename() {
		$headers = \WPMAR_Download_Headers::attachment_headers( 'text/plain', '../../etc/passwd' );

		$this->assertContains( 'Content-Disposition: attachment; filename="etcpasswd"', $headers );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_send_attachment_triggers_nocache_headers() {
		// Separate process: a real header() call errors with "headers already
		// sent" once PHPUnit's own progress output has reached STDOUT. The
		// header values themselves are covered by attachment_headers() above.
		\WPMAR_Download_Headers::send_attachment( 'text/plain', 'report.md' );

		$this->assertTrue( $GLOBALS['_wpmar_test_nocache_headers_called'] );
	}
}
