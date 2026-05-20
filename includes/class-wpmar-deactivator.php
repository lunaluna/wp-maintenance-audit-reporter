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
	 * Clears scheduled hooks for this plugin on the current blog.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( WPMAR_HOOK_SCHEDULED );
	}

	/**
	 * Clears cron hooks on every blog in a multisite network.
	 *
	 * @return void
	 */
	public static function deactivate_network() {
		if ( ! function_exists( 'get_sites' ) ) {
			self::deactivate();
			return;
		}

		foreach ( get_sites( array( 'number' => 0 ) ) as $site ) {
			if ( ! is_object( $site ) || ! isset( $site->blog_id ) ) {
				continue;
			}
			switch_to_blog( (int) $site->blog_id );
			self::deactivate();
			restore_current_blog();
		}
	}
}
