<?php
/**
 * Registers Settings > Maintenance Audit UI and validates POST actions.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the options screen and nonce-protected submissions.
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
	 * Loads scripts/styles on the Maintenance Audit settings screen only.
	 *
	 * @param string $hook_suffix Passed by admin_enqueue_scripts.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( 'settings_page_' . WPMAR_ADMIN_PAGE_SLUG !== $hook_suffix ) {
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

		wp_localize_script(
			'wpmar-admin',
			'wpmarAdminBusy',
			array(
				'dryRun'   => __( 'ドライランを実行しています…', 'wp-maintenance-audit-reporter' ),
				'fullRun'  => __( 'フル監査を実行しています…', 'wp-maintenance-audit-reporter' ),
				'testMail' => __( 'テストメール付き監査を実行しています…', 'wp-maintenance-audit-reporter' ),
			)
		);
	}

	/**
	 * Adds options page under Settings.
	 *
	 * @return void
	 */
	public static function register_page() {
		add_options_page(
			__( 'Maintenance Audit', 'wp-maintenance-audit-reporter' ),
			__( 'Maintenance Audit', 'wp-maintenance-audit-reporter' ),
			self::CAPABILITY,
			WPMAR_ADMIN_PAGE_SLUG,
			array( 'WPMAR_Settings_Page', 'render' )
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
