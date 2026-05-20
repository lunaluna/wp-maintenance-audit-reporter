<?php
/**
 * Unit tests for WPMAR_Settings core helpers.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Covers clamp_int, parse_line_paths, parse_email_list, and merge_form_input
 * (timezone whitelist + retention whitelist introduced in Phase 1-B / Phase 2-A).
 */
final class SettingsTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
		}

		require_once __DIR__ . '/wp-stubs.php';
		require_once dirname( __DIR__ ) . '/includes/class-wpmar-settings.php';
	}

	// -------------------------------------------------------------------------
	// clamp_int
	// -------------------------------------------------------------------------

	public function test_clamp_int_snaps_below_min_to_min(): void {
		self::assertSame( 1, \WPMAR_Settings::clamp_int( 0, 1, 31 ) );
	}

	public function test_clamp_int_snaps_above_max_to_max(): void {
		self::assertSame( 31, \WPMAR_Settings::clamp_int( 99, 1, 31 ) );
	}

	public function test_clamp_int_passes_value_within_range(): void {
		self::assertSame( 15, \WPMAR_Settings::clamp_int( 15, 1, 31 ) );
	}

	// -------------------------------------------------------------------------
	// parse_line_paths
	// -------------------------------------------------------------------------

	public function test_parse_line_paths_trims_whitespace_and_skips_comments(): void {
		$raw    = "# comment\n\nwp-includes/foo.php\n  wp-admin/bar.php  \n";
		$result = \WPMAR_Settings::parse_line_paths( $raw );

		self::assertSame( array( 'wp-includes/foo.php', 'wp-admin/bar.php' ), $result );
	}

	public function test_parse_line_paths_deduplicates_identical_entries(): void {
		$result = \WPMAR_Settings::parse_line_paths( "foo.php\nfoo.php\n" );

		self::assertCount( 1, $result );
		self::assertSame( 'foo.php', $result[0] );
	}

	public function test_parse_line_paths_returns_empty_array_for_blank_input(): void {
		self::assertSame( array(), \WPMAR_Settings::parse_line_paths( '' ) );
	}

	// -------------------------------------------------------------------------
	// parse_email_list
	// -------------------------------------------------------------------------

	public function test_parse_email_list_accepts_valid_addresses(): void {
		$result = \WPMAR_Settings::parse_email_list( "user@example.com\nadmin@example.org" );

		self::assertContains( 'user@example.com', $result );
		self::assertContains( 'admin@example.org', $result );
	}

	public function test_parse_email_list_rejects_invalid_addresses(): void {
		$result = \WPMAR_Settings::parse_email_list( "valid@example.com\nnot-an-email\n@@broken" );

		self::assertContains( 'valid@example.com', $result );
		self::assertNotContains( 'not-an-email', $result );
		self::assertNotContains( '@@broken', $result );
	}

	public function test_parse_email_list_deduplicates(): void {
		$result = \WPMAR_Settings::parse_email_list( "foo@bar.com\nfoo@bar.com\nfoo@bar.com" );

		self::assertCount( 1, $result );
	}

	// -------------------------------------------------------------------------
	// merge_form_input — timezone whitelist (Phase 1-B fix)
	// -------------------------------------------------------------------------

	public function test_merge_form_input_accepts_valid_timezone(): void {
		$post = array(
			'wpmar_schedule_day'    => '15',
			'wpmar_schedule_hour'   => '2',
			'wpmar_schedule_minute' => '0',
			'wpmar_schedule_tz'     => 'America/New_York',
		);

		$result = \WPMAR_Settings::merge_form_input( $post, \WPMAR_Settings::defaults() );

		self::assertSame( 'America/New_York', $result['schedule']['tz'] );
	}

	public function test_merge_form_input_rejects_invalid_timezone_and_falls_back(): void {
		$post = array(
			'wpmar_schedule_day'    => '15',
			'wpmar_schedule_hour'   => '2',
			'wpmar_schedule_minute' => '0',
			'wpmar_schedule_tz'     => 'Not/AReal/Timezone',
		);

		$result = \WPMAR_Settings::merge_form_input( $post, \WPMAR_Settings::defaults() );

		self::assertSame( 'Asia/Tokyo', $result['schedule']['tz'] );
	}

	public function test_merge_form_input_empty_timezone_falls_back_to_default(): void {
		$post = array(
			'wpmar_schedule_day'    => '15',
			'wpmar_schedule_hour'   => '2',
			'wpmar_schedule_minute' => '0',
			'wpmar_schedule_tz'     => '',
		);

		$result = \WPMAR_Settings::merge_form_input( $post, \WPMAR_Settings::defaults() );

		self::assertSame( 'Asia/Tokyo', $result['schedule']['tz'] );
	}

	public function test_merge_form_input_standalone_tz_update_accepts_valid(): void {
		$post = array( 'wpmar_schedule_tz' => 'Europe/London' );

		$result = \WPMAR_Settings::merge_form_input( $post, \WPMAR_Settings::defaults() );

		self::assertSame( 'Europe/London', $result['schedule']['tz'] );
	}

	public function test_merge_form_input_standalone_tz_update_rejects_invalid(): void {
		$post = array( 'wpmar_schedule_tz' => 'Fake/Zone' );

		$result = \WPMAR_Settings::merge_form_input( $post, \WPMAR_Settings::defaults() );

		self::assertSame( 'Asia/Tokyo', $result['schedule']['tz'] );
	}

	// -------------------------------------------------------------------------
	// merge_form_input — retention whitelist
	// -------------------------------------------------------------------------

	public function test_merge_form_input_retention_rejects_non_whitelisted_value(): void {
		$post   = array( 'wpmar_retention_months' => '6' );
		$result = \WPMAR_Settings::merge_form_input( $post, \WPMAR_Settings::defaults() );

		self::assertSame( 12, $result['retention']['months'] );
	}

	public function test_merge_form_input_retention_accepts_zero_for_unlimited(): void {
		$post   = array( 'wpmar_retention_months' => '0' );
		$result = \WPMAR_Settings::merge_form_input( $post, \WPMAR_Settings::defaults() );

		self::assertSame( 0, $result['retention']['months'] );
	}

	public function test_merge_form_input_retention_accepts_24_months(): void {
		$post   = array( 'wpmar_retention_months' => '24' );
		$result = \WPMAR_Settings::merge_form_input( $post, \WPMAR_Settings::defaults() );

		self::assertSame( 24, $result['retention']['months'] );
	}

	// -------------------------------------------------------------------------
	// merge_form_input — schedule clamping
	// -------------------------------------------------------------------------

	public function test_merge_form_input_clamps_schedule_day_to_valid_range(): void {
		$post = array(
			'wpmar_schedule_day'    => '99',
			'wpmar_schedule_hour'   => '25',
			'wpmar_schedule_minute' => '70',
			'wpmar_schedule_tz'     => 'Asia/Tokyo',
		);

		$result = \WPMAR_Settings::merge_form_input( $post, \WPMAR_Settings::defaults() );

		self::assertSame( 31, $result['schedule']['day'] );
		self::assertSame( 23, $result['schedule']['hour'] );
		self::assertSame( 59, $result['schedule']['minute'] );
	}
}
