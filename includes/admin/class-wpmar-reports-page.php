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
	 * Outputs either the list or a record detail view.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::render_notices();

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

			<div id="wpmar-delete-confirm" class="wpmar-delete-confirm" hidden>
				<button type="button" class="wpmar-delete-confirm__backdrop" tabindex="-1" aria-hidden="true"></button>
				<div
					class="wpmar-delete-confirm__panel"
					role="dialog"
					aria-modal="true"
					aria-labelledby="wpmar-delete-confirm-label"
				>
					<p id="wpmar-delete-confirm-label" class="wpmar-delete-confirm__message"></p>
					<p class="wpmar-delete-confirm__actions">
						<button type="button" class="button" id="wpmar-delete-confirm-back">
							<?php esc_html_e( '戻る', 'wp-maintenance-audit-reporter' ); ?>
						</button>
						<button type="button" class="button button-link-delete" id="wpmar-delete-confirm-do">
							<?php esc_html_e( '削除', 'wp-maintenance-audit-reporter' ); ?>
						</button>
					</p>
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

		?>
		<div class="wrap">
			<h1><?php echo esc_html( $title ); ?></h1>
			<p>
				<a class="button" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( '一覧へ戻る', 'wp-maintenance-audit-reporter' ); ?></a>
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

			<h2><?php esc_html_e( '本文 (Markdown)', 'wp-maintenance-audit-reporter' ); ?></h2>
			<pre style="white-space:pre-wrap;background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:640px;overflow:auto;"><?php echo esc_html( (string) ( $row['body_md'] ?? '' ) ); ?></pre>
		</div>
		<?php
	}
}
