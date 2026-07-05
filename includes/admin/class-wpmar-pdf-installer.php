<?php
/**
 * Downloads and installs the mPDF vendor bundle from GitHub Releases.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles on-demand PDF library installation from the admin UI.
 */
class WPMAR_PDF_Installer {

	const AJAX_ACTION        = 'wpmar_install_pdf_library';
	const AJAX_PREFLIGHT     = 'wpmar_pdf_preflight';
	const AJAX_MANUAL_UPLOAD = 'wpmar_pdf_manual_upload';

	/**
	 * Whether a vendor/ backup is awaiting restore in this request.
	 *
	 * Set when {@see backup_vendor_before_upgrade()} moves vendor/ aside, read by
	 * {@see restore_vendor_after_upgrade()}. Both run within the same upgrade request,
	 * so a static flag is a reliable channel — and avoids depending on $hook_extra,
	 * which lacks the `plugin` key during the manual ZIP-upload (install) flow.
	 *
	 * @var bool
	 */
	private static $vendor_pending_restore = false;

	/**
	 * Registers Ajax hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_ajax' ) );
		add_action( 'wp_ajax_' . self::AJAX_PREFLIGHT, array( __CLASS__, 'handle_preflight_ajax' ) );
		add_action( 'wp_ajax_' . self::AJAX_MANUAL_UPLOAD, array( __CLASS__, 'handle_manual_upload_ajax' ) );
	}

	/**
	 * Registers hooks that preserve vendor/ across plugin upgrades.
	 *
	 * Registered in all contexts (admin, WP-CLI, auto-update cron) — not gated behind
	 * is_admin() — so the PDF library survives every update path.
	 *
	 * @return void
	 */
	public static function register_upgrade_hooks() {
		// Recover from an upgrade that was interrupted after vendor/ was moved aside
		// but before it could be restored (e.g. a fatal error mid-copy).
		self::maybe_recover_vendor_backup();

		// Remove legacy Noto Sans JP variable font from previous versions.
		self::maybe_cleanup_legacy_fonts();

		add_filter( 'upgrader_source_selection', array( __CLASS__, 'backup_vendor_before_upgrade' ), 10, 1 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'restore_vendor_after_upgrade' ), 10, 0 );
	}

	/**
	 * Absolute path used as a temporary vendor/ backup during upgrades.
	 *
	 * @return string
	 */
	private static function vendor_backup_path() {
		return WP_CONTENT_DIR . '/wpmar-vendor-backup';
	}

	/**
	 * Absolute path used as a temporary fonts/ backup during upgrades.
	 *
	 * @return string
	 */
	private static function fonts_backup_path() {
		return WP_CONTENT_DIR . '/wpmar-fonts-backup';
	}

	/**
	 * Moves vendor/ to a safe location before the upgrader removes the plugin directory.
	 *
	 * Hooked on `upgrader_source_selection`, which fires after the incoming package is
	 * unpacked but before the old plugin directory is cleared. Unlike `upgrader_pre_install`,
	 * this receives the unpacked $source directory, letting us identify our own package by its
	 * folder name + main file — reliable for BOTH the manual ZIP-upload (install) and the
	 * dashboard "update now" flows, where $hook_extra differs.
	 *
	 * @param string|WP_Error $source Path to the unpacked package source directory.
	 * @return string|WP_Error The unchanged $source (this is a filter).
	 */
	public static function backup_vendor_before_upgrade( $source ) {
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		if ( ! self::source_is_this_plugin( $source ) ) {
			return $source;
		}

		$vendor = WPMAR_PLUGIN_DIR . 'vendor';
		if ( ! is_dir( $vendor ) ) {
			return $source;
		}

		$backup = self::vendor_backup_path();
		if ( is_dir( $backup ) ) {
			self::remove_dir( $backup );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged -- atomic rename within wp-content; WP_Filesystem has no equivalent.
		if ( @rename( $vendor, $backup ) ) {
			self::$vendor_pending_restore = true;
		}

		$fonts = WPMAR_PLUGIN_DIR . 'fonts';
		$fback = self::fonts_backup_path();
		if ( is_dir( $fonts ) ) {
			if ( is_dir( $fback ) ) {
				self::remove_dir( $fback );
			}
			@rename( $fonts, $fback ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged
		}

		return $source;
	}

	/**
	 * Whether the unpacked upgrade $source is this plugin's package.
	 *
	 * Matches the source folder name against the plugin slug and confirms the main plugin
	 * file is present inside it, so we only ever back up vendor/ when THIS plugin is the
	 * one being replaced.
	 *
	 * @param string $source Path to the unpacked package source directory.
	 * @return bool
	 */
	private static function source_is_this_plugin( $source ) {
		if ( ! is_string( $source ) || '' === $source ) {
			return false;
		}

		$slug     = dirname( WPMAR_PLUGIN_BASENAME );
		$basename = basename( untrailingslashit( wp_normalize_path( $source ) ) );
		if ( $basename !== $slug ) {
			return false;
		}

		$main_file = trailingslashit( $source ) . basename( WPMAR_PLUGIN_BASENAME );
		return is_file( $main_file );
	}

	/**
	 * Moves the backed-up vendor/ back into the plugin directory after the upgrade completes.
	 *
	 * Driven by the {@see $vendor_pending_restore} flag set during this same request rather
	 * than by $hook_extra (which omits the `plugin` key for manual ZIP uploads).
	 *
	 * @return void
	 */
	public static function restore_vendor_after_upgrade() {
		if ( ! self::$vendor_pending_restore ) {
			return;
		}
		self::$vendor_pending_restore = false;

		$backup = self::vendor_backup_path();
		if ( ! is_dir( $backup ) ) {
			return;
		}

		$vendor = WPMAR_PLUGIN_DIR . 'vendor';
		if ( ! is_dir( $vendor ) ) {
			@rename( $backup, $vendor ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged
		} else {
			// New package already includes vendor/ — discard the backup.
			self::remove_dir( $backup );
		}

		$fback = self::fonts_backup_path();
		$fonts = WPMAR_PLUGIN_DIR . 'fonts';
		if ( is_dir( $fback ) ) {
			if ( ! is_dir( $fonts ) ) {
				@rename( $fback, $fonts ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged
			} else {
				self::remove_dir( $fback );
			}
		}
	}

	/**
	 * Removes superseded bundled font files left by previous versions.
	 *
	 * Runs once per plugin load. Cleans up:
	 *  - `NotoSansJP.ttf`        — the pre-BIZUD variable font (single file, no bold).
	 *  - `BIZUDGothic-*.ttf`     — superseded by the Noto Sans JP static instances
	 *                             (Regular/Bold) that the current version bundles.
	 *
	 * The overhead is a handful of is_file() checks, which is negligible. No option
	 * flag is needed because each file disappears after the first successful cleanup.
	 * The current fonts (`NotoSansJP-Regular.ttf` / `NotoSansJP-Bold.ttf`) are never
	 * listed here, so they are left untouched.
	 *
	 * @since 1.1.0
	 * @return void
	 */
	private static function maybe_cleanup_legacy_fonts() {
		$font_dir = WPMAR_PLUGIN_DIR . 'fonts' . DIRECTORY_SEPARATOR;
		$legacy   = array(
			'NotoSansJP.ttf',
			'BIZUDGothic-Regular.ttf',
			'BIZUDGothic-Bold.ttf',
		);
		foreach ( $legacy as $file ) {
			$path = $font_dir . $file;
			if ( is_file( $path ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $path );
			}
		}
	}

	/**
	 * Restores an orphaned vendor/ backup left by an interrupted upgrade.
	 *
	 * If a previous upgrade moved vendor/ aside but died before restoring it (so the
	 * in-request flag was lost), the next plugin load finds vendor/ missing while the
	 * backup survives, and moves it back. Runs only when vendor/ is absent, so it never
	 * fires mid-upgrade (no fresh plugin load happens between backup and restore).
	 *
	 * @return void
	 */
	private static function maybe_recover_vendor_backup() {
		$vendor = WPMAR_PLUGIN_DIR . 'vendor';
		if ( is_dir( $vendor ) ) {
			return;
		}

		$backup = self::vendor_backup_path();
		if ( ! is_dir( $backup ) ) {
			return;
		}

		@rename( $backup, $vendor ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged

		$fback = self::fonts_backup_path();
		$fonts = WPMAR_PLUGIN_DIR . 'fonts';
		if ( ! is_dir( $fonts ) && is_dir( $fback ) ) {
			@rename( $fback, $fonts ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Recursively removes a directory and all its contents.
	 *
	 * @param string $dir Absolute path to remove.
	 * @return void
	 */
	private static function remove_dir( $dir ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getRealPath() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				@unlink( $item->getRealPath() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * Whether the PDF vendor bundle is already present.
	 *
	 * @return bool
	 */
	public static function is_installed() {
		return (bool) apply_filters( 'wpmar_pdf_is_installed', WPMAR_PDF_Writer::is_available() );
	}

	/**
	 * Font files the current version expects to find in `fonts/`.
	 *
	 * Kept in sync with the bundled fonts declared in {@see WPMAR_PDF_Writer::write_pdf_from_markdown()}.
	 *
	 * @return string[]
	 */
	private static function expected_font_files() {
		return array( 'NotoSansJP-Regular.ttf', 'NotoSansJP-Bold.ttf' );
	}

	/**
	 * Whether every expected bundled font is present and readable.
	 *
	 * Used to detect installs that carry an OLD vendor-pdf.zip (e.g. one that still
	 * bundled BIZ UDGothic): mPDF is present but the Noto Sans JP fonts this version
	 * needs are missing, so the admin should re-download the current bundle. PDF
	 * generation still works meanwhile via mPDF's built-in `sun-exta` fallback.
	 *
	 * @return bool
	 */
	public static function fonts_present() {
		$font_dir = rtrim( WPMAR_PLUGIN_DIR, '/\\' ) . DIRECTORY_SEPARATOR . 'fonts';
		foreach ( self::expected_font_files() as $file ) {
			if ( ! is_readable( $font_dir . DIRECTORY_SEPARATOR . $file ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * URL of the vendor-pdf.zip asset on GitHub Releases.
	 * Override via the `wpmar_pdf_vendor_zip_url` filter if you host the bundle elsewhere.
	 *
	 * @return string
	 */
	public static function get_download_url() {
		return (string) apply_filters(
			'wpmar_pdf_vendor_zip_url',
			'https://github.com/lunaluna/wp-maintenance-audit-reporter/releases/download/' . WPMAR_VERSION . '/vendor-pdf.zip'
		);
	}

	/**
	 * Ajax endpoint: checks write permissions and disk space before downloading.
	 *
	 * @return void
	 */
	public static function handle_preflight_ajax() {
		check_ajax_referer( 'wpmar_pdf_installer', 'nonce' );

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error(
				array( 'message' => __( '権限がありません。', 'wp-maintenance-audit-reporter' ) ),
				403
			);
		}

		$result = self::preflight_check();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success();
	}

	/**
	 * Ajax endpoint: downloads and extracts the vendor bundle.
	 *
	 * @return void
	 */
	public static function handle_ajax() {
		check_ajax_referer( 'wpmar_pdf_installer', 'nonce' );

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error(
				array( 'message' => __( '権限がありません。', 'wp-maintenance-audit-reporter' ) ),
				403
			);
		}

		$result = self::install();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array( 'message' => __( 'PDF ライブラリのインストールが完了しました。ページを再読み込みします…', 'wp-maintenance-audit-reporter' ) )
		);
	}

	/**
	 * Ajax endpoint: receives a manually-uploaded vendor-pdf.zip and extracts it.
	 *
	 * Used when the automatic GitHub download is unavailable (firewall, network restriction, etc.).
	 *
	 * @return void
	 */
	public static function handle_manual_upload_ajax() {
		check_ajax_referer( 'wpmar_pdf_installer', 'nonce' );

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error(
				array( 'message' => __( '権限がありません。', 'wp-maintenance-audit-reporter' ) ),
				403
			);
		}

		// Validate that a file was actually sent.
		if ( empty( $_FILES['vendor_zip'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- binary upload; sanitizing would corrupt it.
			wp_send_json_error(
				array( 'message' => __( 'ファイルがアップロードされていません。', 'wp-maintenance-audit-reporter' ) )
			);
		}

		$error_code = isset( $_FILES['vendor_zip']['error'] ) ? (int) $_FILES['vendor_zip']['error'] : UPLOAD_ERR_NO_FILE; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( UPLOAD_ERR_OK !== $error_code ) {
			$message = UPLOAD_ERR_INI_SIZE === $error_code
				? __( 'ファイルサイズが PHP の upload_max_filesize 制限を超えています。サーバー設定をご確認ください。', 'wp-maintenance-audit-reporter' )
				: sprintf(
					/* translators: %d: PHP upload error code */
					__( 'ファイルのアップロードに失敗しました（エラーコード: %d）。', 'wp-maintenance-audit-reporter' ),
					$error_code
				);
			wp_send_json_error( array( 'message' => $message ) );
		}

		// Validate file extension.
		$file_name = isset( $_FILES['vendor_zip']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['vendor_zip']['name'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( 'zip' !== strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) ) ) {
			wp_send_json_error(
				array( 'message' => __( 'ZIP ファイルのみアップロード可能です。', 'wp-maintenance-audit-reporter' ) )
			);
		}

		$tmp_path = isset( $_FILES['vendor_zip']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['vendor_zip']['tmp_name'] ) ) : '';

		// Ensure the path is a genuine PHP HTTP upload, not an arbitrary server-side path.
		if ( '' === $tmp_path || ! is_uploaded_file( $tmp_path ) ) {
			wp_send_json_error(
				array( 'message' => __( 'アップロードされたファイルを検証できませんでした。', 'wp-maintenance-audit-reporter' ) )
			);
		}

		// Cap the upload size (the official bundle is ~30 MB); reject oversized archives early.
		$max_upload = 80 * 1024 * 1024;
		$reported   = isset( $_FILES['vendor_zip']['size'] ) ? (int) $_FILES['vendor_zip']['size'] : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( $reported > $max_upload || (int) filesize( $tmp_path ) > $max_upload ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: maximum allowed upload size */
						__( 'ファイルサイズが上限（%s）を超えています。', 'wp-maintenance-audit-reporter' ),
						size_format( $max_upload )
					),
				)
			);
		}

		// Verify the ZIP magic bytes (PK header) to reject non-zip files with a .zip extension.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading 2 bytes from a server-side temp file; no HTTP request involved.
		$header = file_get_contents( $tmp_path, false, null, 0, 2 );
		if ( 'PK' !== $header ) {
			wp_send_json_error(
				array( 'message' => __( 'ファイルが有効な ZIP 形式ではありません。', 'wp-maintenance-audit-reporter' ) )
			);
		}

		// Re-run preflight before extracting.
		$preflight = self::preflight_check();
		if ( is_wp_error( $preflight ) ) {
			wp_send_json_error( array( 'message' => $preflight->get_error_message() ) );
		}

		$result = self::install_bundle_from_zip( $tmp_path );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// The freshly-installed library is intentionally NOT require_once'd here; the
		// admin page reloads on success and mPDF loads via the normal plugin bootstrap.
		wp_send_json_success(
			array( 'message' => __( 'PDF ライブラリのインストールが完了しました。ページを再読み込みします…', 'wp-maintenance-audit-reporter' ) )
		);
	}

	/**
	 * Checks whether the server environment allows writing and has enough free space.
	 * Required free space: 150 MB (zip ~30 MB + extracted ~94 MB + margin).
	 *
	 * @return true|WP_Error
	 */
	private static function preflight_check() {
		$target = WPMAR_PLUGIN_DIR;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem has no equivalent for permission checks; PHP native is used widely in WP core for this purpose.
		if ( ! is_writable( $target ) ) {
			return new WP_Error(
				'wpmar_not_writable',
				sprintf(
					/* translators: %s: absolute path to the plugin directory */
					__( 'ディレクトリへの書き込み権限がありません: %s。FTP またはサーバー管理画面でディレクトリのパーミッションを 755 に変更してください。', 'wp-maintenance-audit-reporter' ),
					esc_html( $target )
				)
			);
		}

		$required = 150 * 1024 * 1024; // 150 MB
		$free     = function_exists( 'disk_free_space' ) ? disk_free_space( $target ) : false;
		if ( false !== $free && $free < $required ) {
			return new WP_Error(
				'wpmar_disk_full',
				sprintf(
					/* translators: 1: required size, 2: current free size */
					__( 'ディスクの空き容量が不足しています（必要: %1$s 以上、現在の空き: %2$s）。不要なファイルを削除してから再試行してください。', 'wp-maintenance-audit-reporter' ),
					size_format( $required ),
					size_format( $free )
				)
			);
		}

		return true;
	}

	/**
	 * Downloads the bundle zip and extracts it into the plugin directory.
	 *
	 * @return true|WP_Error
	 */
	private static function install() {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$url = self::get_download_url();
		$tmp = download_url( $url, 180 );

		if ( is_wp_error( $tmp ) ) {
			return new WP_Error(
				'wpmar_pdf_download_failed',
				sprintf(
					/* translators: %s: error message from wp_remote_get */
					__( 'ダウンロードに失敗しました: %s', 'wp-maintenance-audit-reporter' ),
					$tmp->get_error_message()
				)
			);
		}

		$result = self::install_bundle_from_zip( $tmp );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- temp file cleanup after download_url().
		@unlink( $tmp );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// mPDF is intentionally not require_once'd here; the admin page reloads on
		// success and the library loads via the normal plugin bootstrap next request.
		return true;
	}

	/**
	 * Top-level directory names the vendor bundle is allowed to contain.
	 *
	 * @return string[]
	 */
	private static function allowed_top_level() {
		return array( 'vendor', 'fonts' );
	}

	/**
	 * Expected SHA-256 of the official vendor-pdf.zip, if pinned.
	 *
	 * Empty by default (no enforcement). Set the `WPMAR_PDF_VENDOR_ZIP_SHA256`
	 * constant or the `wpmar_pdf_vendor_zip_sha256` filter to a lowercase hex
	 * digest to require the archive to match before it is extracted.
	 *
	 * @return string
	 */
	private static function expected_sha256() {
		$pinned = defined( 'WPMAR_PDF_VENDOR_ZIP_SHA256' ) ? (string) WPMAR_PDF_VENDOR_ZIP_SHA256 : '';
		return strtolower( trim( (string) apply_filters( 'wpmar_pdf_vendor_zip_sha256', $pinned ) ) );
	}

	/**
	 * Verifies the archive digest when a checksum is pinned; a no-op otherwise.
	 *
	 * @param string $zip_path Absolute path to the archive.
	 * @return true|WP_Error
	 */
	private static function verify_checksum( $zip_path ) {
		$expected = self::expected_sha256();
		if ( '' === $expected ) {
			return true;
		}

		$actual = hash_file( 'sha256', $zip_path );
		if ( ! is_string( $actual ) || ! hash_equals( $expected, strtolower( $actual ) ) ) {
			return new WP_Error(
				'wpmar_zip_checksum',
				__( 'ダウンロードしたファイルのチェックサムが一致しませんでした。ファイルが破損しているか改ざんされている可能性があります。', 'wp-maintenance-audit-reporter' )
			);
		}

		return true;
	}

	/**
	 * Safely installs the vendor bundle from a zip archive.
	 *
	 * Extraction happens in an isolated staging directory with per-entry
	 * validation (no absolute paths, no `..` traversal, no symlinks, and only the
	 * expected `vendor/` and `fonts/` top-level directories). Only after the whole
	 * archive validates are the directories moved into the plugin. This never
	 * relies on `ZipArchive::extractTo()` writing directly into the plugin tree,
	 * so a malicious archive cannot plant files elsewhere or execute code.
	 *
	 * @param string $zip_path Absolute path to the archive to install.
	 * @return true|WP_Error
	 */
	private static function install_bundle_from_zip( $zip_path ) {
		$checksum = self::verify_checksum( $zip_path );
		if ( is_wp_error( $checksum ) ) {
			return $checksum;
		}

		$staging = self::make_staging_dir();
		if ( is_wp_error( $staging ) ) {
			return $staging;
		}

		$extracted = self::safe_extract_to_staging( $zip_path, $staging );
		if ( is_wp_error( $extracted ) ) {
			self::remove_dir( $staging );
			return $extracted;
		}

		$moved = self::move_bundle_into_place( $staging );
		self::remove_dir( $staging );

		return $moved;
	}

	/**
	 * Creates a clean, empty staging directory under wp-content.
	 *
	 * Kept on the same filesystem as the plugin directory so the validated
	 * directories can be moved into place with a rename rather than a copy.
	 *
	 * @return string|WP_Error Absolute staging path, or an error.
	 */
	private static function make_staging_dir() {
		$staging = WP_CONTENT_DIR . '/wpmar-vendor-staging';
		if ( is_dir( $staging ) ) {
			self::remove_dir( $staging );
		}
		if ( ! wp_mkdir_p( $staging ) ) {
			return new WP_Error(
				'wpmar_staging_failed',
				__( '一時展開ディレクトリを作成できませんでした。', 'wp-maintenance-audit-reporter' )
			);
		}
		return $staging;
	}

	/**
	 * Extracts the archive into the staging directory after validating every entry.
	 *
	 * @param string $zip_path Absolute path to the archive.
	 * @param string $staging  Absolute path to the (empty) staging directory.
	 * @return true|WP_Error
	 */
	private static function safe_extract_to_staging( $zip_path, $staging ) {
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			if ( true !== $zip->open( $zip_path ) ) {
				return new WP_Error(
					'wpmar_zip_open',
					__( 'ZIP ファイルを開けませんでした。', 'wp-maintenance-audit-reporter' )
				);
			}

			$total_uncompressed = 0;
			$max_uncompressed   = 300 * 1024 * 1024; // Guard against decompression bombs.
			$count              = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive core property.

			for ( $i = 0; $i < $count; $i++ ) {
				$name  = $zip->getNameIndex( $i );
				$valid = self::validate_entry_name( $name );
				if ( is_wp_error( $valid ) ) {
					$zip->close();
					return $valid;
				}
				if ( self::entry_is_symlink( $zip, $i ) ) {
					$zip->close();
					return new WP_Error(
						'wpmar_zip_symlink',
						__( 'アーカイブにシンボリックリンクが含まれているため展開を中止しました。', 'wp-maintenance-audit-reporter' )
					);
				}
				$stat = $zip->statIndex( $i );
				if ( is_array( $stat ) && isset( $stat['size'] ) ) {
					$total_uncompressed += (int) $stat['size'];
				}
			}

			if ( $total_uncompressed > $max_uncompressed ) {
				$zip->close();
				return new WP_Error(
					'wpmar_zip_too_large',
					__( 'アーカイブの展開後サイズが大きすぎます。', 'wp-maintenance-audit-reporter' )
				);
			}

			if ( ! $zip->extractTo( $staging ) ) {
				$zip->close();
				return new WP_Error(
					'wpmar_zip_extract',
					__( 'ZIP の展開に失敗しました。', 'wp-maintenance-audit-reporter' )
				);
			}
			$zip->close();

			return self::assert_staging_top_level( $staging );
		}

		// Fallback: WordPress built-in unzip_file() carries its own traversal guard.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		$unzip_result = unzip_file( $zip_path, $staging );
		if ( is_wp_error( $unzip_result ) ) {
			return new WP_Error(
				'wpmar_zip_extract',
				sprintf(
					/* translators: %s: error message from unzip_file */
					__( 'ZIP の展開に失敗しました: %s', 'wp-maintenance-audit-reporter' ),
					$unzip_result->get_error_message()
				)
			);
		}

		return self::assert_staging_top_level( $staging );
	}

	/**
	 * Validates a single archive entry name against traversal and layout rules.
	 *
	 * @param string $name Raw entry name from the archive.
	 * @return true|WP_Error
	 */
	private static function validate_entry_name( $name ) {
		$normalized = str_replace( '\\', '/', (string) $name );

		if ( '' === $normalized ) {
			return new WP_Error( 'wpmar_zip_bad_entry', __( 'アーカイブに不正なエントリが含まれています。', 'wp-maintenance-audit-reporter' ) );
		}
		// Absolute paths (POSIX or Windows drive letter).
		if ( '/' === $normalized[0] || preg_match( '#^[A-Za-z]:#', $normalized ) ) {
			return new WP_Error( 'wpmar_zip_absolute', __( 'アーカイブに絶対パスのエントリが含まれているため展開を中止しました。', 'wp-maintenance-audit-reporter' ) );
		}

		$segments = explode( '/', trim( $normalized, '/' ) );
		foreach ( $segments as $segment ) {
			if ( '..' === $segment ) {
				return new WP_Error( 'wpmar_zip_traversal', __( 'アーカイブにディレクトリトラバーサルのエントリが含まれているため展開を中止しました。', 'wp-maintenance-audit-reporter' ) );
			}
		}

		if ( ! in_array( $segments[0], self::allowed_top_level(), true ) ) {
			return new WP_Error(
				'wpmar_zip_unexpected_top',
				sprintf(
					/* translators: %s: unexpected top-level entry name */
					__( 'アーカイブに想定外のエントリ（%s）が含まれているため展開を中止しました。', 'wp-maintenance-audit-reporter' ),
					esc_html( $segments[0] )
				)
			);
		}

		return true;
	}

	/**
	 * Whether the given archive entry is a Unix symbolic link.
	 *
	 * @param ZipArchive $zip   Open archive.
	 * @param int        $index Entry index.
	 * @return bool
	 */
	private static function entry_is_symlink( $zip, $index ) {
		if ( ! method_exists( $zip, 'getExternalAttributesIndex' ) ) {
			return false;
		}
		$opsys = 0;
		$attr  = 0;
		if ( ! $zip->getExternalAttributesIndex( $index, $opsys, $attr ) ) {
			return false;
		}
		if ( defined( 'ZipArchive::OPSYS_UNIX' ) && ZipArchive::OPSYS_UNIX === $opsys ) {
			$mode = ( $attr >> 16 ) & 0xFFFF;
			// S_IFLNK (0xA000) in the file-type bits (0xF000).
			return 0xA000 === ( $mode & 0xF000 );
		}
		return false;
	}

	/**
	 * Confirms the staging directory contains only the allowed top-level directories.
	 *
	 * Belt-and-suspenders for the unzip_file() fallback path, which does not run the
	 * per-entry validation above.
	 *
	 * @param string $staging Absolute staging path.
	 * @return true|WP_Error
	 */
	private static function assert_staging_top_level( $staging ) {
		$entries = scandir( $staging );
		if ( false === $entries ) {
			return new WP_Error( 'wpmar_staging_unreadable', __( '一時展開ディレクトリを確認できませんでした。', 'wp-maintenance-audit-reporter' ) );
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			if ( ! in_array( $entry, self::allowed_top_level(), true ) ) {
				return new WP_Error(
					'wpmar_zip_unexpected_top',
					sprintf(
						/* translators: %s: unexpected top-level entry name */
						__( 'アーカイブに想定外のエントリ（%s）が含まれているため展開を中止しました。', 'wp-maintenance-audit-reporter' ),
						esc_html( $entry )
					)
				);
			}
		}
		return true;
	}

	/**
	 * Moves the validated vendor/ (and optional fonts/) from staging into the plugin.
	 *
	 * @param string $staging Absolute staging path.
	 * @return true|WP_Error
	 */
	private static function move_bundle_into_place( $staging ) {
		$src_vendor = $staging . '/vendor';
		if ( ! is_dir( $src_vendor ) ) {
			return new WP_Error(
				'wpmar_bundle_no_vendor',
				__( 'アーカイブに vendor/ ディレクトリが見つかりませんでした。正しい vendor-pdf.zip か確認してください。', 'wp-maintenance-audit-reporter' )
			);
		}

		$plugin_dir = rtrim( WPMAR_PLUGIN_DIR, '/\\' ) . '/';
		$dst_vendor = $plugin_dir . 'vendor';
		if ( is_dir( $dst_vendor ) ) {
			self::remove_dir( $dst_vendor );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged -- move within the same filesystem; WP_Filesystem has no equivalent.
		if ( ! @rename( $src_vendor, $dst_vendor ) ) {
			return new WP_Error(
				'wpmar_bundle_move_failed',
				__( 'ライブラリの配置に失敗しました。', 'wp-maintenance-audit-reporter' )
			);
		}

		$src_fonts = $staging . '/fonts';
		if ( is_dir( $src_fonts ) ) {
			$dst_fonts = $plugin_dir . 'fonts';
			if ( is_dir( $dst_fonts ) ) {
				self::remove_dir( $dst_fonts );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged -- move within the same filesystem.
			@rename( $src_fonts, $dst_fonts );
		}

		return true;
	}

	/**
	 * Renders the PDF library status panel inside the settings page.
	 *
	 * @return void
	 */
	public static function render_panel() {
		$installed   = self::is_installed();
		$fonts_ready = self::fonts_present();
		// mPDF is present but the fonts this version bundles are missing (stale bundle
		// from a previous version) — offer a re-install using the same download flow.
		$fonts_stale = $installed && ! $fonts_ready;
		// Installing/replacing library code requires the plugin-install capability
		// (super admins only on multisite), matching the AJAX handler gate.
		$can_install = current_user_can( 'install_plugins' );
		?>
		<div class="wpmar-section-panel" id="wpmar-pdf-library-panel">
			<h2><?php esc_html_e( 'PDF ライブラリ（mPDF）', 'wp-maintenance-audit-reporter' ); ?></h2>
			<?php if ( $installed && $fonts_ready ) : ?>
				<p>
					<span style="color:#0a7c00;font-weight:bold;">&#10003;</span>
					<?php esc_html_e( 'mPDF ライブラリはインストール済みです。PDF 出力が有効です。', 'wp-maintenance-audit-reporter' ); ?>
				</p>
			<?php else : ?>
				<?php if ( $fonts_stale ) : ?>
					<p style="padding:0.75em 1em;background:#fff8e1;border-left:4px solid #f0b849;">
						<span style="color:#b26a00;font-weight:bold;">&#9888;</span>
						<?php esc_html_e( '同梱フォントが差し替えられたため、PDF ライブラリの再インストールが必要です。ボタンを押すと最新のライブラリ（新しいフォントを含む）を再ダウンロードします。再インストールするまで、日本語 PDF は代替フォントで出力されます。', 'wp-maintenance-audit-reporter' ); ?>
					</p>
				<?php else : ?>
					<p>
						<?php esc_html_e( 'PDF 出力には mPDF ライブラリ（展開後 約 94 MB）が必要です。ボタンを押すとライブラリを GitHub Releases からダウンロードし、このプラグインの vendor/ ディレクトリ配下に自動展開します。', 'wp-maintenance-audit-reporter' ); ?>
					</p>
				<?php endif; ?>
				<?php if ( $can_install ) : ?>
				<p>
					<button type="button" class="button button-primary" id="wpmar-install-pdf-btn">
						<?php esc_html_e( 'PDF ライブラリをインストール', 'wp-maintenance-audit-reporter' ); ?>
					</button>
					<span id="wpmar-pdf-install-status" style="display:none;margin-left:10px;vertical-align:middle;"></span>
				</p>
				<p class="description">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: download URL shown as <code> */
							__( 'ダウンロード元: %s', 'wp-maintenance-audit-reporter' ),
							'<code>' . esc_html( self::get_download_url() ) . '</code>'
						),
						array( 'code' => array() )
					);
					?>
				</p>
				<p class="description">
					<?php esc_html_e( 'PDF ライブラリがインストールできない場合でも、クライアント向けレポートをマークダウン形式でダウンロードできます。', 'wp-maintenance-audit-reporter' ); ?>
				</p>
				<div id="wpmar-manual-upload-wrap" style="display:none;margin-top:1.5em;padding:1em;background:#fff8e1;border-left:4px solid #f0b849;">
					<p><strong><?php esc_html_e( '手動インストール', 'wp-maintenance-audit-reporter' ); ?></strong></p>
					<p class="description">
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %s: anchor tag linking to vendor-pdf.zip */
								__( 'サーバーからの自動ダウンロードに失敗した場合は、%s をお使いのパソコンにダウンロードし、以下からアップロードしてください。', 'wp-maintenance-audit-reporter' ),
								'<a href="' . esc_url( self::get_download_url() ) . '" target="_blank" rel="noopener noreferrer">vendor-pdf.zip</a>'
							),
							array(
								'a' => array(
									'href'   => array(),
									'target' => array(),
									'rel'    => array(),
								),
							)
						);
						?>
					</p>
					<p>
						<input type="file" id="wpmar-vendor-zip-file" accept=".zip" />
						<button type="button" class="button" id="wpmar-manual-upload-btn" style="margin-left:8px;">
							<?php esc_html_e( 'アップロードしてインストール', 'wp-maintenance-audit-reporter' ); ?>
						</button>
						<span id="wpmar-manual-upload-status" style="display:none;margin-left:10px;vertical-align:middle;"></span>
					</p>
				</div>
					<?php self::render_install_script(); ?>
				<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'このライブラリのインストールにはプラグインインストール権限が必要です（マルチサイトではネットワーク管理者）。権限のある管理者に依頼してください。', 'wp-maintenance-audit-reporter' ); ?>
				</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Outputs the inline JS that drives the install button.
	 *
	 * @return void
	 */
	private static function render_install_script() {
		$nonce    = wp_create_nonce( 'wpmar_pdf_installer' );
		$messages = array(
			'checking'    => __( '環境を確認中…', 'wp-maintenance-audit-reporter' ),
			'downloading' => __( 'ダウンロード中… しばらくお待ちください（数分かかる場合があります）', 'wp-maintenance-audit-reporter' ),
			'uploading'   => __( 'アップロード・展開中… しばらくお待ちください', 'wp-maintenance-audit-reporter' ),
			'no_file'     => __( 'ZIP ファイルを選択してください。', 'wp-maintenance-audit-reporter' ),
			'timeout'     => __( 'タイムアウトしました。サーバーの PHP タイムアウト設定（max_execution_time）をご確認ください。', 'wp-maintenance-audit-reporter' ),
			'network'     => __( 'ネットワークエラーが発生しました。', 'wp-maintenance-audit-reporter' ),
			'fallback'    => __( 'インストールに失敗しました。', 'wp-maintenance-audit-reporter' ),
		);
		?>
		<script>
		(function ($) {
			var btn       = document.getElementById('wpmar-install-pdf-btn');
			var status    = document.getElementById('wpmar-pdf-install-status');
			var uploadBtn = document.getElementById('wpmar-manual-upload-btn');
			var uploadSt  = document.getElementById('wpmar-manual-upload-status');
			if (!btn) { return; }

			var nonce        = <?php echo wp_json_encode( $nonce ); ?>;
			var actionMain   = <?php echo wp_json_encode( self::AJAX_ACTION ); ?>;
			var actionPre    = <?php echo wp_json_encode( self::AJAX_PREFLIGHT ); ?>;
			var actionUpload = <?php echo wp_json_encode( self::AJAX_MANUAL_UPLOAD ); ?>;
			var msg          = {
				checking:    <?php echo wp_json_encode( $messages['checking'] ); ?>,
				downloading: <?php echo wp_json_encode( $messages['downloading'] ); ?>,
				uploading:   <?php echo wp_json_encode( $messages['uploading'] ); ?>,
				noFile:      <?php echo wp_json_encode( $messages['no_file'] ); ?>,
				timeout:     <?php echo wp_json_encode( $messages['timeout'] ); ?>,
				network:     <?php echo wp_json_encode( $messages['network'] ); ?>,
				fallback:    <?php echo wp_json_encode( $messages['fallback'] ); ?>
			};

			function setStatus(text, color) {
				status.style.display = '';
				status.style.color   = color || '';
				status.textContent   = text;
			}

			function setUploadStatus(text, color) {
				if (!uploadSt) { return; }
				uploadSt.style.display = '';
				uploadSt.style.color   = color || '';
				uploadSt.textContent   = text;
			}

			function showManualUpload() {
				var wrap = document.getElementById('wpmar-manual-upload-wrap');
				if (wrap) { wrap.style.display = ''; }
			}

			function doInstall() {
				setStatus(msg.downloading, '');
				$.ajax({
					url:     ajaxurl,
					method:  'POST',
					timeout: 240000,
					data: { action: actionMain, nonce: nonce },
					success: function (res) {
						if (res && res.success) {
							setStatus(res.data.message, '#0a7c00');
							setTimeout(function () { location.reload(); }, 2000);
						} else {
							var errMsg = (res && res.data && res.data.message)
								? res.data.message
								: msg.fallback;
							setStatus(errMsg, '#cc0000');
							btn.disabled = false;
							showManualUpload();
						}
					},
					error: function (xhr, statusStr) {
						var errMsg = ('timeout' === statusStr) ? msg.timeout : msg.network;
						setStatus(errMsg, '#cc0000');
						btn.disabled = false;
						showManualUpload();
					}
				});
			}

			btn.addEventListener('click', function () {
				btn.disabled = true;
				setStatus(msg.checking, '');

				$.ajax({
					url:    ajaxurl,
					method: 'POST',
					data:   { action: actionPre, nonce: nonce },
					success: function (res) {
						if (res && res.success) {
							doInstall();
						} else {
							var errMsg = (res && res.data && res.data.message)
								? res.data.message
								: msg.fallback;
							setStatus(errMsg, '#cc0000');
							btn.disabled = false;
						}
					},
					error: function () {
						setStatus(msg.network, '#cc0000');
						btn.disabled = false;
					}
				});
			});

			if (uploadBtn) {
				uploadBtn.addEventListener('click', function () {
					var fileInput = document.getElementById('wpmar-vendor-zip-file');
					if (!fileInput || !fileInput.files.length) {
						setUploadStatus(msg.noFile, '#cc0000');
						return;
					}

					uploadBtn.disabled = true;
					setUploadStatus(msg.uploading, '');

					var formData = new FormData();
					formData.append('action', actionUpload);
					formData.append('nonce', nonce);
					formData.append('vendor_zip', fileInput.files[0]);

					$.ajax({
						url:         ajaxurl,
						method:      'POST',
						data:        formData,
						processData: false,
						contentType: false,
						timeout:     120000,
						success: function (res) {
							if (res && res.success) {
								setUploadStatus(res.data.message, '#0a7c00');
								setTimeout(function () { location.reload(); }, 2000);
							} else {
								var errMsg = (res && res.data && res.data.message)
									? res.data.message
									: msg.fallback;
								setUploadStatus(errMsg, '#cc0000');
								uploadBtn.disabled = false;
							}
						},
						error: function (xhr, statusStr) {
							var errMsg = ('timeout' === statusStr) ? msg.timeout : msg.network;
							setUploadStatus(errMsg, '#cc0000');
							uploadBtn.disabled = false;
						}
					});
				});
			}
		}(jQuery));
		</script>
		<?php
	}
}
