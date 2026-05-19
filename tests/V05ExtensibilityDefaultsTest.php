<?php
/**
 * PHPUnit coverage for v0.5 Markdown extras and merged performance defaults.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Asserts stringify helper + settings defaults shipped for extensibility knobs.
 *
 * @coversNothing
 */
final class V05ExtensibilityDefaultsTest extends TestCase {

	/**
	 * Bootstraps core plugin files sans WordPress.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
		}
		require_once dirname( __DIR__ ) . '/includes/class-wpmar-settings.php';
		require_once dirname( __DIR__ ) . '/includes/checks/class-wpmar-check-performance.php';
	}

	/**
	 * Ensures stringify helper honours strings and Markdown maps.
	 *
	 * @return void
	 */
	public function test_stringify_section_extras_includes_supported_shapes(): void {
		$html = \WPMAR_Check_Performance::stringify_section_extras(
			array(
				'Lead',
				array(
					'markdown' => "### Sub-heading\nParagraph",
				),
				array( 'markdown' => '   ' ),
				array( 'ignore' => 1 ),
			)
		);

		self::assertStringContainsString( 'Lead', $html );
		self::assertStringContainsString( '### Sub-heading', $html );
		self::assertStringContainsString( 'Paragraph', $html );
		self::assertStringNotContainsString( 'ignore', $html );
	}

	/**
	 * Confirms DB size sampling stays OFF by default.
	 *
	 * @return void
	 */
	public function test_performance_defaults_disabled(): void {
		$performance = \WPMAR_Settings::defaults()['performance'];

		self::assertIsArray( $performance );
		self::assertArrayHasKey( 'db_size_enabled', $performance );
		self::assertFalse( (bool) $performance['db_size_enabled'] );
		self::assertSame( array( 'db_size_enabled' => false ), $performance );
	}
}
