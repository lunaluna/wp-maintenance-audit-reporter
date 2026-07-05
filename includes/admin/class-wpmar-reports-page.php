<?php
/**
 * Admin UI for browsing persisted audits.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders list + detail templates for report storage.
 */
class WPMAR_Reports_Page {

	/**
	 * User-scoped one-shot admin notice (survives redirect without leaving query args in the URL).
	 */
	const FLASH_TRANSIENT_PREFIX = 'wpmar_reports_flash_';

	/**
	 * Stores a notice code for the current user; consumed on the next reports screen render.
	 *
	 * @param string $code `deleted` or `bulk_deleted`.
	 * @return void
	 */
	public static function set_flash_notice( $code ) {
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			return;
		}

		$code = sanitize_key( (string) $code );
		if ( ! in_array( $code, array( 'deleted', 'bulk_deleted' ), true ) ) {
			return;
		}

		set_transient( self::FLASH_TRANSIENT_PREFIX . $uid, $code, 120 );
	}

	/**
	 * Removes legacy `wpmar_msg` from the URL via redirect (bookmarks and older plugin redirects).
	 *
	 * Runs after {@see handle_get_actions()} so GET delete is unaffected.
	 *
	 * @return void
	 */
	public static function strip_legacy_notice_query_arg() {
		if ( ! is_admin() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || WPMAR_REPORTS_PAGE_SLUG !== $_GET['page'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['wpmar_msg'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_safe_redirect( esc_url_raw( remove_query_arg( 'wpmar_msg' ) ) );
		exit;
	}

	/**
	 * Reads and clears a pending notice for the current user.
	 *
	 * @return string Empty string if none.
	 */
	private static function consume_flash_notice() {
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			return '';
		}

		$key  = self::FLASH_TRANSIENT_PREFIX . $uid;
		$code = get_transient( $key );

		if ( ! is_string( $code ) || '' === $code ) {
			return '';
		}

		delete_transient( $key );

		return sanitize_key( $code );
	}

	/**
	 * Routes GET delete actions before rendering output.
	 *
	 * @return void
	 */
	public static function handle_get_actions() {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! isset( $_GET['page'] ) || WPMAR_REPORTS_PAGE_SLUG !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( empty( $_GET['action'] ) || 'delete' !== $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$id = isset( $_GET['report_id'] ) ? absint( $_GET['report_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $id <= 0 ) {
			return;
		}

		check_admin_referer( 'wpmar_delete_report_' . $id );

		$repository = new WPMAR_Report_Repository();
		$repository->delete_row( $id );

		self::set_flash_notice( 'deleted' );

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => WPMAR_REPORTS_PAGE_SLUG ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Streams Markdown / PDF attachments before WordPress prints admin chrome.
	 *
	 * @return void
	 */
	public static function maybe_stream_report_download() {
		if ( ! is_admin() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked after the capability gate; download route makes no durable state change (any on-the-fly PDF is streamed then removed).
		if ( ! isset( $_GET['page'] ) || WPMAR_REPORTS_PAGE_SLUG !== $_GET['page'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['wpmar_download'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '権限がありません。', 'wp-maintenance-audit-reporter' ) );
		}

		$type = isset( $_GET['wpmar_download'] ) ? sanitize_key( wp_unslash( $_GET['wpmar_download'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id   = isset( $_GET['report_id'] ) ? absint( $_GET['report_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $id <= 0 ) {
			wp_die( esc_html__( 'レポート ID が無効です。', 'wp-maintenance-audit-reporter' ) );
		}

		check_admin_referer( 'wpmar_dl_' . $type . '_' . $id );

		$repository = new WPMAR_Report_Repository();
		$row        = $repository->find( $id );

		if ( null === $row ) {
			wp_die( esc_html__( '指定されたレポートは存在しません。', 'wp-maintenance-audit-reporter' ) );
		}

		$dl_domain = sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $dl_domain ) {
			$dl_domain = 'site';
		}
		$created_ts  = strtotime( (string) ( $row['created_at'] ?? '' ) );
		$dl_date_his = false !== $created_ts ? gmdate( 'Ymd-His', $created_ts ) : gmdate( 'Ymd-His' );
		$dl_date_ymd = false !== $created_ts ? gmdate( 'Ymd', $created_ts ) : gmdate( 'Ymd' );

		if ( 'md' === $type ) {
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="wpmar-report-' . $dl_domain . '-admin-' . $dl_date_his . '.md"' );
			$body = (string) ( $row['body_md'] ?? '' );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown export payload for operators.
			echo $body;
			exit;
		}

		if ( 'client_md' === $type ) {
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="wpmar-report-' . $dl_domain . '-client-' . $dl_date_ymd . '.md"' );
			$body = (string) ( $row['body_client_md'] ?? '' );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown export payload for clients.
			echo $body;
			exit;
		}

		if ( 'pdf' !== $type ) {
			wp_die( esc_html__( '未対応の形式です。', 'wp-maintenance-audit-reporter' ) );
		}

		$rel = (string) ( $row['pdf_file_path'] ?? '' );

		// Upload-relative path of an on-the-fly copy to remove after streaming, if any.
		$transient_rel = '';

		if ( '' === $rel ) {
			// No persisted PDF (e.g. the report predates the PDF library being installed).
			// Render a transient copy for THIS download only: a GET must not mutate durable
			// state, so the path is neither persisted to the DB nor kept on disk. PDFs are
			// persisted at audit-run time; this is only a read-time fallback.
			$pdf_md = WPMAR_PDF_Writer::markdown_body_for_client_pdf( $row );
			if ( '' !== $pdf_md && WPMAR_PDF_Writer::is_available() ) {
				$written = WPMAR_PDF_Writer::write_pdf_from_markdown(
					$pdf_md,
					'wpmar-report-' . $dl_domain . '-client-' . $dl_date_ymd . '-' . $id . '-tmp-' . wp_rand( 100000, 999999 )
				);
				if ( is_wp_error( $written ) ) {
					wp_die( esc_html( $written->get_error_message() ) );
				}
				if ( is_string( $written ) && '' !== $written ) {
					$rel           = $written;
					$transient_rel = $written;
				}
			}
		}

		if ( '' === $rel ) {
			wp_die( esc_html__( 'PDF を出力できません。クライアント向けのレポート本文がないか、PDF ライブラリがインストールされていません。監査を実行した後に試すか、管理画面の「PDF ライブラリ」設定からライブラリをインストールしてください。', 'wp-maintenance-audit-reporter' ) );
		}

		$abs = WPMAR_MD_Writer::absolute_path_from_upload_relative( $rel );
		if ( '' === $abs || ! is_readable( $abs ) ) {
			if ( '' !== $transient_rel ) {
				WPMAR_MD_Writer::delete_if_upload_relative( $transient_rel );
			}
			wp_die( esc_html__( 'PDF ファイルを読み込めませんでした。', 'wp-maintenance-audit-reporter' ) );
		}

		$size = filesize( $abs );
		if ( false === $size ) {
			if ( '' !== $transient_rel ) {
				WPMAR_MD_Writer::delete_if_upload_relative( $transient_rel );
			}
			wp_die( esc_html__( 'PDF サイズを取得できませんでした。', 'wp-maintenance-audit-reporter' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="wpmar-report-' . $dl_domain . '-client-' . $dl_date_ymd . '-' . $id . '.pdf"' );
		header( 'Content-Length: ' . (string) $size );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams binary PDF artefact.
		$bytes = readfile( $abs );

		// Remove the transient copy: the download GET leaves no persisted file or DB change.
		if ( '' !== $transient_rel ) {
			WPMAR_MD_Writer::delete_if_upload_relative( $transient_rel );
		}

		if ( false === $bytes ) {
			wp_die( esc_html__( 'PDF の送信中にエラーが発生しました。', 'wp-maintenance-audit-reporter' ) );
		}
		exit;
	}

	/**
	 * Streams bulk ZIP before admin HTML is sent (must not run inside {@see render()}).
	 *
	 * @return void
	 */
	public static function maybe_stream_bulk_zip() {
		if ( ! is_admin() ) {
			return;
		}

		if ( empty( $_POST ) ) {
			return;
		}

		if ( ! isset( $_POST['_wpnonce'] ) ) {
			return;
		}

		// Identify the request (which screen), then gate on capability, then verify the nonce.
		if ( empty( $_POST['page'] ) || WPMAR_REPORTS_PAGE_SLUG !== sanitize_key( wp_unslash( $_POST['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- routing check; nonce verified below before any action.
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'bulk-reports' ) ) {
			return;
		}

		// Mirror {@see WP_List_Table::current_action()}: `action` then `action2`, skip -1 / filter UI.
		$bulk = '';
		if ( empty( $_POST['filter_action'] ) ) {
			$action_top = '';
			if ( isset( $_POST['action'] ) ) {
				$action_top = sanitize_text_field( wp_unslash( (string) $_POST['action'] ) );
			}
			if ( '' !== $action_top && '-1' !== $action_top ) {
				$bulk = sanitize_key( $action_top );
			}
			if ( '' === $bulk ) {
				$action_bottom = '';
				if ( isset( $_POST['action2'] ) ) {
					$action_bottom = sanitize_text_field( wp_unslash( (string) $_POST['action2'] ) );
				}
				if ( '' !== $action_bottom && '-1' !== $action_bottom ) {
					$bulk = sanitize_key( $action_bottom );
				}
			}
		}

		if ( 'wpmar_zip' !== $bulk ) {
			return;
		}

		$reports = array();
		if ( isset( $_POST['report'] ) ) {
			$reports = array_map( 'absint', wp_unslash( (array) $_POST['report'] ) );
		}

		if ( empty( $reports ) ) {
			return;
		}

		WPMAR_Report_Zip_Export::stream_zip_for_ids( $reports );
	}

	/**
	 * Outputs either the list or a record detail view.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::render_notices();
		WPMAR_Admin_Menu::maybe_render_audit_storage_empty_notice();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only GET `report_id` routes to detail; no state change on this request.
		if ( isset( $_GET['report_id'] ) ) {
			self::render_detail( absint( $_GET['report_id'] ) );

			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		self::render_list();
	}

	/**
	 * Prints cached admin pointers for destructive flows.
	 *
	 * @return void
	 */
	private static function render_notices() {
		$code = self::consume_flash_notice();
		if ( '' === $code ) {
			return;
		}

		if ( 'deleted' === $code ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'レポートを削除しました。', 'wp-maintenance-audit-reporter' )
			);
		}

		if ( 'bulk_deleted' === $code ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( '選択したレポートを削除しました。', 'wp-maintenance-audit-reporter' )
			);
		}
	}

	/**
	 * Table view.
	 *
	 * @return void
	 */
	private static function render_list() {
		$table = new WPMAR_Reports_List_Table();
		$table->prepare_items();

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Maintenance Audit — レポート一覧', 'wp-maintenance-audit-reporter' ); ?></h1>
			<hr class="wp-header-end" />
			<p>
				<a class="button" href="<?php echo esc_url( WPMAR_Admin_Menu::admin_screen_url( WPMAR_ADMIN_PAGE_SLUG ) ); ?>">
					<?php esc_html_e( '設定に戻る', 'wp-maintenance-audit-reporter' ); ?>
				</a>
			</p>

			<form id="wpmar-reports-form" method="post" action="<?php echo esc_url( WPMAR_Admin_Menu::admin_screen_url( WPMAR_REPORTS_PAGE_SLUG ) ); ?>">
				<?php wp_nonce_field( 'bulk-reports' ); ?>
				<input type="hidden" name="page" value="<?php echo esc_attr( WPMAR_REPORTS_PAGE_SLUG ); ?>" />
				<?php $table->display(); ?>
			</form>

			<div id="wpmar-delete-report-modal" class="wpmar-modal-overlay" hidden aria-hidden="true">
				<div
					class="wpmar-modal-panel"
					role="dialog"
					aria-modal="true"
					aria-labelledby="wpmar-delete-report-modal-title"
					aria-describedby="wpmar-delete-report-modal-body"
				>
					<h2 id="wpmar-delete-report-modal-title" class="wpmar-modal-title">
						<?php esc_html_e( '削除の確認', 'wp-maintenance-audit-reporter' ); ?>
					</h2>
					<p id="wpmar-delete-report-modal-body" class="wpmar-modal-body" data-wpmar-delete-modal-body></p>
					<div class="wpmar-modal-actions">
						<button type="button" class="button" data-wpmar-delete-cancel>
							<?php esc_html_e( 'キャンセル', 'wp-maintenance-audit-reporter' ); ?>
						</button>
						<button type="button" class="button button-primary" data-wpmar-delete-confirm>
							<?php esc_html_e( 'OK', 'wp-maintenance-audit-reporter' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Read-only detail screen for Markdown bodies.
	 *
	 * @param int $report_id PK.
	 * @return void
	 */
	private static function render_detail( $report_id ) {
		$repository = new WPMAR_Report_Repository();
		$row        = $repository->find( $report_id );

		$list_url = add_query_arg(
			array(
				'page' => WPMAR_REPORTS_PAGE_SLUG,
			),
			admin_url( 'admin.php' )
		);

		if ( null === $row ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( '指定されたレポートは存在しません。', 'wp-maintenance-audit-reporter' )
			);
			printf(
				'<p><a class="button" href="%s">%s</a></p>',
				esc_url( $list_url ),
				esc_html__( '一覧へ戻る', 'wp-maintenance-audit-reporter' )
			);

			return;
		}

		$title = sprintf(
			/* translators: %d: report ID */
			__( 'レポート #%d', 'wp-maintenance-audit-reporter' ),
			absint( $report_id )
		);

		$md_dl_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'           => WPMAR_REPORTS_PAGE_SLUG,
					'report_id'      => $report_id,
					'wpmar_download' => 'md',
				),
				admin_url( 'admin.php' )
			),
			'wpmar_dl_md_' . $report_id
		);

		$pdf_dl_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'           => WPMAR_REPORTS_PAGE_SLUG,
					'report_id'      => $report_id,
					'wpmar_download' => 'pdf',
				),
				admin_url( 'admin.php' )
			),
			'wpmar_dl_pdf_' . $report_id
		);

		$client_md_dl_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'           => WPMAR_REPORTS_PAGE_SLUG,
					'report_id'      => $report_id,
					'wpmar_download' => 'client_md',
				),
				admin_url( 'admin.php' )
			),
			'wpmar_dl_client_md_' . $report_id
		);

		$pdf_available = WPMAR_PDF_Writer::is_available();

		?>
		<div class="wrap">
			<h1><?php echo esc_html( $title ); ?></h1>
			<p>
				<a class="button" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( '一覧へ戻る', 'wp-maintenance-audit-reporter' ); ?></a>
				<a class="button" href="<?php echo esc_url( $md_dl_url ); ?>"><?php esc_html_e( 'Markdown をダウンロード（管理者向け）', 'wp-maintenance-audit-reporter' ); ?></a>
				<?php if ( $pdf_available ) : ?>
					<a class="button" href="<?php echo esc_url( $pdf_dl_url ); ?>"><?php esc_html_e( 'PDF をダウンロード（クライアント向け）', 'wp-maintenance-audit-reporter' ); ?></a>
				<?php else : ?>
					<a class="button" href="<?php echo esc_url( $client_md_dl_url ); ?>"><?php esc_html_e( 'Markdown をダウンロード（クライアント向け）', 'wp-maintenance-audit-reporter' ); ?></a>
				<?php endif; ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( '作成日時 (UTC)', 'wp-maintenance-audit-reporter' ); ?></th>
					<td><?php echo esc_html( (string) ( $row['created_at'] ?? '' ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '状態', 'wp-maintenance-audit-reporter' ); ?></th>
					<td><?php echo esc_html( (string) ( $row['status'] ?? '' ) ); ?></td>
				</tr>
			</table>

			<h2><?php esc_html_e( '本文（管理者向け・Markdown）', 'wp-maintenance-audit-reporter' ); ?></h2>
			<pre style="white-space:pre-wrap;background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:640px;overflow:auto;"><?php echo esc_html( (string) ( $row['body_md'] ?? '' ) ); ?></pre>
		</div>
		<?php
	}
}
