<?php
/**
 * Registers the Maintenance Audit admin menu (top-level + submenus) and validates POST actions.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires dashboard menu, assets, and nonce-protected form posts.
 */
class WPMAR_Admin_Menu {

	/** Capability required to adjust plugin settings / trigger runs. */
	const CAPABILITY = 'manage_options';

	/**
	 * Wire WordPress hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'plugin_action_links_' . WPMAR_PLUGIN_BASENAME, array( __CLASS__, 'plugin_action_links' ), 10, 2 );

		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_init', array( 'WPMAR_Reports_Page', 'maybe_stream_report_download' ), 0 );
		add_action( 'admin_init', array( 'WPMAR_Reports_Page', 'maybe_stream_bulk_zip' ), 0 );
		add_action( 'admin_init', array( 'WPMAR_Reports_Page', 'handle_get_actions' ), 1 );
		add_action( 'admin_init', array( 'WPMAR_Reports_Page', 'strip_legacy_notice_query_arg' ), 2 );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		WPMAR_PDF_Installer::register_hooks();
	}

	/**
	 * Holds dry-run preview JSON until {@see WPMAR_Settings_Page::render()} (same POST request only).
	 *
	 * @var string
	 */
	private static $dry_run_brevity_inline = '';

	/**
	 * Returns brevity JSON from the preceding dry POST in this request, then clears the stash.
	 *
	 * @return string
	 */
	public static function consume_dry_run_brevity() {
		$blob                         = self::$dry_run_brevity_inline;
		self::$dry_run_brevity_inline = '';

		if ( ! is_string( $blob ) || '' === trim( $blob ) ) {
			return '';
		}

		return $blob;
	}

	/**
	 * Holds the job id enqueued by a 「今すぐ実行」 POST until {@see WPMAR_Settings_Page::render()}
	 * renders the polling panel (same POST request only).
	 *
	 * @var string
	 */
	private static $queued_job_id = '';

	/**
	 * Returns (and clears) the job id queued during this request's full_run POST.
	 *
	 * @return string Empty string if none.
	 */
	public static function consume_queued_job_id() {
		$id                  = self::$queued_job_id;
		self::$queued_job_id = '';

		return WPMAR_Jobs_Repository::sanitize_id( (string) $id );
	}

	/**
	 * Shared localisation for the async-job poller (REST config + status/link strings).
	 *
	 * Merged into the `wpmarAdminBusy` object on both the single-site and network screens.
	 *
	 * @return array<string,string>
	 */
	public static function polling_l10n() {
		return array(
			'restBase'    => esc_url_raw( rest_url( WPMAR_Jobs_REST::NAMESPACE_V1 . '/jobs/' ) ),
			'restNonce'   => wp_create_nonce( 'wp_rest' ),
			'pollQueued'  => __( 'キューで待機中です…', 'wp-maintenance-audit-reporter' ),
			'pollRunning' => __( 'バックグラウンドで監査を実行しています…', 'wp-maintenance-audit-reporter' ),
			'pollDone'    => __( 'レポート生成が完了しました。', 'wp-maintenance-audit-reporter' ),
			'pollFailed'  => __( 'レポート生成に失敗しました。', 'wp-maintenance-audit-reporter' ),
			'pollError'   => __( 'ジョブ状態の取得中にエラーが発生しました。', 'wp-maintenance-audit-reporter' ),
			'linkReport'  => __( 'レポート詳細を開く', 'wp-maintenance-audit-reporter' ),
			'linkMd'      => __( 'Markdown をダウンロード（管理者向け）', 'wp-maintenance-audit-reporter' ),
			'linkPdf'     => __( 'PDF をダウンロード（クライアント向け）', 'wp-maintenance-audit-reporter' ),
			'linkClient'  => __( 'Markdown をダウンロード（クライアント向け）', 'wp-maintenance-audit-reporter' ),
		);
	}

	/**
	 * Outputs the async-job polling panel for a queued job.
	 *
	 * Shared by the single-site settings screen and the network rollup screen; the
	 * client-side poller (assets/js/admin.js) reads the data attributes and updates
	 * the message/links via the REST endpoint.
	 *
	 * @param string $job_id Queued job id.
	 * @return void
	 */
	public static function render_job_status_panel( $job_id ) {
		$job_id = WPMAR_Jobs_Repository::sanitize_id( (string) $job_id );
		if ( '' === $job_id ) {
			return;
		}
		?>
		<div
			class="wpmar-section-panel wpmar-job-panel"
			data-wpmar-job-poll="1"
			data-wpmar-job-id="<?php echo esc_attr( $job_id ); ?>"
		>
			<h2><?php esc_html_e( 'レポート生成ジョブ', 'wp-maintenance-audit-reporter' ); ?></h2>
			<p class="wpmar-job-status" aria-live="polite">
				<span class="wpmar-spinner" data-wpmar-job-spinner aria-hidden="true"></span>
				<span data-wpmar-job-message><?php esc_html_e( 'キューで待機中です…', 'wp-maintenance-audit-reporter' ); ?></span>
			</p>
			<ul class="wpmar-job-links" data-wpmar-job-links hidden></ul>
		</div>
		<?php
	}

	/**
	 * URL for a screen registered under admin.php?page=…
	 *
	 * @param string $page_slug {@see WPMAR_ADMIN_PAGE_SLUG} or {@see WPMAR_REPORTS_PAGE_SLUG}.
	 * @return string
	 */
	public static function admin_screen_url( $page_slug ) {
		return add_query_arg(
			'page',
			sanitize_key( (string) $page_slug ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Prepends 「設定」 to the Plugins row (before Deactivate).
	 *
	 * @param array<int,string> $links Existing plugin action links (typically Deactivate).
	 * @param string            $file  Plugin basename as passed by WordPress.
	 * @return array<int,string>
	 */
	public static function plugin_action_links( $links, $file ) {
		if ( WPMAR_PLUGIN_BASENAME !== $file ) {
			return $links;
		}

		$url             = esc_url( self::admin_screen_url( WPMAR_ADMIN_PAGE_SLUG ) );
		$settings_anchor = sprintf(
			'<a href="%s">%s</a>',
			$url,
			esc_html__( '設定', 'wp-maintenance-audit-reporter' )
		);

		return array_merge( array( $settings_anchor ), $links );
	}

	/**
	 * Whether both report rows and snapshot rows are absent (fresh or fully cleared DB).
	 *
	 * @return bool
	 */
	public static function audit_storage_is_empty() {
		static $memo = null;

		if ( null !== $memo ) {
			return $memo;
		}

		$reports_count   = ( new WPMAR_Report_Repository() )->count_all();
		$snapshots_count = ( new WPMAR_Snapshot_Repository() )->count_all();
		$memo            = ( 0 === $reports_count && 0 === $snapshots_count );

		return $memo;
	}

	/**
	 * Prints an informational notice when no persisted audit data exists yet.
	 *
	 * @return void
	 */
	public static function maybe_render_audit_storage_empty_notice() {
		if ( ! self::audit_storage_is_empty() ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html__( '現在はレポートのためのデータが未取得か、またはデータがすべて削除されています', 'wp-maintenance-audit-reporter' )
		);
	}

	/**
	 * Loads scripts/styles on Maintenance Audit screens only.
	 *
	 * @param string $hook_suffix Passed by admin_enqueue_scripts.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		/*
		 * WordPress builds $hook_suffix via get_plugin_page_hookname(); it is NOT always
		 * "{$parent_slug}_page_{$menu_slug}". Top-level menus store sanitize_title( $menu_title )
		 * in $GLOBALS['admin_page_hooks'], so submenu hooks look like "{sanitized-menu-title}_page_{slug}"
		 * (e.g. maintenance-audit_page_wpmar-reports), not "wpmar-maintenance-report_page_…".
		 */
		if ( ! function_exists( 'get_plugin_page_hookname' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$allowed = array_unique(
			array_filter(
				array(
					get_plugin_page_hookname( WPMAR_ADMIN_PAGE_SLUG, '' ),
					get_plugin_page_hookname( WPMAR_ADMIN_PAGE_SLUG, WPMAR_ADMIN_PAGE_SLUG ),
					get_plugin_page_hookname( WPMAR_REPORTS_PAGE_SLUG, WPMAR_ADMIN_PAGE_SLUG ),
				)
			)
		);

		if ( ! in_array( $hook_suffix, $allowed, true ) ) {
			return;
		}

		$base_ver       = defined( 'WPMAR_VERSION' ) ? WPMAR_VERSION : '0';
		$admin_js_path  = WPMAR_PLUGIN_DIR . 'assets/js/admin.js';
		$admin_css_path = WPMAR_PLUGIN_DIR . 'assets/css/admin.css';
		$admin_js_ver   = $base_ver;
		$admin_css_ver  = $base_ver;
		if ( is_readable( $admin_js_path ) ) {
			$admin_js_ver .= '-' . (string) filemtime( $admin_js_path );
		}
		if ( is_readable( $admin_css_path ) ) {
			$admin_css_ver .= '-' . (string) filemtime( $admin_css_path );
		}

		wp_enqueue_style(
			'wpmar-admin',
			WPMAR_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			$admin_css_ver
		);

		wp_enqueue_script(
			'wpmar-admin',
			WPMAR_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			$admin_js_ver,
			true
		);

		$wpmar_admin_l10n = array_merge(
			array(
				'dryRun'  => __( 'ドライランを実行しています…', 'wp-maintenance-audit-reporter' ),
				'fullRun' => __( 'レポート生成をキューに追加しています…', 'wp-maintenance-audit-reporter' ),
			),
			self::polling_l10n()
		);

		wp_localize_script(
			'wpmar-admin',
			'wpmarAdminBusy',
			$wpmar_admin_l10n
		);
	}

	/**
	 * Top-level Maintenance Audit menu + Submenus (設定・実行 / レポート).
	 *
	 * @return void
	 */
	public static function register_page() {
		add_menu_page(
			__( 'Maintenance Audit', 'wp-maintenance-audit-reporter' ),
			__( 'Maintenance Audit', 'wp-maintenance-audit-reporter' ),
			self::CAPABILITY,
			WPMAR_ADMIN_PAGE_SLUG,
			array( 'WPMAR_Settings_Page', 'render' ),
			'dashicons-clipboard',
			81
		);

		add_submenu_page(
			WPMAR_ADMIN_PAGE_SLUG,
			__( 'Maintenance Audit', 'wp-maintenance-audit-reporter' ),
			__( '設定・実行', 'wp-maintenance-audit-reporter' ),
			self::CAPABILITY,
			WPMAR_ADMIN_PAGE_SLUG,
			array( 'WPMAR_Settings_Page', 'render' )
		);

		add_submenu_page(
			WPMAR_ADMIN_PAGE_SLUG,
			__( 'Maintenance Audit — レポート一覧', 'wp-maintenance-audit-reporter' ),
			__( 'レポート', 'wp-maintenance-audit-reporter' ),
			self::CAPABILITY,
			WPMAR_REPORTS_PAGE_SLUG,
			array( 'WPMAR_Reports_Page', 'render' )
		);
	}

	/**
	 * Persists settings or triggers runner actions.
	 *
	 * @return void
	 */
	public static function handle_post() {
		if ( ! isset( $_POST['wpmar_settings_nonce'] ) ) {
			return;
		}

		check_admin_referer( 'wpmar_settings_save', 'wpmar_settings_nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'wp-maintenance-audit-reporter' ) );
		}

		if ( ! isset( $_POST['wpmar_admin_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['wpmar_admin_action'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_key.
		$input  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Handed to sanitizer.

		// Buttons share one form; discriminate via `wpmar_admin_action`.
		switch ( $action ) {
			case 'save':
				// Persist settings shape then realign chained single-event Cron.
				$merged = WPMAR_Settings::merge_form_input( $input, WPMAR_Settings::get_all() );
				WPMAR_Settings::update_all( $merged );
				WPMAR_Scheduler::reschedule();
				add_settings_error(
					'wpmar_messages',
					'wpmar_saved',
					__( '設定を保存し、次回の Cron を再計算しました。', 'wp-maintenance-audit-reporter' ),
					'success'
				);
				break;
			case 'dry_run':
				if ( WPMAR_Network::per_site_runs_disabled() ) {
					add_settings_error(
						'wpmar_messages',
						'wpmar_network_only',
						__( 'ネットワーク集約監査が有効なため、子サイトからの実行はできません。ネットワーク管理画面から実行してください。', 'wp-maintenance-audit-reporter' ),
						'error'
					);
					break;
				}
				// Collect-only path: skips DB artefacts; preview is rendered in the same POST response.
				$runner = new WPMAR_Runner();
				$result = $runner->run(
					array(
						'dry'          => true,
						'triggered_by' => 'manual',
					)
				);
				if ( isset( $result['dry_brevity'] ) && is_string( $result['dry_brevity'] ) ) {
					self::$dry_run_brevity_inline = $result['dry_brevity'];
				}
				add_settings_error(
					'wpmar_messages',
					'wpmar_dry',
					__( 'ドライランを完了しました（保存・通知なし）。', 'wp-maintenance-audit-reporter' ),
					'success'
				);
				break;
			case 'full_run':
				if ( WPMAR_Network::per_site_runs_disabled() ) {
					add_settings_error(
						'wpmar_messages',
						'wpmar_network_only',
						__( 'ネットワーク集約監査が有効なため、子サイトからの実行はできません。ネットワーク管理画面から実行してください。', 'wp-maintenance-audit-reporter' ),
						'error'
					);
					break;
				}
				// On-demand audit, now enqueued as a background job so the request returns
				// immediately (avoids the CloudFront 504 the synchronous path produced).
				$persist = ! empty( $input['wpmar_persist_snapshots'] );
				$qa_mail = '';
				if ( isset( $input['wpmar_qa_mail'] ) ) {
					$qa_mail = sanitize_email( $input['wpmar_qa_mail'] );
				}

				$enqueued = WPMAR_Job_Dispatcher::enqueue_audit_job(
					array(
						'dry'               => false,
						'triggered_by'      => 'manual',
						'persist_snapshots' => $persist,
						'mail_qa_extra'     => $qa_mail,
					),
					'single'
				);

				if ( is_wp_error( $enqueued ) ) {
					add_settings_error(
						'wpmar_messages',
						'wpmar_full',
						sprintf(
							/* translators: %s: reason the job could not be queued */
							__( 'レポート生成をキューに追加できませんでした: %s WP-CLI（wp wpmar audit run --sync）での実行をご検討ください。', 'wp-maintenance-audit-reporter' ),
							$enqueued->get_error_message()
						),
						'error'
					);
					break;
				}

				self::$queued_job_id = $enqueued;
				add_settings_error(
					'wpmar_messages',
					'wpmar_full',
					__( 'レポート生成をキューに追加しました。バックグラウンドで実行され、完了するとこの画面にダウンロードリンクが表示されます。', 'wp-maintenance-audit-reporter' ),
					'success'
				);
				break;
			default:
				break;
		}
	}
}
