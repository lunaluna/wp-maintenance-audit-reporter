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
 * Multisite-only: repeats the per-blog table/option/file cleanup on every blog, then clears
 * the network-wide (sitemeta) data that a single blog's `$wpdb->options` cleanup never
 * reaches.
 *
 * Mirrors the same `get_sites()` + `switch_to_blog()` loop {@see WPMAR_Activator::activate_network()}
 * uses to provision these same per-blog tables, so every blog that got a table on activation
 * gets it dropped here too.
 *
 * The file deletions run inside this loop as well: both storage locations are per-site
 * (`wp_upload_dir()` splits itself, the private base directory gets a `site-{blog_id}`
 * subdirectory), and the private base directory may additionally be filtered per blog, so
 * both have to be resolved under each blog's own context.
 *
 * @param wpdb $wp_db WP database object.
 */
function wpmar_uninstall_multisite_cleanup( $wp_db ) {
	if ( ! function_exists( 'get_sites' ) ) {
		return;
	}

	$sites = get_sites( array( 'number' => 0 ) );

	// Keyed by path so a base directory shared by every blog is only rmdir'd once at the end.
	// A blog-dependent filter (or a relocated install, which adds the well-known default as a
	// second location) can contribute more than one distinct path here.
	$shared_bases = array();

	foreach ( $sites as $site ) {
		if ( ! is_object( $site ) || ! isset( $site->blog_id ) ) {
			continue;
		}

		switch_to_blog( (int) $site->blog_id );
		wpmar_uninstall_drop_tables( $wp_db );
		wpmar_uninstall_cleanup_options_and_cron( $wp_db );
		wpmar_uninstall_delete_uploads();

		foreach ( wpmar_uninstall_delete_private_storage() as $base ) {
			$shared_bases[ $base ] = true;
		}

		restore_current_blog();
	}

	// The `site-{blog_id}` subdirectories are gone now; drop the parent that held them,
	// unless something not ours (or a pre-multisite era leftover) still lives there.
	foreach ( array_keys( $shared_bases ) as $base ) {
		wpmar_uninstall_rmdir_if_empty( $base );
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
 * Removes the uploads/wpmar directory tree of the current blog.
 *
 * This is only the write-fallback location {@see WPMAR_Private_Storage::fallback_base_dir()}
 * uses when the configured private base directory is not writable; the primary location is
 * handled by {@see wpmar_uninstall_delete_private_storage()}. `wp_upload_dir()` is already
 * per-site on multisite, which is why no `site-{blog_id}` segment is appended here — call
 * this once per blog (inside `switch_to_blog()`) instead.
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
 * Resolves the shared private storage base directory, before the per-site segment.
 *
 * Deliberately duplicates {@see WPMAR_Private_Storage::configured_base_dir()} instead of
 * loading that class: `uninstall.php` is included by core with only `WP_UNINSTALL_PLUGIN`
 * defined, so none of this plugin's own classes are available here — the same reason
 * {@see wpmar_uninstall_drop_tables()} restates its table names locally.
 *
 * The `WPMAR_PRIVATE_STORAGE_DIR` constant (`wp-config.php`) and the
 * `wpmar_private_storage_dir` filter (mu-plugin / another plugin / the theme) *are* in
 * effect during uninstall, since both are loaded as part of the normal WP bootstrap.
 *
 * @return string Absolute path without a trailing slash, or '' when unresolvable.
 */
function wpmar_uninstall_private_storage_base_dir() {
	if ( defined( 'WPMAR_PRIVATE_STORAGE_DIR' ) && '' !== trim( (string) WPMAR_PRIVATE_STORAGE_DIR ) ) {
		$base = (string) WPMAR_PRIVATE_STORAGE_DIR;
	} else {
		$base = WP_CONTENT_DIR . '/wpmar-private';
	}

	/** This filter is documented in includes/storage/class-wpmar-private-storage.php */
	$base = (string) apply_filters( 'wpmar_private_storage_dir', $base );
	$base = wp_normalize_path( untrailingslashit( trim( $base ) ) );

	// A filter returning an empty value would otherwise turn into a delete of '/'.
	if ( '' === $base || '/' === $base ) {
		return '';
	}

	return $base;
}

/**
 * The plugin's well-known default private storage base, ignoring constant/filter overrides.
 *
 * Duplicates {@see WPMAR_Private_Storage::default_base_dir()}. Files stay where they were
 * written: an operator who later defines `WPMAR_PRIVATE_STORAGE_DIR` (or adds the filter)
 * only changes where *new* files go, which is exactly why
 * {@see WPMAR_Private_Storage::resolve()} keeps probing this location on read — so uninstall
 * has to clean it too, not just the currently configured base.
 *
 * @return string Absolute path without a trailing slash.
 */
function wpmar_uninstall_private_storage_default_base_dir() {
	return wp_normalize_path( untrailingslashit( WP_CONTENT_DIR . '/wpmar-private' ) );
}

/**
 * Removes the private storage trees (reports/, pdf/, logs/, tmp/) of the current blog.
 *
 * This is the primary storage location introduced in 1.3.1, i.e. everything
 * {@see WPMAR_Private_Storage} writes when the configured base directory is usable. Both the
 * configured base and the well-known default are cleaned, since a relocated install can hold
 * files under either.
 *
 * Unlike the uploads fallback, these base directories are shared network-wide, so on
 * multisite only this blog's `site-{blog_id}` subdirectory is removed here — call this once
 * per blog (inside `switch_to_blog()`, so a blog-dependent filter resolves correctly) and let
 * the caller drop the emptied parents afterwards.
 *
 * @return string[] The resolved shared base directories (no trailing slash) so the caller can
 *                  clean them up once every blog is done; empty when none was resolvable.
 */
function wpmar_uninstall_delete_private_storage() {
	$bases   = array();
	$default = wpmar_uninstall_private_storage_default_base_dir();

	$configured = wpmar_uninstall_private_storage_base_dir();
	if ( '' !== $configured ) {
		$bases[ $configured ] = true;
	}

	// Only a relocated install adds a second location; normally this is the same path.
	if ( '' !== $default ) {
		$bases[ $default ] = true;
	}

	$bases = array_keys( $bases );

	foreach ( $bases as $base ) {
		$dir = is_multisite() ? $base . '/site-' . get_current_blog_id() : $base;
		wpmar_uninstall_rrmdir( $dir );
	}

	return $bases;
}

/**
 * Resolves the external PDF library directory, mirroring `wpmar_pdf_lib_dir()`
 * in wp-maintenance-audit-reporter.php — `uninstall.php` runs with only
 * `WP_UNINSTALL_PLUGIN` defined, so none of this plugin's own code is loaded
 * here (same reason {@see wpmar_uninstall_private_storage_base_dir()} restates
 * its own resolution logic locally instead of loading WPMAR_PDF_Installer).
 *
 * @return string Absolute path without a trailing slash, or '' when unresolvable.
 */
function wpmar_uninstall_pdf_lib_dir() {
	/** This filter is documented in wp-maintenance-audit-reporter.php */
	$dir = (string) apply_filters( 'wpmar_pdf_lib_dir', WP_CONTENT_DIR . '/wpmar-pdf-lib/' );
	$dir = wp_normalize_path( untrailingslashit( trim( $dir ) ) );

	// A filter returning an empty value would otherwise turn into a delete of '/'.
	if ( '' === $dir || '/' === $dir ) {
		return '';
	}

	return $dir;
}

/**
 * Removes the external PDF library directory (`vendor/` + `fonts/`, ~94 MB).
 *
 * Shared network-wide ({@see wpmar_pdf_lib_dir()} in wp-maintenance-audit-reporter.php
 * never appends a `site-{blog_id}` segment) — call this once total, not per blog
 * like {@see wpmar_uninstall_delete_private_storage()}.
 *
 * Gated behind the `wpmar_pdf_lib_delete_on_uninstall` filter (default true) so
 * an operator who wants to keep the ~94 MB library across an uninstall/reinstall
 * cycle (e.g. a staging workflow that reinstalls the plugin often) can opt out
 * via an mu-plugin instead of losing the library and re-downloading it.
 *
 * @return void
 */
function wpmar_uninstall_delete_pdf_lib() {
	if ( ! (bool) apply_filters( 'wpmar_pdf_lib_delete_on_uninstall', true ) ) {
		return;
	}

	$dir = wpmar_uninstall_pdf_lib_dir();
	if ( '' === $dir ) {
		return;
	}

	wpmar_uninstall_rrmdir( $dir );
}

/**
 * Removes a directory only when it has no entries left (uninstall-only).
 *
 * Used for the shared private storage parent: an operator-chosen path (or one holding
 * leftovers this uninstall does not own) must never be removed recursively.
 *
 * @param string $path Absolute path (with or without trailing slash).
 * @return void
 */
function wpmar_uninstall_rmdir_if_empty( $path ) {
	$path = rtrim( (string) $path, '/\\' );

	if ( '' === $path || ! is_dir( $path ) ) {
		return;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_opendir -- uninstall uses direct FS.
	$handle = opendir( $path );
	if ( false === $handle ) {
		return;
	}

	$is_empty = true;
	$filename = readdir( $handle );
	while ( false !== $filename ) {
		if ( '.' !== $filename && '..' !== $filename ) {
			$is_empty = false;
			break;
		}

		$filename = readdir( $handle );
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_closedir -- direct handle.
	closedir( $handle );

	if ( ! $is_empty ) {
		return;
	}

	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Uninstall cleanup; WP_Filesystem may be unavailable during plugin deletion.
	@rmdir( $path );
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
	// Both file deletions run per blog inside this call — see its docblock.
	wpmar_uninstall_multisite_cleanup( $wpdb );
} else {
	wpmar_uninstall_drop_tables( $wpdb );
	wpmar_uninstall_cleanup_options_and_cron( $wpdb );
	wpmar_uninstall_delete_uploads();
	wpmar_uninstall_delete_private_storage();
}

// Shared network-wide regardless of single-site/multisite — run once total,
// outside the per-blog loop above (see wpmar_uninstall_delete_pdf_lib()'s docblock).
wpmar_uninstall_delete_pdf_lib();
