<?php
/**
 * Builds ZIP bundles of persisted Markdown / optional PDF peers.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streams a ZIP of selected report artefacts (UTF-8 filenames, collision-safe per report id).
 */
class WPMAR_Report_Zip_Export {

	/**
	 * Streams a ZIP response and terminates the request (admin download handler).
	 *
	 * @param array<int,int> $ids Report primary keys.
	 * @return void
	 */
	public static function stream_zip_for_ids( array $ids ) {
		$ids = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $v ) {
							return absint( $v );
						},
						$ids
					)
				)
			)
		);

		if ( empty( $ids ) ) {
			wp_die( esc_html__( 'レポートが選択されていません。', 'wp-maintenance-audit-reporter' ) );
		}

		if ( ! class_exists( 'ZipArchive', false ) ) {
			wp_die( esc_html__( 'このサーバーでは ZipArchive が利用できないため ZIP を作成できません。', 'wp-maintenance-audit-reporter' ) );
		}

		$repo = new WPMAR_Report_Repository();
		$zip  = new ZipArchive();

		$tmp = wp_tempnam( 'wpmar-reports-' );
		if ( false === $tmp || ! is_string( $tmp ) ) {
			wp_die( esc_html__( '一時ファイルを確保できませんでした。', 'wp-maintenance-audit-reporter' ) );
		}

		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE ) ) {
			wp_delete_file( $tmp );
			wp_die( esc_html__( 'ZIP アーカイブを初期化できませんでした。', 'wp-maintenance-audit-reporter' ) );
		}

		foreach ( $ids as $id ) {
			if ( $id <= 0 ) {
				continue;
			}

			$row = $repo->find( $id );
			if ( null === $row ) {
				continue;
			}

			$base = 'wpmar-report-' . $id;

			$md_path = isset( $row['md_file_path'] ) ? (string) $row['md_file_path'] : '';
			$md_abs  = '' !== $md_path
				? WPMAR_MD_Writer::absolute_path_from_upload_relative( $md_path )
				: '';

			if ( '' !== $md_abs && is_readable( $md_abs ) ) {
				$zip->addFile( $md_abs, $base . '.md' );
			} elseif ( ! empty( $row['body_md'] ) ) {
				$zip->addFromString( $base . '.md', (string) $row['body_md'] );
			}

			$pdf_path = isset( $row['pdf_file_path'] ) ? (string) $row['pdf_file_path'] : '';
			$pdf_abs  = '' !== $pdf_path
				? WPMAR_MD_Writer::absolute_path_from_upload_relative( $pdf_path )
				: '';

			if ( '' !== $pdf_abs && is_readable( $pdf_abs ) ) {
				$zip->addFile( $pdf_abs, $base . '.pdf' );
			}
		}

		$zip->close();

		if ( ! is_readable( $tmp ) ) {
			wp_delete_file( $tmp );
			wp_die( esc_html__( 'ZIP を書き込めませんでした。', 'wp-maintenance-audit-reporter' ) );
		}

		$size = filesize( $tmp );
		if ( false === $size ) {
			wp_delete_file( $tmp );
			wp_die( esc_html__( 'ZIP サイズを取得できませんでした。', 'wp-maintenance-audit-reporter' ) );
		}

		$name = 'wpmar-reports-' . gmdate( 'Ymd-His' ) . '.zip';

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . (string) $size );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Deliberate download of generated temp file.
		readfile( $tmp );
		wp_delete_file( $tmp );
		exit;
	}
}
