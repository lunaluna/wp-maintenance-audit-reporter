<?php
/**
 * Uninstall: deletes all plugin data when removed from Plugins screen.
 *
 * @package WPMAR
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/**
 * Drops custom tables on the current blog (call once per blog on multisite).
 *
 * @param wpdb $wp_db WP database object.
 */
function wpmar_uninstall_drop_tables( $wp_db ) {
	$wp_db->suppress_errors();

	$tables = array(
		$wp_db->prefix . 'wpmar_reports',
		$wp_db->prefix . 'wpmar_snapshots',
		$wp_db->prefix . 'wpmar_jobs',
		$wp_db->prefix . 'wpmar_network_segments',
	);

	foreach ( $tables as $table ) {
		$escaped = sprintf( '`%s`', esc_sql( $table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery -- uninstall cleanup.
		$wp_db->query( "DROP TABLE IF EXISTS {$escaped}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// Action Scheduler's own tables (actionscheduler_*) are intentionally left intact:
	// the library may be shared with other plugins (e.g. WooCommerce) and manages its
	// own teardown.
}

/**
 * Deletes options and cron events.
 *
 * @param wpdb $wp_db WP database object.
 */
function wpmar_uninstall_cleanup_options_and_cron( $wp_db ) {
	wp_clear_scheduled_hook( 'wpmar_run_audit' );

	delete_option( 'wpmar_settings' );
	delete_option( 'wpmar_cli_environment' );
	delete_option( 'wpmar_db_version' );
	delete_transient( 'wpmar_run_lock' );
	delete_site_transient( 'wpmar_run_lock' );

	$patterns = array(
		$wp_db->esc_like( 'wpmar_' ) . '%',
		$wp_db->esc_like( '_transient_wpmar_' ) . '%',
		$wp_db->esc_like( '_transient_timeout_wpmar_' ) . '%',
		$wp_db->esc_like( '_site_transient_wpmar_' ) . '%',
		$wp_db->esc_like( '_site_transient_timeout_wpmar_' ) . '%',
	);

	foreach ( $patterns as $like ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall batch delete.
		$wp_db->query(
			$wp_db->prepare(
				"DELETE FROM {$wp_db->options} WHERE option_name LIKE %s",
				$like
			)
		);
	}
}

/**
 * Multisite-only: repeats the per-blog table/option cleanup on every blog, then clears
 * the network-wide (sitemeta) data that a single blog's `$wpdb->options` cleanup never
 * reaches.
 *
 * Mirrors the same `get_sites()` + `switch_to_blog()` loop {@see WPMAR_Activator::activate_network()}
 * uses to provision these same per-blog tables, so every blog that got a table on activation
 * gets it dropped here too.
 *
 * @param wpdb $wp_db WP database object.
 */
function wpmar_uninstall_multisite_cleanup( $wp_db ) {
	if ( ! function_exists( 'get_sites' ) ) {
		return;
	}

	$sites = get_sites( array( 'number' => 0 ) );

	foreach ( $sites as $site ) {
		if ( ! is_object( $site ) || ! isset( $site->blog_id ) ) {
			continue;
		}

		switch_to_blog( (int) $site->blog_id );
		wpmar_uninstall_drop_tables( $wp_db );
		wpmar_uninstall_cleanup_options_and_cron( $wp_db );
		restore_current_blog();
	}

	wpmar_uninstall_cleanup_sitemeta( $wp_db );
}

/**
 * Deletes network-wide (sitemeta) settings.
 *
 * `wpmar_network_settings` / `wpmar_last_network_audit_completed_at` / `wpmar_wp_cron_last_fired_at`
 * are written via `update_site_option()`, which lands in `$wpdb->sitemeta` — a table the
 * per-blog `$wpdb->options` LIKE cleanup in {@see wpmar_uninstall_cleanup_options_and_cron()}
 * never touches.
 *
 * @param wpdb $wp_db WP database object.
 */
function wpmar_uninstall_cleanup_sitemeta( $wp_db ) {
	delete_site_option( 'wpmar_network_settings' );
	delete_site_option( 'wpmar_last_network_audit_completed_at' );
	delete_site_option( 'wpmar_wp_cron_last_fired_at' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall batch delete.
	$wp_db->query(
		$wp_db->prepare(
			"DELETE FROM {$wp_db->sitemeta} WHERE meta_key LIKE %s",
			$wp_db->esc_like( 'wpmar_' ) . '%'
		)
	);
}

/**
 * Removes uploads/wpmar directory tree.
 *
 * @return void
 */
function wpmar_uninstall_delete_uploads() {
	if ( ! function_exists( 'wp_upload_dir' ) ) {
		return;
	}

	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['error'] ) ) {
		return;
	}

	$dir = trailingslashit( $uploads['basedir'] ) . 'wpmar';
	if ( ! is_dir( $dir ) ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';

	wpmar_uninstall_rrmdir( $dir );
}

/**
 * Recursively removes a directory (uninstall-only).
 *
 * @param string $path Absolute path (with or without trailing slash).
 * @return void
 */
function wpmar_uninstall_rrmdir( $path ) {
	$path = rtrim( $path, '/\\' ) . DIRECTORY_SEPARATOR;

	if ( ! is_dir( $path ) ) {
		return;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_opendir -- uninstall uses direct FS.
	$handle = opendir( $path );
	if ( false === $handle ) {
		return;
	}

	// phpcs:ignore Generic.CodeAnalysis.JumbledIncrementer.Increment,JumbledDecrement -- readdir loop.
	$filename = readdir( $handle );
	while ( false !== $filename ) {
		if ( '.' === $filename || '..' === $filename ) {
			$filename = readdir( $handle );
			continue;
		}

		$item = $path . $filename;
		if ( is_dir( $item ) ) {
			wpmar_uninstall_rrmdir( $item );
		} elseif ( file_exists( $item ) && function_exists( 'wp_delete_file' ) ) {
			wp_delete_file( $item );
		}

		$filename = readdir( $handle );
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_closedir -- direct handle.
	closedir( $handle );

	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Uninstall cleanup; WP_Filesystem may be unavailable during plugin deletion.
	@rmdir( rtrim( $path, '/\\' ) );
}

if ( is_multisite() ) {
	wpmar_uninstall_multisite_cleanup( $wpdb );
} else {
	wpmar_uninstall_drop_tables( $wpdb );
	wpmar_uninstall_cleanup_options_and_cron( $wpdb );
}

wpmar_uninstall_delete_uploads();
