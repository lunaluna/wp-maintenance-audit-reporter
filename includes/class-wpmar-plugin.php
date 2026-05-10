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
		add_action(
			'plugins_loaded',
			function () {
				load_plugin_textdomain(
					'wp-maintenance-audit-reporter',
					false,
					dirname( WPMAR_PLUGIN_BASENAME ) . '/languages/'
				);
			},
			1
		);

		add_action(
			'plugins_loaded',
			array( $this, 'later_init' ),
			5
		);
	}

	/**
	 * Placeholder for admin, cron, CLI, runner (later v0.1 tasks).
	 *
	 * @return void
	 */
	public function later_init() {
		/**
		 * Fires after WP Maintenance Audit Reporter bootstrap.
		 *
		 * @since 0.1.0
		 */
		do_action( 'wpmar_loaded' );
	}
}
