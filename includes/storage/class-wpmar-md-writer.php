<?php
/**
 * Filesystem helpers that land Markdown exports in the private storage directory.
 *
 * Also retains the `uploads/wpmar` helpers used by {@see WPMAR_Private_Storage} as
 * its write-fallback location when the primary storage directory is not writable.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves safe upload paths and writes Markdown bytes.
 */
class WPMAR_MD_Writer {

	/** Subdirectory inside `wp_upload_dir()['basedir']`. */
	const UPLOAD_SUBDIR = 'wpmar';

	/**
	 * Ensures uploads base directory writable return absolute path subtree.
	 *
	 * @return string|WP_Error
	 */
	public static function uploads_base_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'wpmar_upload_base', esc_html( $uploads['error'] ) );
		}

		// Maintain a dedicated subtree so operators can exclude it from rsync rules if needed.
		$dir = trailingslashit( $uploads['basedir'] ) . self::UPLOAD_SUBDIR;
		wp_mkdir_p( $dir );

		if ( ! is_dir( $dir ) ) {
			return new WP_Error( 'wpmar_upload_mkdir_fail', __( 'Unable to create upload directory.', 'wp-maintenance-audit-reporter' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- lightweight pre-flight; WP_Filesystem not yet initialised at this point.
		if ( ! is_writable( $dir ) ) {
			return new WP_Error( 'wpmar_upload_not_writable', __( 'Upload directory is not writable.', 'wp-maintenance-audit-reporter' ) );
		}

		return trailingslashit( $dir );
	}

	/**
	 * Persist markdown string and return its `private:`-prefixed storage path.
	 *
	 * @param string $basename_no_ext   Desired filename slug (timestamp based).
	 * @param string $markdown_contents utf8 textual body bytes.
	 * @return string|WP_Error `private:`-prefixed relative path, or error.
	 */
	public static function write_markdown_file( $basename_no_ext, $markdown_contents ) {
		$dir = WPMAR_Private_Storage::reports_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$slug = sanitize_file_name( strtolower( preg_replace( '/[^a-z0-9_-]+/i', '-', $basename_no_ext ) ) );
		if ( '' === $slug ) {
			$slug = 'report';
		}

		$file = trailingslashit( $dir ) . $slug . '-' . WPMAR_Private_Storage::generate_token() . '.md';

		// Atomic-friendly single write; caller already assembled the UTF-8 Markdown string.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writes under the protected private-storage directory.
		if ( false === file_put_contents( $file, $markdown_contents ) ) {
			return new WP_Error( 'wpmar_md_write_failed', __( 'Unable to persist markdown artefact.', 'wp-maintenance-audit-reporter' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.PHP.NoSilencedErrors.Discouraged -- Mirror core upload permissions; failures are harmless.
		@chmod( $file, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );

		return WPMAR_Private_Storage::relative_for_storage( $file );
	}

	/**
	 * Maps a stored path (new `private:`-prefixed or legacy uploads-relative) to an
	 * absolute filesystem path.
	 *
	 * Thin wrapper kept for the existing call sites; the resolution itself now
	 * lives in {@see WPMAR_Private_Storage::resolve()}.
	 *
	 * @param string $relative Value from `md_file_path` / `pdf_file_path` / `log_path`.
	 * @return string Empty string when the path is invalid or cannot be resolved.
	 */
	public static function absolute_path_from_upload_relative( $relative ) {
		return WPMAR_Private_Storage::resolve( $relative );
	}

	/**
	 * Deletes a file previously stored via {@see self::write_markdown_file()} or the
	 * legacy uploads-relative format.
	 *
	 * Thin wrapper kept for the existing call sites; see {@see WPMAR_Private_Storage::delete()}.
	 *
	 * @param string $relative Value from `md_file_path` / `pdf_file_path` / `log_path`.
	 * @return void
	 */
	public static function delete_if_upload_relative( $relative ) {
		WPMAR_Private_Storage::delete( $relative );
	}
}
