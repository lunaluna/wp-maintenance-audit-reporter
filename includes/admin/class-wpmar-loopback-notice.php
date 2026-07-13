<?php
/**
 * Warns operators on the plugin's admin screens when loopback requests are blocked.
 *
 * Basic auth (or similar server-level access control) silently disables the
 * WP-Cron / Action Scheduler loopback the monthly schedule depends on. This
 * notice explains the constraint, what still works (manual runs via the
 * polling fallback, WP-CLI), and offers a re-check button that discards the
 * detector's cached verdict.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the loopback-blocked admin notice and handles the re-check action.
 */
class WPMAR_Loopback_Notice {

	/**
	 * Action name for the admin-post.php re-check handler.
	 */
	const RECHECK_ACTION = 'wpmar_loopback_recheck';

	/**
	 * Wires notice rendering and the re-check POST handler.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'render_network_notice' ) );
		add_action( 'admin_post_' . self::RECHECK_ACTION, array( __CLASS__, 'handle_recheck' ) );
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

		self::maybe_output_notice();
	}

	/**
	 * Shows the warning on the plugin's network admin screen.
	 *
	 * @return void
	 */
	public static function render_network_notice() {
		if ( ! current_user_can( WPMAR_Network_Admin_Menu::CAPABILITY ) ) {
			return;
		}

		if ( ! self::is_plugin_screen( self::network_screen_ids() ) ) {
			return;
		}

		self::maybe_output_notice();
	}

	/**
	 * Discards the cached verdict, re-probes, and returns to the previous screen.
	 *
	 * @return void
	 */
	public static function handle_recheck() {
		if ( ! current_user_can( WPMAR_Admin_Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'wp-maintenance-audit-reporter' ) );
		}

		check_admin_referer( self::RECHECK_ACTION );

		$detector = new WPMAR_Loopback_Detector();
		$detector->flush_cache();
		$detector->run_check();

		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url() );
		exit;
	}

	/**
	 * Prints the warning when the (cached) probe says loopback is blocked.
	 *
	 * @return void
	 */
	protected static function maybe_output_notice() {
		$detector = new WPMAR_Loopback_Detector();
		if ( $detector->is_loopback_available() ) {
			return;
		}
		?>
		<div class="notice notice-warning wpmar-loopback-notice">
			<p>
				<strong><?php esc_html_e( 'このサイトはループバックリクエストがブロックされています（Basic 認証などのアクセス制限が原因の可能性があります）。', 'wp-maintenance-audit-reporter' ); ?></strong>
			</p>
			<ul class="wpmar-loopback-notice__list">
				<li><?php esc_html_e( 'レポートの月次自動生成は動作しません。', 'wp-maintenance-audit-reporter' ); ?></li>
				<li><?php esc_html_e( 'レポートの手動生成は利用できます（処理はステータス確認中に段階的に進行します）。', 'wp-maintenance-audit-reporter' ); ?></li>
				<li>
					<?php esc_html_e( 'サーバーの cron から WP-CLI で確実に実行することもできます:', 'wp-maintenance-audit-reporter' ); ?>
					<code>wp wpmar audit run --sync</code>
				</li>
			</ul>
			<form method="post" action="<?php echo esc_url( self_admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::RECHECK_ACTION ); ?>" />
				<?php wp_nonce_field( self::RECHECK_ACTION ); ?>
				<p>
					<button type="submit" class="button button-secondary">
						<?php esc_html_e( '再チェック', 'wp-maintenance-audit-reporter' ); ?>
					</button>
				</p>
			</form>
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
	 * Mirrors {@see WPMAR_Admin_Menu::enqueue_assets()}: for pages registered
	 * through add_menu_page()/add_submenu_page() the screen id equals the page
	 * hookname.
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

	/**
	 * Screen ids of the plugin's network admin page.
	 *
	 * In the network admin, WordPress appends `-network` to the hookname when
	 * building the screen id, so both variants are listed.
	 *
	 * @return array<int,string>
	 */
	protected static function network_screen_ids() {
		if ( ! function_exists( 'get_plugin_page_hookname' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$hookname = (string) get_plugin_page_hookname( WPMAR_NETWORK_ADMIN_PAGE_SLUG, '' );
		if ( '' === $hookname ) {
			return array();
		}

		return array( $hookname, $hookname . '-network' );
	}
}
