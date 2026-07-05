<?php
/**
 * Plugin Name:       WP Maintenance Audit Reporter
 * Plugin URI:        https://github.com/lunaluna/wp-maintenance-audit-reporter
 * Description:       Monthly maintenance reports for WordPress: core, themes, plugins, deltas, checksums, security ops, mail, CLI.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Tested up to:      7.0.1
 * Requires PHP:      7.4
 * Network:           true
 * Author:            lunaluna_dev
 * Author URI:        https://profiles.wordpress.org/lunaluna_dev/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-maintenance-audit-reporter
 * Domain Path:       /languages
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPMAR_VERSION', '1.0.0' );
define( 'WPMAR_PLUGIN_FILE', __FILE__ );
define( 'WPMAR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPMAR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPMAR_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

define( 'WPMAR_HOOK_SCHEDULED', 'wpmar_run_audit' );
define( 'WPMAR_HOOK_NETWORK_MANUAL_RUN', 'wpmar_run_network_audit_manual' );
define( 'WPMAR_ADMIN_PAGE_SLUG', 'wpmar-maintenance-report' );
define( 'WPMAR_REPORTS_PAGE_SLUG', 'wpmar-reports' );
define( 'WPMAR_NETWORK_ADMIN_PAGE_SLUG', 'wpmar-network-maintenance-report' );

/*
 * Action Scheduler must load at plugin-file inclusion time — before the
 * `plugins_loaded` hook — so its own `plugins_loaded` (priority 0) bootstrap can
 * register and define the `as_*` queue API. Requiring it later (e.g. inside our
 * own `plugins_loaded` priority-5 bootstrap) is too late: the priority-0 slot has
 * already passed and the API never initialises. Defensive by design: when the
 * library is absent the plugin keeps working through the synchronous CLI/cron paths.
 */
wpmar_maybe_load_action_scheduler();

/**
 * Includes loaded for activation hooks and runtime.
 *
 * @return array<int, string>
 */
function wpmar_get_include_manifest() {
	return array(
		'includes/class-wpmar-settings.php',
		'includes/class-wpmar-network-settings.php',
		'includes/class-wpmar-network.php',
		'includes/class-wpmar-activator.php',
		'includes/class-wpmar-domain-gate.php',
		'includes/checks/class-wpmar-check-checksums.php',
		'includes/checks/class-wpmar-check-security-ops.php',
		'includes/checks/class-wpmar-check-performance.php',
		'includes/api/class-wpmar-wporg-client.php',
		'includes/api/class-wpmar-jobs-rest.php',
		'includes/storage/class-wpmar-snapshot-repository.php',
		'includes/storage/class-wpmar-report-repository.php',
		'includes/storage/class-wpmar-jobs-repository.php',
		'includes/storage/class-wpmar-md-writer.php',
		'includes/storage/class-wpmar-pdf-writer.php',
		'includes/storage/class-wpmar-report-zip-export.php',
		'includes/class-wpmar-data-collector.php',
		'includes/notify/class-wpmar-notifier-mail.php',
		'includes/notify/class-wpmar-notification-dispatcher.php',
		'includes/class-wpmar-cli-environment.php',
		'includes/class-wpmar-runner.php',
		'includes/class-wpmar-network-runner.php',
		'includes/class-wpmar-job-dispatcher.php',
		'includes/class-wpmar-scheduler.php',
		'includes/admin/class-wpmar-admin-menu.php',
		'includes/admin/class-wpmar-settings-page.php',
		'includes/admin/class-wpmar-network-admin-menu.php',
		'includes/admin/class-wpmar-network-settings-page.php',
		'includes/admin/class-wpmar-reports-list-table.php',
		'includes/admin/class-wpmar-reports-page.php',
		'includes/admin/class-wpmar-pdf-installer.php',
		'includes/class-wpmar-github-updater.php',
	);
}

/**
 * Loads feature modules once (Activator, runtime bootstrap, WP-CLI, etc.).
 *
 * @return void
 */
function wpmar_require_includes_once() {
	static $loaded = false;
	if ( $loaded ) {
		return;
	}

	$autoload = WPMAR_PLUGIN_DIR . 'vendor/autoload.php';
	if ( is_readable( $autoload ) ) {
		require_once $autoload;
	}

	foreach ( wpmar_get_include_manifest() as $relative_path ) {
		require_once WPMAR_PLUGIN_DIR . $relative_path;
	}

	$loaded = true;
}

/**
 * Requires the Action Scheduler bootstrap if present in any known location.
 *
 * Candidate paths cover both delivery options kept open during design:
 * a committed `lib/` copy and a Composer-managed `vendor/` install.
 *
 * @return void
 */
function wpmar_maybe_load_action_scheduler() {
	if ( function_exists( 'as_enqueue_async_action' ) ) {
		return; // Already loaded (e.g. by another plugin bundling Action Scheduler).
	}

	$candidates = array(
		'lib/action-scheduler/action-scheduler.php',
		'vendor/woocommerce/action-scheduler/action-scheduler.php',
	);

	foreach ( $candidates as $relative_path ) {
		$absolute = WPMAR_PLUGIN_DIR . $relative_path;
		if ( is_readable( $absolute ) ) {
			require_once $absolute;
			return;
		}
	}
}

/**
 * Whether Action Scheduler is loaded and its async API is callable.
 *
 * Guards every enqueue call site so async features degrade gracefully when the
 * library has not yet been shipped to this install.
 *
 * @return bool
 */
function wpmar_action_scheduler_available() {
	return function_exists( 'as_enqueue_async_action' );
}

/**
 * Plugin activation hook: schema + defaults + cron anchor.
 *
 * @param bool $network_wide Whether the plugin is being network-activated.
 * @return void
 */
function wpmar_activate_plugin( $network_wide = false ) {
	wpmar_require_includes_once();
	if ( is_multisite() && $network_wide ) {
		WPMAR_Activator::activate_network();
		return;
	}
	WPMAR_Activator::activate();
}

/**
 * Removes scheduled Cron hook only (minimal scrape footprint).
 *
 * @param bool $network_wide Whether the plugin is being network-deactivated.
 * @return void
 */
function wpmar_deactivate_plugin( $network_wide = false ) {
	require_once WPMAR_PLUGIN_DIR . 'includes/class-wpmar-deactivator.php';
	if ( is_multisite() && $network_wide ) {
		WPMAR_Deactivator::deactivate_network();
		return;
	}
	WPMAR_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'wpmar_activate_plugin' );
register_deactivation_hook( __FILE__, 'wpmar_deactivate_plugin' );

/**
 * Normal runtime bootstrap after WordPress completes `plugins_loaded`.
 *
 * @return void
 */
function wpmar_bootstrap_on_plugins_loaded() {
	wpmar_require_includes_once();
	wpmar()->init();
}

add_action( 'plugins_loaded', 'wpmar_bootstrap_on_plugins_loaded', 5 );

/**
 * Returns the singleton; loads the main class file on first use.
 *
 * @return \WPMAR_Plugin
 */
function wpmar() {
	require_once WPMAR_PLUGIN_DIR . 'includes/class-wpmar-plugin.php';

	return \WPMAR_Plugin::instance();
}
