<?php
/**
 * Main plugin singleton.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstraps the plugin after WordPress loads.
 */
class WPMAR_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var WPMAR_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton.
	 *
	 * @return WPMAR_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Loads text domain and registers hooks (extended in v0.1).
	 *
	 * @return void
	 */
	public function init() {
		WPMAR_Activator::upgrade_database_if_needed();

		load_plugin_textdomain(
			'wp-maintenance-audit-reporter',
			false,
			dirname( WPMAR_PLUGIN_BASENAME ) . '/languages/'
		);

		WPMAR_Scheduler::init();
		WPMAR_Job_Dispatcher::init();

		if ( is_multisite() ) {
			WPMAR_Network_Admin_Menu::init();
			add_action( 'wp_initialize_site', array( 'WPMAR_Activator', 'activate_new_site' ), 10, 1 );
		}

		// Register upgrade hooks in every context (admin, WP-CLI, auto-update cron) so the
		// on-demand PDF library (vendor/) survives all plugin update paths.
		WPMAR_PDF_Installer::register_upgrade_hooks();

		if ( is_admin() ) {
			WPMAR_Admin_Menu::init();
		}

		WPMAR_GitHub_Updater::init();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once WPMAR_PLUGIN_DIR . 'includes/cli/class-wpmar-cli-command.php';
			require_once WPMAR_PLUGIN_DIR . 'includes/cli/class-wpmar-cli-audit-command.php';
		}

		/**
		 * Fires after WP Maintenance Audit Reporter bootstrap.
		 *
		 * @since 0.1.0
		 */
		do_action( 'wpmar_loaded' );
	}
}
