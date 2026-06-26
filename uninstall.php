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
 * Drops custom tables.
 *
 * @param wpdb $wp_db WP database object.
 */
function wpmar_uninstall_drop_tables( $wp_db ) {
	$wp_db->suppress_errors();

	$reports_table   = sprintf( '`%s`', esc_sql( $wp_db->prefix . 'wpmar_reports' ) );
	$snapshots_table = sprintf( '`%s`', esc_sql( $wp_db->prefix . 'wpmar_snapshots' ) );
	$jobs_table      = sprintf( '`%s`', esc_sql( $wp_db->prefix . 'wpmar_jobs' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery -- uninstall cleanup.
	$wp_db->query( "DROP TABLE IF EXISTS {$reports_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery -- uninstall cleanup.
	$wp_db->query( "DROP TABLE IF EXISTS {$snapshots_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery -- uninstall cleanup.
	$wp_db->query( "DROP TABLE IF EXISTS {$jobs_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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

wpmar_uninstall_drop_tables( $wpdb );
wpmar_uninstall_cleanup_options_and_cron( $wpdb );
wpmar_uninstall_delete_uploads();
