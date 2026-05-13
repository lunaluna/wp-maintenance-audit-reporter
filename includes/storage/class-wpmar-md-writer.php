<?php
/**
 * Filesystem helpers that land Markdown exports under `uploads/wpmar`.
 *
 * Paths are normalised against `wp_upload_dir()` so multisite subsites stay isolated.
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

		return trailingslashit( $dir );
	}

	/**
	 * Persist markdown string and return uploads-relative posix path fragments.
	 *
	 * @param string $basename_no_ext Desired filename slug (timestamp based).
	 * @param string $markdown_contents utf8 textual body bytes.
	 * @return string|WP_Error relative path like wp-content uploads relative or error.
	 */
	public static function write_markdown_file( $basename_no_ext, $markdown_contents ) {
		$base = self::uploads_base_dir();
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$slug = sanitize_file_name( strtolower( preg_replace( '/[^a-z0-9_-]+/i', '-', $basename_no_ext ) ) );
		if ( '' === $slug ) {
			$slug = 'report';
		}

		$file = trailingslashit( $base ) . $slug . '.md';

		// Atomic-friendly single write; caller already assembled the UTF-8 Markdown string.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writes under wp_upload_dir with controlled filename.
		if ( false === file_put_contents( $file, $markdown_contents ) ) {
			return new WP_Error( 'wpmar_md_write_failed', __( 'Unable to persist markdown artefact.', 'wp-maintenance-audit-reporter' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.PHP.NoSilencedErrors.Discouraged -- Mirror core upload permissions; failures are harmless.
		@chmod( $file, FS_CHMOD_FILE );

		// Store relative fragments in DB so restores can relocate uploads between environments.
		$upload_info = wp_upload_dir();
		$relative    = str_replace(
			trailingslashit( $upload_info['basedir'] ),
			'',
			$file
		);

		return is_string( $relative ) ? $relative : '';
	}

	/**
	 * Deletes a file previously stored as `wp_upload_dir()['basedir']`-relative.
	 *
	 * @param string $relative Path relative to the uploads base directory.
	 * @return void
	 */
	public static function delete_if_upload_relative( $relative ) {
		$relative = is_string( $relative ) ? trim( $relative ) : '';

		if ( '' === $relative ) {
			return;
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return;
		}

		$base = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
		$full = wp_normalize_path( path_join( $uploads['basedir'], $relative ) );

		if ( 0 !== strpos( $full, $base ) ) {
			return;
		}

		if ( file_exists( $full ) && is_file( $full ) ) {
			wp_delete_file( $full );
		}
	}
}
