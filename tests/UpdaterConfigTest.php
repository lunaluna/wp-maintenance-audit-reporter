<?php
/**
 * Wiring test for the shared updater library (l2d-wp-github-update-lib).
 *
 * Boots lib/l2d-updater/loader.php with the exact config WPMAR passes in
 * wp-maintenance-audit-reporter.php, then asserts the resulting
 * L2dwpghul_GitHub_Updater instance registered exactly the 5 expected hooks
 * and holds the config values WPMAR relies on (cache_key / filter_prefix /
 * slug / TTLs), via Reflection. Purpose: catch a future `git subtree pull`
 * silently changing the library's defaults or config keys — the library's
 * own ~54 tests cover HTTP/cache/asset-selection behaviour, so this suite
 * does not re-test that.
 *
 * Runs with process isolation because it flips the shared
 * $GLOBALS['_wpmar_test_apply_filters_functional'] switch (see wp-stubs.php)
 * to verify the legacy filter names actually change a return value — every
 * other test file relies on apply_filters() staying a no-op pass-through.
 *
 * @package WPMAR\Tests
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

require_once __DIR__ . '/wp-stubs.php';

/**
 * The 5 hooks L2dwpghul_GitHub_Updater::init() must register — see
 * lib/l2d-updater/class-l2d-github-updater.php:161-167.
 */
const EXPECTED_HOOKS = array(
	'pre_set_site_transient_update_plugins',
	'plugins_api',
	'upgrader_process_complete',
	'upgrader_source_selection',
	'upgrader_pre_download',
);

final class UpdaterConfigTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_wpmar_test_filters']                  = array();
		$GLOBALS['_wpmar_test_site_transients']          = array();
		$GLOBALS['_wpmar_test_plugin_basename']          = 'wp-maintenance-audit-reporter/wp-maintenance-audit-reporter.php';
		$GLOBALS['_wpmar_test_apply_filters_functional'] = true;

		// l2dwpghul_updater_boot() never clears the registry it reads from, so
		// without this reset a leftover config from an earlier test would get
		// re-booted alongside this test's config (observed in this sandbox:
		// @runTestsInSeparateProcesses did not actually isolate processes,
		// so the global registry below survived across test methods).
		global $l2dwpghul_updater_registry;
		$l2dwpghul_updater_registry = null;

		// require (not require_once), matching the contract documented at the
		// top of loader.php: require_once would make the 2nd+ registering
		// copy get `true` as its return value instead of the config closure.
		$register = require dirname( __DIR__ ) . '/lib/l2d-updater/loader.php';
		$register(
			array(
				'plugin_file'   => __DIR__ . '/fixtures/plugin-header-fixture.php',
				'github_repo'   => 'lunaluna/wp-maintenance-audit-reporter',
				'cache_key'     => 'wpmar_github_release_cache',
				'filter_prefix' => 'wpmar',
			)
		);
		\l2dwpghul_updater_boot();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['_wpmar_test_filters'],
			$GLOBALS['_wpmar_test_site_transients'],
			$GLOBALS['_wpmar_test_plugin_basename'],
			$GLOBALS['_wpmar_test_apply_filters_functional']
		);
		parent::tearDown();
	}

	/**
	 * Finds the L2dwpghul_GitHub_Updater instance booted in setUp() by
	 * pulling it out of one of its own hook registrations.
	 *
	 * @return \L2dwpghul_GitHub_Updater
	 */
	private function updater_instance() {
		foreach ( $GLOBALS['_wpmar_test_filters'] as $registration ) {
			if ( 'add' !== $registration[0] || 'plugins_api' !== $registration[1] ) {
				continue;
			}
			$callback = $registration[3];
			if ( is_array( $callback ) && $callback[0] instanceof \L2dwpghul_GitHub_Updater ) {
				return $callback[0];
			}
		}

		$this->fail( 'Could not locate the L2dwpghul_GitHub_Updater instance from registered hooks.' );
	}

	/**
	 * @param \L2dwpghul_GitHub_Updater $instance Instance to read from.
	 * @param string                    $property Private property name.
	 * @return mixed
	 */
	private function read_property( $instance, $property ) {
		$reflection = new ReflectionProperty( \L2dwpghul_GitHub_Updater::class, $property );
		$reflection->setAccessible( true );
		return $reflection->getValue( $instance );
	}

	public function test_old_updater_class_is_fully_removed(): void {
		$this->assertFalse(
			class_exists( 'WPMAR_GitHub_Updater' ),
			'The old WPMAR_GitHub_Updater class must be removed once migrated to the shared library.'
		);
	}

	public function test_registers_each_of_the_five_hooks_exactly_once(): void {
		foreach ( EXPECTED_HOOKS as $hook ) {
			$count = 0;
			foreach ( $GLOBALS['_wpmar_test_filters'] as $registration ) {
				if ( 'add' === $registration[0] && $hook === $registration[1] ) {
					++$count;
				}
			}
			$this->assertSame( 1, $count, "Expected exactly one registration for {$hook}." );
		}
	}

	public function test_config_values_match_wpmar_expectations(): void {
		$instance = $this->updater_instance();

		$this->assertSame( 'lunaluna/wp-maintenance-audit-reporter', $this->read_property( $instance, 'github_repo' ) );
		$this->assertSame( 'wp-maintenance-audit-reporter', $this->read_property( $instance, 'slug' ) );
		$this->assertSame( 'wp-maintenance-audit-reporter', $this->read_property( $instance, 'asset_pattern' ) );
		$this->assertSame( 'wpmar_github_release_cache', $this->read_property( $instance, 'cache_key' ) );
		$this->assertSame( 'wpmar', $this->read_property( $instance, 'filter_prefix' ) );
		$this->assertSame( 21600, $this->read_property( $instance, 'cache_ttl' ) );
		$this->assertSame( 1800, $this->read_property( $instance, 'backoff_ttl' ) );
		$this->assertSame( '', $this->read_property( $instance, 'token' ) );
		$this->assertFalse( $this->read_property( $instance, 'allow_prerelease' ) );
	}

	public function test_legacy_filter_name_still_overrides_cache_ttl(): void {
		$instance = $this->updater_instance();
		$method   = new ReflectionMethod( \L2dwpghul_GitHub_Updater::class, 'get_cache_ttl' );
		$method->setAccessible( true );

		$this->assertSame( 21600, $method->invoke( $instance ) );

		add_filter(
			'wpmar_github_updater_cache_ttl',
			static function () {
				return 60;
			}
		);

		$this->assertSame( 60, $method->invoke( $instance ) );
	}

	public function test_legacy_filter_name_still_overrides_backoff_ttl(): void {
		$instance = $this->updater_instance();
		$method   = new ReflectionMethod( \L2dwpghul_GitHub_Updater::class, 'get_backoff_ttl' );
		$method->setAccessible( true );

		$this->assertSame( 1800, $method->invoke( $instance ) );

		add_filter(
			'wpmar_github_updater_backoff_ttl',
			static function () {
				return 90;
			}
		);

		$this->assertSame( 90, $method->invoke( $instance ) );
	}
}
