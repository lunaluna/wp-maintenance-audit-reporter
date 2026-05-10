<?php
/**
 * Plugin deactivation: clears scheduled cron only.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs on deactivate.
 */
class WPMAR_Deactivator {

	/**
	 * Clears scheduled hooks for this plugin.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( WPMAR_HOOK_SCHEDULED );
	}
}
