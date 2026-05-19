<?php
/**
 * Turns Markdown bodies into PDF files under `uploads/wpmar/pdf`.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges mPDF and Parsedown when Composer dependencies are present.
 */
class WPMAR_PDF_Writer {

	/**
	 * Whether runtime dependencies are loadable.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// Allow Composer autoload (second arg must not be false — classes are lazy-loaded until used).
		return class_exists( '\Mpdf\Mpdf' ) && class_exists( '\Parsedown' );
	}

	/**
	 * Converts UTF-8 Markdown to an HTML fragment (for HTML email). Parsedown only; does not require mPDF.
	 *
	 * @param string $markdown Source Markdown (same family as PDF / client body).
	 * @return string HTML fragment, or empty string when Parsedown is not available.
	 */
	public static function markdown_to_html_fragment( $markdown ) {
		if ( ! class_exists( '\Parsedown' ) ) {
			return '';
		}

		$markdown = (string) $markdown;
		$pd       = new \Parsedown();
		if ( method_exists( $pd, 'setSafeMode' ) ) {
			$pd->setSafeMode( true );
		}

		return $pd->text( $markdown );
	}

	/**
	 * Client-facing Markdown stored on the report row — sole source for PDF rendering (admin uses {@see WPMAR_Report_Repository} `body_md`).
	 *
	 * @param array<string,mixed> $row Row from {@see WPMAR_Report_Repository::find()}.
	 * @return string Non-empty when PDF can be rendered from persisted stakeholder copy.
	 */
	public static function markdown_body_for_client_pdf( array $row ) {
		return isset( $row['body_client_md'] ) ? trim( (string) $row['body_client_md'] ) : '';
	}

	/**
	 * Converts UTF-8 Markdown into a PDF stored alongside other uploads artefacts.
	 *
	 * @param string $markdown        Source Markdown (client-facing stakeholder report when generating audits).
	 * @param string $basename_no_ext Filename slug without extension.
	 * @return string|WP_Error Relative uploads path or failure.
	 */
	public static function write_pdf_from_markdown( $markdown, $basename_no_ext ) {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'wpmar_pdf_missing_libs',
				__( 'PDF ライブラリが読み込めません。プラグイン直下で composer install を実行してください。', 'wp-maintenance-audit-reporter' )
			);
		}

		$markdown = (string) $markdown;
		$slug     = sanitize_file_name( strtolower( preg_replace( '/[^a-z0-9_-]+/i', '-', (string) $basename_no_ext ) ) );
		if ( '' === $slug ) {
			$slug = 'report';
		}

		$base = WPMAR_MD_Writer::uploads_base_dir();
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$pdf_dir = trailingslashit( $base ) . 'pdf';
		wp_mkdir_p( $pdf_dir );

		if ( ! is_dir( $pdf_dir ) ) {
			return new WP_Error( 'wpmar_pdf_mkdir', __( 'PDF 保存用ディレクトリを作成できません。', 'wp-maintenance-audit-reporter' ) );
		}

		$temp_parent = trailingslashit( $base ) . 'tmp';
		wp_mkdir_p( $temp_parent );

		$temp_dir = trailingslashit( $temp_parent ) . 'mpdf-' . gmdate( 'YmdHis' ) . '-' . wp_rand( 10000, 99999 );
		wp_mkdir_p( $temp_dir );

		if ( ! is_dir( $temp_dir ) ) {
			return new WP_Error( 'wpmar_pdf_temp_mkdir', __( 'mPDF 一時ディレクトリを作成できません。', 'wp-maintenance-audit-reporter' ) );
		}

		$parsedown = new \Parsedown();
		$fragment  = $parsedown->text( $markdown );
		$html      = '<!DOCTYPE html><html><head><meta charset="UTF-8" />'
			. '<style>body{font-family:sun-exta,dejavusans,sans-serif;font-size:10pt;line-height:1.35;color:#111;}pre,code{font-family:dejavusansmono,monospace;font-size:8pt;}h1{font-size:14pt;}h2{font-size:12pt;}table{border-collapse:collapse;}td,th{border:1px solid #ccc;padding:4px;}</style>'
			. '</head><body>' . $fragment . '</body></html>';

		$file = trailingslashit( $pdf_dir ) . $slug . '.pdf';

		try {
			$mpdf = new \Mpdf\Mpdf(
				array(
					'mode'          => 'utf-8',
					'format'        => 'A4',
					'tempDir'       => $temp_dir,
					'default_font'  => 'sun-exta',
					'margin_top'    => 12,
					'margin_bottom' => 12,
				)
			);

			$mpdf->WriteHTML( $html );
			$mpdf->Output( $file, \Mpdf\Output\Destination::FILE );
		} catch ( \Throwable $e ) {
			self::cleanup_temp_dir( $temp_dir );

			return new WP_Error(
				'wpmar_pdf_render',
				sprintf(
					/* translators: %s: technical reason */
					__( 'PDF を生成できませんでした: %s', 'wp-maintenance-audit-reporter' ),
					$e->getMessage()
				)
			);
		}

		self::cleanup_temp_dir( $temp_dir );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.PHP.NoSilencedErrors.Discouraged -- Mirror uploaded artefact permissions like Markdown exports.
		@chmod( $file, FS_CHMOD_FILE );

		$upload_info = wp_upload_dir();
		$relative    = str_replace( trailingslashit( $upload_info['basedir'] ), '', $file );

		return is_string( $relative ) ? $relative : '';
	}

	/**
	 * Recursively clears a temporary directory created for mPDF.
	 *
	 * @param string $dir Absolute directory path.
	 * @return void
	 */
	protected static function cleanup_temp_dir( $dir ) {
		if ( ! is_string( $dir ) || '' === $dir || ! is_dir( $dir ) ) {
			return;
		}

		$dir = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_opendir -- temp dir teardown.
		$handle = opendir( $dir );
		if ( false === $handle ) {
			return;
		}

		// phpcs:ignore Generic.CodeAnalysis.JumbledIncrementer.Increment,JumbledDecrement -- readdir loop.
		$item = readdir( $handle );
		while ( false !== $item ) {
			if ( '.' !== $item && '..' !== $item ) {
				$path = $dir . $item;
				if ( is_dir( $path ) ) {
					self::cleanup_temp_dir( $path );
				} elseif ( file_exists( $path ) && is_file( $path ) ) {
					wp_delete_file( $path );
				}
			}
			$item = readdir( $handle );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_closedir -- paired with opendir.
		closedir( $handle );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Temp cleanup after PDF render.
		@rmdir( rtrim( $dir, '/\\' ) );
	}
}
