<?php
/**
 * Network admin menu and POST handlers for rollup settings.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers network-level Maintenance Audit screens.
 */
class WPMAR_Network_Admin_Menu {

	const CAPABILITY = 'manage_network_options';

	/**
	 * Dry-run preview blob for the current request.
	 *
	 * @var string
	 */
	private static $dry_run_brevity_inline = '';

	/**
	 * Registers network admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! WPMAR_Network_Settings::is_multisite_available() ) {
			return;
		}

		add_action( 'network_admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'network_admin_edit_wpmar_network_settings', array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Returns and clears the dry-run brevity blob.
	 *
	 * @return string
	 */
	public static function consume_dry_run_brevity() {
		$blob                         = self::$dry_run_brevity_inline;
		self::$dry_run_brevity_inline = '';

		return is_string( $blob ) ? $blob : '';
	}

	/**
	 * Registers network admin menu page.
	 *
	 * @return void
	 */
	public static function register_page() {
		add_menu_page(
			__( 'Maintenance Audit (Network)', 'wp-maintenance-audit-reporter' ),
			__( 'Maintenance Audit', 'wp-maintenance-audit-reporter' ),
			self::CAPABILITY,
			WPMAR_NETWORK_ADMIN_PAGE_SLUG,
			array( 'WPMAR_Network_Settings_Page', 'render' ),
			'dashicons-clipboard',
			81
		);
	}

	/**
	 * Enqueues admin assets on the network settings screen.
	 *
	 * @param string $hook_suffix Hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( 'toplevel_page_' . WPMAR_NETWORK_ADMIN_PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'wpmar-admin',
			WPMAR_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			defined( 'WPMAR_VERSION' ) ? WPMAR_VERSION : '0'
		);

		wp_enqueue_script(
			'wpmar-admin',
			WPMAR_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			defined( 'WPMAR_VERSION' ) ? WPMAR_VERSION : '0',
			true
		);

		wp_localize_script(
			'wpmar-admin',
			'wpmarAdminBusy',
			array(
				'dryRun'  => __( 'ネットワークドライランを実行しています…', 'wp-maintenance-audit-reporter' ),
				'fullRun' => __( 'ネットワークレポート生成を実行しています…', 'wp-maintenance-audit-reporter' ),
			)
		);
	}

	/**
	 * Handles network settings saves and rollup runs.
	 *
	 * @return void
	 */
	public static function handle_post() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'wp-maintenance-audit-reporter' ) );
		}

		check_admin_referer( 'wpmar_network_settings_save', 'wpmar_network_settings_nonce' );

		$action = isset( $_POST['wpmar_admin_action'] ) ? sanitize_key( wp_unslash( $_POST['wpmar_admin_action'] ) ) : 'save'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_key.
		$input  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in merge.

		switch ( $action ) {
			case 'dry_run':
				$runner = new WPMAR_Network_Runner();
				$result = $runner->run(
					array(
						'dry'          => true,
						'triggered_by' => 'manual_network',
					)
				);
				if ( isset( $result['dry_brevity'] ) && is_string( $result['dry_brevity'] ) ) {
					self::$dry_run_brevity_inline = $result['dry_brevity'];
				}
				add_settings_error(
					'wpmar_network_messages',
					'wpmar_network_dry',
					__( 'ネットワークドライランを完了しました（保存・通知なし）。', 'wp-maintenance-audit-reporter' ),
					'success'
				);
				break;

			case 'full_run':
				$persist = ! empty( $input['wpmar_persist_snapshots'] );
				$qa_mail = '';
				if ( isset( $input['wpmar_qa_mail'] ) ) {
					$qa_mail = sanitize_email( $input['wpmar_qa_mail'] );
				}
				$runner = new WPMAR_Network_Runner();
				$runner->run(
					array(
						'dry'               => false,
						'triggered_by'      => 'manual_network',
						'persist_snapshots' => $persist,
						'mail_qa_extra'     => $qa_mail,
					)
				);
				add_settings_error(
					'wpmar_network_messages',
					'wpmar_network_full',
					__( 'ネットワークレポート生成を実行して完了しました。', 'wp-maintenance-audit-reporter' ),
					'success'
				);
				break;

			case 'save':
			default:
				$merged = WPMAR_Network_Settings::merge_form_input( $input, WPMAR_Network_Settings::get_all() );
				WPMAR_Network_Settings::update_all( $merged );
				WPMAR_Network::on_main_site(
					static function () {
						WPMAR_Scheduler::reschedule();
					}
				);
				// Clear orphaned cron hooks on subsites when rollup takes over scheduling.
				if ( ! empty( $merged['network_audit_enabled'] ) && function_exists( 'get_sites' ) ) {
					$main_id = WPMAR_Network::main_site_id();
					foreach ( get_sites( array( 'number' => 0 ) ) as $site ) {
						if ( ! is_object( $site ) || ! isset( $site->blog_id ) ) {
							continue;
						}
						$blog_id = (int) $site->blog_id;
						if ( $main_id === $blog_id ) {
							continue;
						}
						WPMAR_Network::on_blog(
							$blog_id,
							static function () {
								WPMAR_Scheduler::clear();
							}
						);
					}
				}
				add_settings_error(
					'wpmar_network_messages',
					'wpmar_network_saved',
					__( 'ネットワーク設定を保存し、次回の Cron を再計算しました。', 'wp-maintenance-audit-reporter' ),
					'success'
				);
				break;
		}

		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => WPMAR_NETWORK_ADMIN_PAGE_SLUG,
					'wpmar_network_msg' => '1',
				),
				network_admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
