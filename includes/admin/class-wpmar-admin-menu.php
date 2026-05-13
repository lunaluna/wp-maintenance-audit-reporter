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
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_init', array( 'WPMAR_Reports_Page', 'handle_get_actions' ), 1 );
		add_action( 'admin_init', array( 'WPMAR_Reports_Page', 'strip_legacy_notice_query_arg' ), 2 );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
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
	 * Loads scripts/styles on Maintenance Audit screens only.
	 *
	 * @param string $hook_suffix Passed by admin_enqueue_scripts.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		$main_hook    = WPMAR_ADMIN_PAGE_SLUG . '_page_' . WPMAR_ADMIN_PAGE_SLUG;
		$reports_hook = WPMAR_ADMIN_PAGE_SLUG . '_page_' . WPMAR_REPORTS_PAGE_SLUG;
		$allowed      = array(
			'toplevel_page_' . WPMAR_ADMIN_PAGE_SLUG,
			$main_hook,
			$reports_hook,
		);

		if ( ! in_array( $hook_suffix, $allowed, true ) ) {
			return;
		}

		$ver = defined( 'WPMAR_VERSION' ) ? WPMAR_VERSION : '0';

		wp_enqueue_style(
			'wpmar-admin',
			WPMAR_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			$ver
		);

		wp_enqueue_script(
			'wpmar-admin',
			WPMAR_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			$ver,
			true
		);

		$wpmar_admin_l10n = array(
			'dryRun'   => __( 'ドライランを実行しています…', 'wp-maintenance-audit-reporter' ),
			'fullRun'  => __( 'フル監査を実行しています…', 'wp-maintenance-audit-reporter' ),
			'testMail' => __( 'テストメール付き監査を実行しています…', 'wp-maintenance-audit-reporter' ),
		);

		if ( $reports_hook === $hook_suffix ) {
			$wpmar_admin_l10n['confirmSingle'] = __( 'このレポートを削除しますか？元に戻せません。', 'wp-maintenance-audit-reporter' );
			/* translators: %d: number of reports selected for bulk delete. */
			$wpmar_admin_l10n['confirmBulk'] = __( '選択した %d 件のレポートを削除しますか？元に戻せません。', 'wp-maintenance-audit-reporter' );
		}

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
		if ( ! isset( $_POST['wpmar_admin_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below.
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		check_admin_referer( 'wpmar_settings_save', 'wpmar_settings_nonce' );

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
				// Full audit on demand (same pathway as Cron once domain gate passes).
				$runner = new WPMAR_Runner();
				$runner->run(
					array(
						'dry'          => false,
						'triggered_by' => 'manual',
					)
				);
				add_settings_error(
					'wpmar_messages',
					'wpmar_full',
					__( 'フル監査をキューイングして完了しました。', 'wp-maintenance-audit-reporter' ),
					'success'
				);
				break;
			case 'test_mail':
				// Optional single-address override forwarded to notifier inside the runner.
				$override = '';
				if ( isset( $input['wpmar_qa_mail'] ) ) {
					$override = sanitize_email( $input['wpmar_qa_mail'] );
				}
				$runner = new WPMAR_Runner();
				$runner->run(
					array(
						'dry'           => false,
						'triggered_by'  => 'manual_test',
						'mail_override' => $override,
					)
				);
				add_settings_error(
					'wpmar_messages',
					'wpmar_mail_test',
					__( 'テストメール用の監査を実行しました。', 'wp-maintenance-audit-reporter' ),
					'success'
				);
				break;
			default:
				break;
		}
	}
}
