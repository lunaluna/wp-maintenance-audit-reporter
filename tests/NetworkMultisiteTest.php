<?php
/**
 * PHPUnit coverage for multisite settings and markup merge helpers.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Asserts network settings shape and rollup merge helpers.
 *
 * @coversNothing
 */
final class NetworkMultisiteTest extends TestCase {

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
		require_once dirname( __DIR__ ) . '/includes/class-wpmar-domain-gate.php';
		require_once dirname( __DIR__ ) . '/includes/class-wpmar-runner.php';
	}

	/**
	 * Network defaults expose site filter and path prefix keys.
	 *
	 * @return void
	 */
	public function test_network_settings_defaults_include_site_filters(): void {
		$defaults = \WPMAR_Network_Settings::defaults();

		self::assertFalse( (bool) $defaults['network_audit_enabled'] );
		self::assertArrayHasKey( 'sites', $defaults );
		self::assertSame( 100, $defaults['sites']['max_sites'] );
		self::assertArrayHasKey( 'domain', $defaults );
		self::assertArrayHasKey( 'allowed_path_prefix', $defaults['domain'] );
	}

	/**
	 * Site-level gate inherits network host/path when site host is empty.
	 *
	 * @return void
	 */
	public function test_merge_network_gate_settings_falls_back_to_network_host(): void {
		$site    = array(
			'domain' => array(
				'allowed_host'        => '',
				'allowed_path_prefix' => '',
			),
		);
		$network = array(
			'domain' => array(
				'allowed_host'        => 'Example.COM',
				'allowed_path_prefix' => 'client/a',
			),
		);

		$merged = \WPMAR_Domain_Gate::merge_network_gate_settings( $site, $network );

		self::assertSame( 'Example.COM', $merged['domain']['allowed_host'] );
		self::assertSame( 'client/a', $merged['domain']['allowed_path_prefix'] );
	}

	/**
	 * Client merge wraps each site segment with a heading and separator.
	 *
	 * @return void
	 */
	public function test_merge_network_client_markup_wraps_site_sections(): void {
		$merged = \WPMAR_Runner::merge_network_client_markup(
			array(
				array(
					'blog_id'        => 2,
					'site_name'      => 'Child',
					'home_url'       => 'https://example.com/child/',
					'domain_gate_ok' => true,
					'client_body'    => "Hello client\n",
				),
				array(
					'blog_id'        => 3,
					'site_name'      => 'Other',
					'home_url'       => 'https://example.com/other/',
					'domain_gate_ok' => true,
					'client_body'    => "Second site\n",
				),
			)
		);

		self::assertStringContainsString( 'Child', $merged );
		self::assertStringContainsString( 'https://example.com/child/', $merged );
		self::assertStringContainsString( 'Hello client', $merged );
		self::assertStringContainsString( '---', $merged );
	}
}
