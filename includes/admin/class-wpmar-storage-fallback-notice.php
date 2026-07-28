<?php
/**
 * Warns operators on the plugin's admin screens when private storage falls back to uploads/.
 *
 * {@see WPMAR_Private_Storage} prefers a directory outside `wp_upload_dir()`
 * (by default `WP_CONTENT_DIR/wpmar-private/`) because uploads is routinely
 * exposed by CDNs, sync tools, and backup publishing. When that directory is
 * not writable, it falls back to a still-protected `uploads/wpmar/` directory
 * — this notice tells the operator so they can fix permissions or set
 * `WPMAR_PRIVATE_STORAGE_DIR` instead of relying on the fallback indefinitely.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the storage-fallback admin notice on the plugin's own screens.
 */
class WPMAR_Storage_Fallback_Notice {

	/**
	 * Wires notice rendering.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
	}

	/**
	 * Shows the warning on the plugin's own (single-site) screens.
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

		if ( ! WPMAR_Private_Storage::is_fallback_active() ) {
			return;
		}
		?>
		<div class="notice notice-warning wpmar-storage-fallback-notice">
			<p>
				<strong><?php esc_html_e( 'レポートの保存先が uploads にフォールバックしています。', 'wp-maintenance-audit-reporter' ); ?></strong>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: 1: private storage path, 2: constant name */
						__( '%1$s へ書き込めないため、レポート・PDF・診断ログの保存先を一時的に uploads 配下に切り替えています（トークン付きファイル名と保護ファイルによる保護は維持されます）。サーバー管理者にディレクトリの書き込み権限を確認いただくか、%2$s 定数でドキュメントルート外の保存先を指定することを推奨します。', 'wp-maintenance-audit-reporter' ),
						'<code>' . esc_html( WP_CONTENT_DIR . '/wpmar-private' ) . '</code>',
						'<code>WPMAR_PRIVATE_STORAGE_DIR</code>'
					)
				);
				?>
			</p>
		</div>
		<?php
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
