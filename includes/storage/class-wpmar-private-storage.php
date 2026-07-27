<?php
/**
 * Resolves and protects the storage location for reports, PDFs, and logs.
 *
 * Reports, PDFs, and diagnostics logs contain administrator email addresses,
 * plugin/theme/core version inventories, and other information that is a
 * reconnaissance goldmine if it leaks. This class is the single place that
 * decides where those artefacts live and enforces the layered defenses:
 *
 *   1. A random token in every filename (the only defense that does not
 *      depend on the web server honoring `.htaccess`).
 *   2. `.htaccess` (`Require all denied` / `Deny from all`) + `index.php` in
 *      every directory it manages.
 *   3. A base directory outside `wp_upload_dir()` by default — `uploads` is
 *      routinely exposed by CDNs, sync tools, and backup publishing that
 *      operators configure without thinking about this plugin's output.
 *   4. An escape hatch (`WPMAR_PRIVATE_STORAGE_DIR`) to move the base
 *      directory outside the document root entirely.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the private storage base/subdirectories and the `private:`-prefixed
 * relative path format persisted in the `md_file_path` / `pdf_file_path` /
 * `log_path` DB columns.
 */
class WPMAR_Private_Storage {

	/** Subdirectory names under the resolved base directory. */
	const SUBDIR_REPORTS = 'reports';
	const SUBDIR_PDF     = 'pdf';
	const SUBDIR_LOGS    = 'logs';
	const SUBDIR_TMP     = 'tmp';

	/** Marks a DB-stored path as relative to this class's base directory (new format). */
	const PREFIX = 'private:';

	/** Option recording whether the uploads/ fallback is currently in use, for the admin notice. */
	const OPTION_FALLBACK_ACTIVE = 'wpmar_storage_fallback_active';

	/**
	 * Resolves + creates the site-scoped base directory, falling back to
	 * `uploads/wpmar/` when the configured location is not writable.
	 *
	 * @return string|WP_Error Trailing-slashed absolute path, or WP_Error on total failure.
	 */
	public static function base_dir() {
		$configured = self::configured_base_dir();

		if ( self::ensure_dir( $configured ) ) {
			self::clear_fallback_active();
			return $configured;
		}

		$fallback = self::fallback_base_dir();
		if ( is_wp_error( $fallback ) ) {
			return $fallback;
		}

		self::mark_fallback_active();

		return $fallback;
	}

	/**
	 * The configured (non-fallback) base directory, before it is created/verified.
	 *
	 * @return string Trailing-slashed absolute path.
	 */
	protected static function configured_base_dir() {
		if ( defined( 'WPMAR_PRIVATE_STORAGE_DIR' ) && '' !== trim( (string) WPMAR_PRIVATE_STORAGE_DIR ) ) {
			$base = (string) WPMAR_PRIVATE_STORAGE_DIR;
		} else {
			$base = WP_CONTENT_DIR . '/wpmar-private';
		}

		/**
		 * Filters the configured (pre-fallback) private storage base directory.
		 *
		 * @param string $base Absolute path, before the per-site subdirectory is appended.
		 */
		$base = (string) apply_filters( 'wpmar_private_storage_dir', $base );
		$base = wp_normalize_path( untrailingslashit( $base ) );

		if ( is_multisite() ) {
			// WP_CONTENT_DIR (and any custom override) is shared network-wide, unlike
			// wp_upload_dir(), which WordPress already splits per site.
			$base .= '/site-' . get_current_blog_id();
		}

		return trailingslashit( $base );
	}

	/**
	 * The uploads-based fallback directory used when the configured base is not writable.
	 *
	 * Reuses {@see WPMAR_MD_Writer::uploads_base_dir()} (already per-site via
	 * `wp_upload_dir()`, so no additional `site-{id}` split is needed here).
	 *
	 * @return string|WP_Error Trailing-slashed absolute path, or WP_Error.
	 */
	protected static function fallback_base_dir() {
		return WPMAR_MD_Writer::uploads_base_dir();
	}

	/**
	 * Whether the uploads/ fallback is active for this site right now.
	 *
	 * @return bool
	 */
	public static function is_fallback_active() {
		return '1' === get_option( self::OPTION_FALLBACK_ACTIVE );
	}

	/**
	 * Records that the uploads/ fallback is in use, for the admin notice.
	 *
	 * @return void
	 */
	protected static function mark_fallback_active() {
		if ( '1' !== get_option( self::OPTION_FALLBACK_ACTIVE ) ) {
			update_option( self::OPTION_FALLBACK_ACTIVE, '1', false );
		}
	}

	/**
	 * Clears the fallback flag once the configured base directory works again.
	 *
	 * @return void
	 */
	protected static function clear_fallback_active() {
		if ( false !== get_option( self::OPTION_FALLBACK_ACTIVE, false ) ) {
			delete_option( self::OPTION_FALLBACK_ACTIVE );
		}
	}

	/**
	 * The protected directory for admin-facing Markdown reports.
	 *
	 * @return string|WP_Error Trailing-slashed absolute path, or WP_Error.
	 */
	public static function reports_dir() {
		return self::sub_dir( self::SUBDIR_REPORTS );
	}

	/**
	 * The protected directory for client-facing PDFs.
	 *
	 * @return string|WP_Error Trailing-slashed absolute path, or WP_Error.
	 */
	public static function pdf_dir() {
		return self::sub_dir( self::SUBDIR_PDF );
	}

	/**
	 * The protected directory for per-job diagnostics logs.
	 *
	 * @return string|WP_Error Trailing-slashed absolute path, or WP_Error.
	 */
	public static function logs_dir() {
		return self::sub_dir( self::SUBDIR_LOGS );
	}

	/**
	 * The protected directory for mPDF's temporary render workspace.
	 *
	 * @return string|WP_Error Trailing-slashed absolute path, or WP_Error.
	 */
	public static function tmp_dir() {
		return self::sub_dir( self::SUBDIR_TMP );
	}

	/**
	 * Resolves + creates a named subdirectory under the base directory.
	 *
	 * @param string $name One of the `SUBDIR_*` constants.
	 * @return string|WP_Error Trailing-slashed absolute path, or WP_Error.
	 */
	protected static function sub_dir( $name ) {
		$base = self::base_dir();
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$dir = trailingslashit( trailingslashit( $base ) . $name );

		if ( ! self::ensure_dir( $dir ) ) {
			return new WP_Error(
				'wpmar_storage_subdir_failed',
				__( 'プライベート保存先のサブディレクトリを作成できませんでした。', 'wp-maintenance-audit-reporter' )
			);
		}

		return $dir;
	}

	/**
	 * Creates (if needed) and protects a directory; reports whether it is usable.
	 *
	 * @param string $dir Trailing-slashed absolute directory path.
	 * @return bool True when the directory exists and is writable.
	 */
	protected static function ensure_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- lightweight pre-flight; WP_Filesystem not yet initialised at this point.
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return false;
		}

		self::seed_protection_files( $dir );

		return true;
	}

	/**
	 * Writes `.htaccess` (Apache) and an empty `index.php` guard into a directory.
	 *
	 * Defense in depth only: the primary protection is the unguessable random token in
	 * every filename, since local/dev environments frequently run nginx where `.htaccess`
	 * has no effect at all.
	 *
	 * @param string $dir Trailing-slashed absolute directory path.
	 * @return void
	 */
	public static function seed_protection_files( $dir ) {
		$htaccess = $dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- one-time guard file inside our own protected directory.
			file_put_contents( $htaccess, $rules );
		}

		$index = $dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- one-time guard file inside our own protected directory.
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * A random, filesystem-safe token for use in generated filenames.
	 *
	 * @return string 20 alphanumeric characters.
	 */
	public static function generate_token() {
		return sanitize_file_name( wp_generate_password( 20, false, false ) );
	}

	/**
	 * Converts an absolute path already inside the base directory to the
	 * `private:`-prefixed form persisted in the DB.
	 *
	 * @param string $absolute Absolute path returned by {@see self::reports_dir()} et al.
	 * @return string `private:`-prefixed relative path, or '' if not inside the base directory.
	 */
	public static function relative_for_storage( $absolute ) {
		$base = self::base_dir();
		if ( is_wp_error( $base ) ) {
			return '';
		}

		$base_n     = wp_normalize_path( trailingslashit( $base ) );
		$absolute_n = wp_normalize_path( (string) $absolute );

		if ( 0 !== strpos( $absolute_n, $base_n ) ) {
			return '';
		}

		return self::PREFIX . substr( $absolute_n, strlen( $base_n ) );
	}

	/**
	 * Resolves a DB-stored path to an absolute filesystem path.
	 *
	 * Accepts both the new `private:`-prefixed format (relative to the base
	 * directory resolved by {@see self::base_dir()}) and the legacy v1.3.0
	 * format (a bare `wp_upload_dir()`-relative fragment, read-only support).
	 *
	 * @param string $stored Value from `md_file_path` / `pdf_file_path` / `log_path`.
	 * @return string Absolute path, or '' when invalid, unresolvable, or escaping its root.
	 */
	public static function resolve( $stored ) {
		$stored = is_string( $stored ) ? trim( $stored ) : '';
		if ( '' === $stored ) {
			return '';
		}

		if ( 0 === strpos( $stored, self::PREFIX ) ) {
			return self::resolve_relative_to( substr( $stored, strlen( self::PREFIX ) ), self::base_dir() );
		}

		return self::resolve_legacy_upload_relative( $stored );
	}

	/**
	 * Deletes the file a stored path resolves to, if any. Silent no-op otherwise.
	 *
	 * @param string $stored Value from `md_file_path` / `pdf_file_path` / `log_path`.
	 * @return void
	 */
	public static function delete( $stored ) {
		$abs = self::resolve( $stored );
		if ( '' !== $abs && file_exists( $abs ) && is_file( $abs ) ) {
			wp_delete_file( $abs );
		}
	}

	/**
	 * Resolves a fragment against a given base directory, rejecting traversal/symlink escape.
	 *
	 * @param string          $relative Fragment relative to $base (no `private:` prefix).
	 * @param string|WP_Error $base     Trailing-slashed absolute base directory, or a WP_Error
	 *                                  from an earlier resolution step (propagated as failure).
	 * @return string Absolute path, or '' on failure.
	 */
	protected static function resolve_relative_to( $relative, $base ) {
		if ( is_wp_error( $base ) ) {
			return '';
		}

		$relative = trim( (string) $relative );
		if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
			return '';
		}

		$base_n = wp_normalize_path( trailingslashit( $base ) );
		$full_n = wp_normalize_path( $base . $relative );

		if ( 0 !== strpos( $full_n, $base_n ) ) {
			return '';
		}

		if ( false === self::real_path_within( $full_n, $base_n ) ) {
			return '';
		}

		return $full_n;
	}

	/**
	 * Legacy (pre-1.3.1) resolution: a bare `wp_upload_dir()`-relative fragment.
	 *
	 * Kept for read-only compatibility with rows written before the storage move;
	 * mirrors the containment/symlink checks {@see WPMAR_MD_Writer} used to apply directly.
	 *
	 * @param string $relative Path relative to `wp_upload_dir()['basedir']`.
	 * @return string Absolute path, or '' on failure.
	 */
	protected static function resolve_legacy_upload_relative( $relative ) {
		$relative = trim( $relative );
		if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
			return '';
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}

		$base = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
		$full = wp_normalize_path( path_join( $uploads['basedir'], $relative ) );

		if ( 0 !== strpos( $full, $base ) ) {
			return '';
		}

		if ( false === self::real_path_within( $full, $base ) ) {
			return '';
		}

		return $full;
	}

	/**
	 * Whether $full, once symlinks are resolved, stays inside $base.
	 *
	 * @param string $full Absolute candidate path (already prefix-checked as a string).
	 * @param string $base Trailing-slashed absolute base directory.
	 * @return bool|null True when contained, false when it escapes the root, null when the
	 *                   target does not yet exist (nothing to resolve — the string prefix
	 *                   check already guards the logical path for not-yet-written files).
	 */
	protected static function real_path_within( $full, $base ) {
		$real_full = realpath( $full );
		if ( false === $real_full ) {
			return null;
		}

		$real_base = realpath( untrailingslashit( $base ) );
		if ( false === $real_base ) {
			return false;
		}

		$real_base_n = wp_normalize_path( trailingslashit( $real_base ) );
		$real_full_n = wp_normalize_path( $real_full );

		return 0 === strpos( $real_full_n, $real_base_n );
	}
}
