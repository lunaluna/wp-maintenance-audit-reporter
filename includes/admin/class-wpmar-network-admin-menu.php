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
			array_merge(
				array(
					'dryRun'  => __( 'ネットワークドライランを実行しています…', 'wp-maintenance-audit-reporter' ),
					'fullRun' => __( 'ネットワーク実行をキューに追加しています…', 'wp-maintenance-audit-reporter' ),
				),
				WPMAR_Admin_Menu::polling_l10n()
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

		// Set when full_run / dry_run enqueues an Action Scheduler job; forwarded via redirect
		// so the next render can show the polling panel.
		$queued_job_id   = '';
		$queued_job_mode = 'full';

		switch ( $action ) {
			case 'dry_run':
				$scope = self::read_run_scope( $input, $scope_error );
				if ( '' !== $scope_error ) {
					add_settings_error( 'wpmar_network_messages', 'wpmar_network_dry', $scope_error, 'error' );
					break;
				}

				$dry_options = array(
					'dry'            => true,
					'triggered_by'   => 'manual_network',
					'same_setting'   => $scope['same_setting'],
					'target_blog_id' => $scope['target_blog_id'],
				);

				$enqueued_dry = WPMAR_Job_Dispatcher::enqueue_audit_job( $dry_options, 'network' );

				if ( ! is_wp_error( $enqueued_dry ) ) {
					$queued_job_id   = $enqueued_dry;
					$queued_job_mode = 'dry';
					break;
				}

				// Fallback (Action Scheduler unavailable): run synchronously and stash the
				// inline preview for this same render.
				$runner = new WPMAR_Network_Runner();
				$result = $runner->run( $dry_options );
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
				$scope = self::read_run_scope( $input, $scope_error );
				if ( '' !== $scope_error ) {
					add_settings_error( 'wpmar_network_messages', 'wpmar_network_full', $scope_error, 'error' );
					break;
				}
				$persist = ! empty( $input['wpmar_persist_snapshots'] );
				$qa_mail = '';
				if ( isset( $input['wpmar_qa_mail'] ) ) {
					$qa_mail = sanitize_email( $input['wpmar_qa_mail'] );
				}
				$run_options = array(
					'dry'               => false,
					'triggered_by'      => 'manual_network',
					'persist_snapshots' => $persist,
					'mail_qa_extra'     => $qa_mail,
					'same_setting'      => $scope['same_setting'],
					'target_blog_id'    => $scope['target_blog_id'],
				);

				$enqueued = WPMAR_Job_Dispatcher::enqueue_audit_job( $run_options, 'network' );

				if ( ! is_wp_error( $enqueued ) ) {
					// Action Scheduler queue: tracked job + polling panel (with its own
					// queued/completed flash notice) on the next render via wpmar_job.
					$queued_job_id = $enqueued;
				} elseif ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
					// Fallback path (Action Scheduler unavailable) needs WP-Cron; refuse when disabled.
					add_settings_error(
						'wpmar_network_messages',
						'wpmar_network_full',
						__( 'WP-Cron が無効（DISABLE_WP_CRON）のため、管理画面からの実行はできません。WP-CLI（wp maintenance-audit run --network）を使用するか、各サイトの設定画面から個別に実行してください。', 'wp-maintenance-audit-reporter' ),
						'error'
					);
				} else {
					// Legacy single-event fallback while Action Scheduler is not yet shipped.
					wp_schedule_single_event( time(), WPMAR_HOOK_NETWORK_MANUAL_RUN, array( $run_options ) );
					spawn_cron();
					add_settings_error(
						'wpmar_network_messages',
						'wpmar_network_full',
						__( 'ネットワーク実行をキューに追加しました。バックグラウンドで処理が開始されます。完了後はステータスの「直近の完了時刻」が更新されます。', 'wp-maintenance-audit-reporter' ),
						'success'
					);
				}
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

		$redirect_args = array(
			'page'              => WPMAR_NETWORK_ADMIN_PAGE_SLUG,
			'wpmar_network_msg' => '1',
		);
		if ( '' !== $queued_job_id ) {
			$redirect_args['wpmar_job']      = WPMAR_Jobs_Repository::sanitize_id( $queued_job_id );
			$redirect_args['wpmar_job_mode'] = ( 'dry' === $queued_job_mode ) ? 'dry' : 'full';
		}

		wp_safe_redirect(
			add_query_arg(
				$redirect_args,
				network_admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Reads and validates the run-scope POST fields, mapping them to runner option keys.
	 *
	 * @param array  $input       wp_unslash()'d $_POST.
	 * @param string $scope_error Output: non-empty string when validation fails.
	 * @return array{ same_setting: bool, target_blog_id: int }
	 */
	private static function read_run_scope( array $input, &$scope_error ) {
		$scope_error    = '';
		$scope          = isset( $input['wpmar_run_scope'] ) ? sanitize_key( $input['wpmar_run_scope'] ) : 'all';
		$same_setting   = false;
		$target_blog_id = 0;

		if ( 'same_setting' === $scope ) {
			$same_setting = true;
		} elseif ( 'target_blog_id' === $scope ) {
			$target_blog_id = isset( $input['wpmar_target_blog_id'] ) ? absint( $input['wpmar_target_blog_id'] ) : 0;
			if ( $target_blog_id <= 0 ) {
				$scope_error = __( '「特定のサイトのみ」を選択した場合は blog ID を入力してください。', 'wp-maintenance-audit-reporter' );
			} elseif ( ! get_blog_details( $target_blog_id ) ) {
				$scope_error = sprintf(
					/* translators: %d: blog ID */
					__( 'blog ID %d はこのネットワークに存在しません。', 'wp-maintenance-audit-reporter' ),
					$target_blog_id
				);
			}
		}

		return array(
			'same_setting'   => $same_setting,
			'target_blog_id' => $target_blog_id,
		);
	}
}
