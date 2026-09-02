<?php
/**
 * PHPUnit coverage for the network "report output scope" setting and rollup filtering.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Asserts WPMAR_Network_Settings sanitization and WPMAR_Network_Runner segment filtering
 * for the report-output-scope feature (1.5.4).
 *
 * @coversNothing
 */
final class NetworkReportScopeTest extends TestCase {

	/**
	 * Bootstraps plugin classes for offline tests.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
		}

		require_once __DIR__ . '/wp-stubs.php';
		require_once dirname( __DIR__ ) . '/includes/class-wpmar-settings.php';
		require_once dirname( __DIR__ ) . '/includes/class-wpmar-network-settings.php';
	}

	/**
	 * Resets the in-memory site-option store between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wpmar_test_site_options'] = array();
		$GLOBALS['_wpmar_test_is_multisite'] = true;
	}

	/**
	 * #1 - defaults() exposes report.scope === 'all' and report.blog_ids === [].
	 *
	 * @return void
	 */
	public function test_defaults_include_report_scope_all_and_empty_blog_ids(): void {
		$defaults = \WPMAR_Network_Settings::defaults();

		self::assertSame( 'all', $defaults['report']['scope'] );
		self::assertSame( array(), $defaults['report']['blog_ids'] );
	}

	/**
	 * #2 - a stored value missing the `report` key gets it backfilled by get_all()'s
	 * normalize() call, rather than raising a PHP warning (the shallow-merge regression
	 * this feature is most at risk of).
	 *
	 * @return void
	 */
	public function test_get_all_backfills_missing_report_key(): void {
		$GLOBALS['_wpmar_test_site_options']['wpmar_network_settings'] = array(
			'network_audit_enabled' => true,
		);

		$settings = \WPMAR_Network_Settings::get_all();

		self::assertArrayHasKey( 'report', $settings );
		self::assertSame( 'all', $settings['report']['scope'] );
		self::assertSame( array(), $settings['report']['blog_ids'] );
	}

	/**
	 * #3 - a `report` value that is not an array (string/null) is replaced with defaults.
	 *
	 * @return void
	 */
	public function test_non_array_report_value_falls_back_to_defaults(): void {
		$GLOBALS['_wpmar_test_site_options']['wpmar_network_settings'] = array(
			'report' => 'not-an-array',
		);

		$settings = \WPMAR_Network_Settings::get_all();

		self::assertSame( 'all', $settings['report']['scope'] );
		self::assertSame( array(), $settings['report']['blog_ids'] );

		$GLOBALS['_wpmar_test_site_options']['wpmar_network_settings'] = array(
			'report' => null,
		);

		$settings = \WPMAR_Network_Settings::get_all();

		self::assertSame( 'all', $settings['report']['scope'] );
	}

	/**
	 * #4 - an unknown scope value falls back to 'all'.
	 *
	 * @return void
	 */
	public function test_unknown_scope_value_falls_back_to_all(): void {
		$GLOBALS['_wpmar_test_site_options']['wpmar_network_settings'] = array(
			'report' => array(
				'scope'    => 'literally_anything_else',
				'blog_ids' => array(),
			),
		);

		$settings = \WPMAR_Network_Settings::get_all();

		self::assertSame( 'all', $settings['report']['scope'] );
	}

	/**
	 * #5 - blog_ids POST input is parsed on newlines, commas, and semicolons.
	 *
	 * @return void
	 */
	public function test_merge_form_input_parses_blog_ids_on_newline_comma_and_semicolon(): void {
		$curr = \WPMAR_Network_Settings::get_all();

		$merged = \WPMAR_Network_Settings::merge_form_input(
			array(
				'wpmar_report_scope'    => 'main_and_selected',
				'wpmar_report_blog_ids' => "2\n3,4;5",
			),
			$curr
		);

		self::assertSame( array( 2, 3, 4, 5 ), $merged['report']['blog_ids'] );
	}

	/**
	 * #6 - zero, non-numeric input, and duplicates are removed. Negative values are
	 * sign-flipped by absint() (abs(intval())) rather than removed - the same behaviour
	 * as the pre-existing `sites.exclude_blog_ids` sanitizer this mirrors.
	 *
	 * @return void
	 */
	public function test_merge_form_input_strips_invalid_and_duplicate_blog_ids(): void {
		$curr = \WPMAR_Network_Settings::get_all();

		$merged = \WPMAR_Network_Settings::merge_form_input(
			array(
				'wpmar_report_scope'    => 'main_and_selected',
				'wpmar_report_blog_ids' => "2\n2\n-1\n0\nabc\n3",
			),
			$curr
		);

		self::assertSame( array( 2, 1, 3 ), $merged['report']['blog_ids'] );
	}

	/**
	 * #7 - merge_form_input() keeps the current value when the POST key is absent.
	 *
	 * @return void
	 */
	public function test_merge_form_input_keeps_current_value_when_post_key_absent(): void {
		$curr                       = \WPMAR_Network_Settings::get_all();
		$curr['report']['scope']    = 'main_only';
		$curr['report']['blog_ids'] = array( 2, 3 );

		$merged = \WPMAR_Network_Settings::merge_form_input( array(), $curr );

		self::assertSame( 'main_only', $merged['report']['scope'] );
		self::assertSame( array( 2, 3 ), $merged['report']['blog_ids'] );
	}

	/**
	 * #8 - merge_form_input() overwrites the current value when POST is present.
	 *
	 * @return void
	 */
	public function test_merge_form_input_overwrites_when_post_present(): void {
		$curr                       = \WPMAR_Network_Settings::get_all();
		$curr['report']['scope']    = 'main_only';
		$curr['report']['blog_ids'] = array( 2 );

		$merged = \WPMAR_Network_Settings::merge_form_input(
			array(
				'wpmar_report_scope'    => 'main_and_selected',
				'wpmar_report_blog_ids' => '5,6',
			),
			$curr
		);

		self::assertSame( 'main_and_selected', $merged['report']['scope'] );
		self::assertSame( array( 5, 6 ), $merged['report']['blog_ids'] );
	}
}
