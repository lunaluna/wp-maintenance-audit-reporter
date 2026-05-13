<?php
/**
 * Paginated report history table.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table', false ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Presents stored audits for bulk + row actions.
 */
class WPMAR_Reports_List_Table extends WP_List_Table {

	/**
	 * Repository instance.
	 *
	 * @var WPMAR_Report_Repository
	 */
	protected $repository;

	/**
	 * Constructor wires plural labels for bulk nonce IDs.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'plural'   => 'reports',
				'singular' => 'report',
				'ajax'     => false,
			)
		);

		$this->repository = new WPMAR_Report_Repository();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->handle_bulk_delete();

		$columns  = $this->get_columns();
		$hidden   = array();
		$sortable = $this->get_sortable_columns();
		$primary  = 'id';

		$this->_column_headers = array( $columns, $hidden, $sortable, $primary );

		$per_page     = 20;
		$current_page = $this->get_pagenum();

		$page = $this->repository->list_page( $per_page, $current_page );

		$this->items = $page['items'];

		$this->set_pagination_args(
			array(
				'total_items' => $page['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $page['total'] / $per_page ),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'cb'            => '<input type="checkbox" />',
			'id'            => __( 'ID', 'wp-maintenance-audit-reporter' ),
			'created_at'    => __( '作成日時 (UTC)', 'wp-maintenance-audit-reporter' ),
			'status'        => __( '状態', 'wp-maintenance-audit-reporter' ),
			'change_count'  => __( '変更件数', 'wp-maintenance-audit-reporter' ),
			'mail_sent'     => __( 'メール', 'wp-maintenance-audit-reporter' ),
			'domain_match'  => __( 'ドメイン', 'wp-maintenance-audit-reporter' ),
			'triggered_by'  => __( '起動元', 'wp-maintenance-audit-reporter' ),
			'duration_sec'  => __( '所要秒', 'wp-maintenance-audit-reporter' ),
			'backup_status' => __( 'バックアップ', 'wp-maintenance-audit-reporter' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $item Current row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="report[]" value="%d" />',
			absint( $item['id'] ?? 0 )
		);
	}

	/**
	 * Primary column renders row actions.
	 *
	 * @param array<string,mixed> $item Row.
	 * @return string
	 */
	protected function column_id( $item ) {
		$id = absint( $item['id'] ?? 0 );

		$view_url = add_query_arg(
			array(
				'page'      => WPMAR_REPORTS_PAGE_SLUG,
				'report_id' => $id,
			),
			admin_url( 'admin.php' )
		);

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'      => WPMAR_REPORTS_PAGE_SLUG,
					'action'    => 'delete',
					'report_id' => $id,
				),
				admin_url( 'admin.php' )
			),
			'wpmar_delete_report_' . $id
		);

		$actions = array(
			'view'   => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $view_url ),
				esc_html__( '表示', 'wp-maintenance-audit-reporter' )
			),
			'delete' => sprintf(
				'<a href="%s" class="wpmar-report-delete">%s</a>',
				esc_url( $delete_url ),
				esc_html__( '削除', 'wp-maintenance-audit-reporter' )
			),
		);

		$title = sprintf(
			'<a href="%s" class="row-title">%s</a>',
			esc_url( $view_url ),
			esc_html( sprintf( '#%d', $id ) )
		);

		return sprintf( '%1$s %2$s', $title, $this->row_actions( $actions, true ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $item        Row data.
	 * @param string              $column_name Accessor.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		if ( isset( $item[ $column_name ] ) ) {
			return esc_html( (string) $item[ $column_name ] );
		}

		if ( 'domain_match' === $column_name ) {
			return ! empty( $item['domain_matched'] )
				? esc_html__( '一致', 'wp-maintenance-audit-reporter' )
				: esc_html__( '不一致', 'wp-maintenance-audit-reporter' );
		}

		if ( 'backup_status' === $column_name ) {
			return '—';
		}

		return '';
	}

	/**
	 * Formats the mail delivery column.
	 *
	 * @param array<string,mixed> $item Row.
	 * @return string
	 */
	protected function column_mail_sent( $item ) {
		return ! empty( $item['mail_sent'] )
			? esc_html__( '送信済', 'wp-maintenance-audit-reporter' )
			: esc_html__( '未送信', 'wp-maintenance-audit-reporter' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,mixed>
	 */
	protected function get_bulk_actions() {
		return array(
			'delete' => __( '一括削除', 'wp-maintenance-audit-reporter' ),
		);
	}

	/**
	 * Deletes selected identifiers.
	 *
	 * @return void
	 */
	protected function handle_bulk_delete() {
		if ( 'delete' !== $this->current_action() ) {
			return;
		}

		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		$reports = array();
		if ( isset( $_POST['report'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cast to int via absint immediately below.
			$reports = array_map( 'absint', wp_unslash( (array) $_POST['report'] ) );
		}

		if ( empty( $reports ) ) {
			return;
		}

		foreach ( $reports as $maybe_id ) {
			$id = absint( $maybe_id );
			if ( $id > 0 ) {
				$this->repository->delete_row( $id );
			}
		}

		WPMAR_Reports_Page::set_flash_notice( 'bulk_deleted' );

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => WPMAR_REPORTS_PAGE_SLUG ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Empty state copy.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( '保存済みのレポートはまだありません。フル実行後にここへ一覧されます。', 'wp-maintenance-audit-reporter' );
	}
}
