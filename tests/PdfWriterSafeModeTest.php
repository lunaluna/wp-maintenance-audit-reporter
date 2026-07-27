<?php
/**
 * Regression tests for WPMAR_PDF_Writer::markdown_to_safe_html_fragment() (S-2).
 *
 * Covers the reported issue: the PDF rendering path called `new \Parsedown()`
 * directly, bypassing the safe mode used by the HTML-email path, so raw HTML
 * (<script>, <annotation>, remote <img>) embedded in an attacker-controlled
 * plugin/theme/display_name string reached mPDF unsanitized.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

require_once __DIR__ . '/wp-stubs.php';

$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( is_readable( $autoload ) ) {
	require_once $autoload;
}

require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-pdf-writer.php';

class PdfWriterSafeModeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\Parsedown' ) ) {
			$this->markTestSkipped( 'erusev/parsedown is not installed (run composer install).' );
		}
	}

	public function test_strips_raw_script_tag() {
		$html = \WPMAR_PDF_Writer::markdown_to_safe_html_fragment(
			"Plugin name: <script>alert('xss')</script> v1.0"
		);

		$this->assertStringNotContainsString( '<script', $html );
	}

	public function test_strips_raw_annotation_tag() {
		$html = \WPMAR_PDF_Writer::markdown_to_safe_html_fragment(
			'Theme name: <annotation content="evil">note</annotation>'
		);

		$this->assertStringNotContainsString( '<annotation', $html );
	}

	public function test_strips_raw_img_tag_with_remote_src() {
		$html = \WPMAR_PDF_Writer::markdown_to_safe_html_fragment(
			'Display name: <img src="http://attacker.example/track.gif">'
		);

		// Safe mode escapes the raw tag to text (Parsedown then autolinks the bare
		// URL inside it) — the point is no <img> element survives for mPDF to fetch.
		$this->assertStringNotContainsString( '<img', $html );
	}

	public function test_strips_markdown_image_syntax_even_though_safe_mode_allows_it() {
		// Safe mode only blocks raw HTML; Markdown's own `![]()` still becomes
		// an <img> tag, which is why the fragment builder strips <img> as well.
		$html = \WPMAR_PDF_Writer::markdown_to_safe_html_fragment(
			'![tracker](http://attacker.example/track.gif)'
		);

		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'attacker.example', $html );
	}

	public function test_ordinary_markdown_still_renders() {
		$html = \WPMAR_PDF_Writer::markdown_to_safe_html_fragment( "# Title\n\nSome **bold** text." );

		$this->assertStringContainsString( '<h1>Title</h1>', $html );
		$this->assertStringContainsString( '<strong>bold</strong>', $html );
	}

	public function test_pdf_and_mail_paths_share_the_same_sanitizer() {
		$pdf_writer_source    = file_get_contents( dirname( __DIR__ ) . '/includes/storage/class-wpmar-pdf-writer.php' );
		$mail_notifier_source = file_get_contents( dirname( __DIR__ ) . '/includes/notify/class-wpmar-notifier-mail.php' );

		$this->assertStringContainsString( 'self::markdown_to_safe_html_fragment(', $pdf_writer_source );
		$this->assertStringContainsString( 'WPMAR_PDF_Writer::markdown_to_safe_html_fragment(', $mail_notifier_source );

		// The mail path must never instantiate Parsedown directly (that would
		// bypass safe mode); only the shared wrapper in class-wpmar-pdf-writer.php may.
		$this->assertStringNotContainsString( 'new \Parsedown', $mail_notifier_source );

		preg_match_all( '/new \\\\?Parsedown\(\)/', $pdf_writer_source, $matches );
		$this->assertSame(
			1,
			count( $matches[0] ),
			'Exactly one direct Parsedown instantiation is expected: inside markdown_to_safe_html_fragment() itself.'
		);
	}
}
