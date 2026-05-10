<?php
/**
 * Plugin Name:       WP Maintenance Audit Reporter
 * Plugin URI:        https://github.com/lunaluna/wp-maintenance-audit-reporter
 * Description:       Monthly maintenance reports for WordPress: core, themes, plugins, deltas, checksums (later), mail, CLI.
 * Version:           0.1.0-dev
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            lunaluna
 * Author URI:        https://github.com/lunaluna
 * License:            GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-maintenance-audit-reporter
 * Domain Path:       /languages
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPMAR_VERSION', '0.1.0-dev' );
define( 'WPMAR_PLUGIN_FILE', __FILE__ );
define( 'WPMAR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPMAR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPMAR_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

define( 'WPMAR_HOOK_SCHEDULED', 'wpmar_run_audit' );

require_once WPMAR_PLUGIN_DIR . 'includes/class-wpmar-activator.php';
require_once WPMAR_PLUGIN_DIR . 'includes/class-wpmar-deactivator.php';

register_activation_hook( __FILE__, array( 'WPMAR_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPMAR_Deactivator', 'deactivate' ) );

require_once WPMAR_PLUGIN_DIR . 'includes/class-wpmar-plugin.php';

/**
 * Loads the plugin main instance.
 *
 * @return \WPMAR_Plugin
 */
function wpmar() {
	return \WPMAR_Plugin::instance();
}

wpmar()->init();
