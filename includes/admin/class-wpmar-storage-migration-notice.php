<?php
/**
 * Shows storage-migration progress/failure on the plugin's admin screens.
 *
 * @see WPMAR_Storage_Migrator
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the migration progress/failure notice and handles the retry action.
 */
class WPMAR_Storage_Migration_Notice {

	/**
	 * Action name for the admin-post.php retry handler.
	 */
	const RETRY_ACTION = 'wpmar_storage_migration_retry';

	/**
	 * Wires notice rendering and the retry POST handler.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
		add_action( 'admin_post_' . self::RETRY_ACTION, array( __CLASS__, 'handle_retry' ) );
	}

	/**
	 * Shows the notice on the plugin's own screens.
	 *
	 * @return void
	 */
	public static function render_notice() {
		if ( ! current_user_can( WPMAR_Admin_Menu::CAPABILITY ) ) {
			return;
		}

		if ( ! self::is_plugin_screen( self::plugin_screen_ids() ) ) {
			return;
		}

		$state = WPMAR_Storage_Migrator::get_state();

		if ( 'failed' === $state['state'] ) {
			self::render_failed_notice( $state );

			return;
		}

		if ( in_array( $state['state'], array( 'pending', 'running' ), true ) ) {
			self::render_progress_notice( $state );
		}
	}

	/**
	 * Prints the "in progress" notice.
	 *
	 * @param array<string,mixed> $state Current migration progress.
	 * @return void
	 */
	protected static function render_progress_notice( array $state ) {
		$total = max( 1, (int) $state['total_rows'] );
		$done  = min( $total, (int) $state['migrated'] + (int) $state['failed'] );
		?>
		<div class="notice notice-info wpmar-storage-migration-notice">
			<p>
				<?php
				printf(
					/* translators: 1: processed count, 2: total count */
					esc_html__( 'レポートの保存先を移行中です: %1$d / %2$d 件。処理はページアクセスのたびに少しずつ進みます。', 'wp-maintenance-audit-reporter' ),
					(int) $done,
					(int) $total
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Prints the "failed" notice with the last note and a retry button.
	 *
	 * @param array<string,mixed> $state Current migration progress.
	 * @return void
	 */
	protected static function render_failed_notice( array $state ) {
		$notes     = is_array( $state['notes'] ) ? $state['notes'] : array();
		$last_note = ! empty( $notes ) ? (string) end( $notes ) : '';
		?>
		<div class="notice notice-error wpmar-storage-migration-notice">
			<p>
				<strong><?php esc_html_e( 'レポート保存先の移行に失敗しました。', 'wp-maintenance-audit-reporter' ); ?></strong>
			</p>
			<?php if ( '' !== $last_note ) : ?>
				<p><code><?php echo esc_html( $last_note ); ?></code></p>
			<?php endif; ?>
			<p><?php esc_html_e( '旧ファイルは削除されていません。既存のレポート表示・ダウンロードは引き続き利用できます。', 'wp-maintenance-audit-reporter' ); ?></p>
			<form method="post" action="<?php echo esc_url( self_admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::RETRY_ACTION ); ?>" />
				<?php wp_nonce_field( self::RETRY_ACTION ); ?>
				<p>
					<button type="submit" class="button button-secondary">
						<?php esc_html_e( '移行を再実行', 'wp-maintenance-audit-reporter' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Resets a failed migration and advances one batch, then returns to the referring screen.
	 *
	 * @return void
	 */
	public static function handle_retry() {
		if ( ! current_user_can( WPMAR_Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'wp-maintenance-audit-reporter' ) );
		}

		check_admin_referer( self::RETRY_ACTION );

		WPMAR_Storage_Migrator::reset_failed();
		WPMAR_Storage_Migrator::run_batch( 'migrate' );

		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url() );
		exit;
	}

	/**
	 * Whether the current screen is one of the given plugin screens.
	 *
	 * @param array<int,string> $screen_ids Allowed screen ids.
	 * @return bool
	 */
	protected static function is_plugin_screen( array $screen_ids ) {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return $screen && in_array( (string) $screen->id, $screen_ids, true );
	}

	/**
	 * Screen ids of the plugin's single-site admin pages.
	 *
	 * Mirrors {@see WPMAR_Loopback_Notice::plugin_screen_ids()}.
	 *
	 * @return array<int,string>
	 */
	protected static function plugin_screen_ids() {
		if ( ! function_exists( 'get_plugin_page_hookname' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return array_values(
			array_unique(
				array_filter(
					array(
						get_plugin_page_hookname( WPMAR_ADMIN_PAGE_SLUG, '' ),
						get_plugin_page_hookname( WPMAR_ADMIN_PAGE_SLUG, WPMAR_ADMIN_PAGE_SLUG ),
						get_plugin_page_hookname( WPMAR_REPORTS_PAGE_SLUG, WPMAR_ADMIN_PAGE_SLUG ),
					)
				)
			)
		);
	}
}
