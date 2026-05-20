<?php
/**
 * Unit tests for WPMAR_Domain_Gate::is_allowed().
 *
 * home_url() is controlled via $GLOBALS['_wpmar_test_home_url'] (see wp-stubs.php).
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Covers host matching, case insensitivity, path prefix gating, and the
 * permissive-fallback behaviour when no allowed_host is configured.
 */
final class DomainGateTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
		}

		require_once __DIR__ . '/wp-stubs.php';
		require_once dirname( __DIR__ ) . '/includes/class-wpmar-domain-gate.php';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_wpmar_test_home_url'] );
	}

	// -------------------------------------------------------------------------
	// is_allowed — host matching
	// -------------------------------------------------------------------------

	public function test_is_allowed_empty_allowed_host_is_permissive(): void {
		$GLOBALS['_wpmar_test_home_url'] = 'https://example.com';

		self::assertTrue(
			\WPMAR_Domain_Gate::is_allowed( array( 'domain' => array( 'allowed_host' => '' ) ) )
		);
	}

	public function test_is_allowed_matching_host_passes(): void {
		$GLOBALS['_wpmar_test_home_url'] = 'https://example.com';

		self::assertTrue(
			\WPMAR_Domain_Gate::is_allowed( array( 'domain' => array( 'allowed_host' => 'example.com' ) ) )
		);
	}

	public function test_is_allowed_mismatched_host_blocks(): void {
		$GLOBALS['_wpmar_test_home_url'] = 'https://staging.example.com';

		self::assertFalse(
			\WPMAR_Domain_Gate::is_allowed( array( 'domain' => array( 'allowed_host' => 'example.com' ) ) )
		);
	}

	public function test_is_allowed_host_comparison_is_case_insensitive(): void {
		$GLOBALS['_wpmar_test_home_url'] = 'https://Example.COM';

		self::assertTrue(
			\WPMAR_Domain_Gate::is_allowed( array( 'domain' => array( 'allowed_host' => 'EXAMPLE.COM' ) ) )
		);
	}

	// -------------------------------------------------------------------------
	// is_allowed — path prefix gating
	// -------------------------------------------------------------------------

	public function test_is_allowed_exact_path_prefix_match_passes(): void {
		$GLOBALS['_wpmar_test_home_url'] = 'https://example.com/client/site-a';

		self::assertTrue(
			\WPMAR_Domain_Gate::is_allowed(
				array(
					'domain' => array(
						'allowed_host'        => 'example.com',
						'allowed_path_prefix' => 'client/site-a',
					),
				)
			)
		);
	}

	public function test_is_allowed_different_path_prefix_blocks(): void {
		$GLOBALS['_wpmar_test_home_url'] = 'https://example.com/client/site-b';

		self::assertFalse(
			\WPMAR_Domain_Gate::is_allowed(
				array(
					'domain' => array(
						'allowed_host'        => 'example.com',
						'allowed_path_prefix' => 'client/site-a',
					),
				)
			)
		);
	}

	public function test_is_allowed_subpath_passes_when_prefix_is_ancestor(): void {
		$GLOBALS['_wpmar_test_home_url'] = 'https://example.com/client/site-a/subdir';

		self::assertTrue(
			\WPMAR_Domain_Gate::is_allowed(
				array(
					'domain' => array(
						'allowed_host'        => 'example.com',
						'allowed_path_prefix' => 'client/site-a',
					),
				)
			)
		);
	}

	public function test_is_allowed_prefix_without_separator_does_not_match_partial_segment(): void {
		// 'client/site-ax' must NOT pass a gate configured for 'client/site-a'.
		$GLOBALS['_wpmar_test_home_url'] = 'https://example.com/client/site-ax';

		self::assertFalse(
			\WPMAR_Domain_Gate::is_allowed(
				array(
					'domain' => array(
						'allowed_host'        => 'example.com',
						'allowed_path_prefix' => 'client/site-a',
					),
				)
			)
		);
	}

	public function test_is_allowed_no_path_prefix_configured_skips_path_check(): void {
		$GLOBALS['_wpmar_test_home_url'] = 'https://example.com/anything/here';

		self::assertTrue(
			\WPMAR_Domain_Gate::is_allowed(
				array(
					'domain' => array(
						'allowed_host'        => 'example.com',
						'allowed_path_prefix' => '',
					),
				)
			)
		);
	}

	// -------------------------------------------------------------------------
	// merge_network_gate_settings — fallback logic
	// -------------------------------------------------------------------------

	public function test_merge_network_gate_settings_uses_network_host_when_site_host_empty(): void {
		$site    = array( 'domain' => array( 'allowed_host' => '', 'allowed_path_prefix' => '' ) );
		$network = array( 'domain' => array( 'allowed_host' => 'example.com', 'allowed_path_prefix' => '' ) );

		$merged = \WPMAR_Domain_Gate::merge_network_gate_settings( $site, $network );

		self::assertSame( 'example.com', $merged['domain']['allowed_host'] );
	}

	public function test_merge_network_gate_settings_preserves_site_host_when_set(): void {
		$site    = array( 'domain' => array( 'allowed_host' => 'site.example.com', 'allowed_path_prefix' => '' ) );
		$network = array( 'domain' => array( 'allowed_host' => 'network.example.com', 'allowed_path_prefix' => '' ) );

		$merged = \WPMAR_Domain_Gate::merge_network_gate_settings( $site, $network );

		self::assertSame( 'site.example.com', $merged['domain']['allowed_host'] );
	}

	public function test_merge_network_gate_settings_applies_network_path_prefix(): void {
		$site    = array( 'domain' => array( 'allowed_host' => '', 'allowed_path_prefix' => '' ) );
		$network = array( 'domain' => array( 'allowed_host' => 'example.com', 'allowed_path_prefix' => 'blog/client' ) );

		$merged = \WPMAR_Domain_Gate::merge_network_gate_settings( $site, $network );

		self::assertSame( 'blog/client', $merged['domain']['allowed_path_prefix'] );
	}
}
