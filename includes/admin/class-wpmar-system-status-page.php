<?php
/**
 * Site-level "システム状態" screen: wp.org cache size, run-lock state, manual recovery actions.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the diagnostics/recovery screen and its two POST actions.
 */
class WPMAR_System_Status_Page {

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( WPMAR_Admin_Menu::CAPABILITY ) ) {
			return;
		}

		settings_errors( 'wpmar_messages' );

		$cache_count = self::wporg_cache_count();
		$lock        = self::run_lock_status();
		$action_url  = WPMAR_Admin_Menu::admin_screen_url( WPMAR_SYSTEM_STATUS_PAGE_SLUG );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'システム機能', 'wp-maintenance-audit-reporter' ); ?></h1>
			<hr class="wp-header-end" />
			<p class="description">
				<?php esc_html_e( 'wp.org キャッシュや実行ロックの状態を確認し、スタックした定期実行を手動で復旧できます。', 'wp-maintenance-audit-reporter' ); ?>
			</p>

			<?php WPMAR_Log_Viewer::render_section(); ?>

			<hr />

			<h2><?php esc_html_e( 'wp.org メタデータキャッシュ', 'wp-maintenance-audit-reporter' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %d: cached wp.org entry count */
					esc_html__( '現在 %d 件のプラグイン/テーマ情報をキャッシュしています。', 'wp-maintenance-audit-reporter' ),
					absint( $cache_count )
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>">
				<?php wp_nonce_field( 'wpmar_settings_save', 'wpmar_settings_nonce' ); ?>
				<button
					class="button"
					name="wpmar_admin_action"
					type="submit"
					value="clear_wporg_cache"
					onclick="return confirm('<?php echo esc_js( __( 'wp.org キャッシュをすべて削除しますか?次回の監査でキャッシュが再構築されます。', 'wp-maintenance-audit-reporter' ) ); ?>');"
				>
					<?php esc_html_e( 'キャッシュをクリア', 'wp-maintenance-audit-reporter' ); ?>
				</button>
			</form>

			<h2><?php esc_html_e( '実行ロック（多重実行防止）', 'wp-maintenance-audit-reporter' ); ?></h2>
			<p class="description">
				<?php esc_html_e( '定期実行や手動実行が同時に重複しないようにするための仕組みです。実行が完了すれば自動的に解除されます。実行が異常終了してロックが残ったままになっている場合のみ、下のボタンで強制解除してください。', 'wp-maintenance-audit-reporter' ); ?>
			</p>
			<?php if ( $lock['active'] ) : ?>
				<p>
					<?php
					printf(
						/* translators: %d: seconds remaining before the lock auto-expires */
						esc_html__( 'ロック中です（残り約 %d 秒で自動的に解除されます）。定期実行が正常に進行中の場合は解除しないでください。', 'wp-maintenance-audit-reporter' ),
						absint( $lock['remaining_sec'] )
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( $action_url ); ?>">
					<?php wp_nonce_field( 'wpmar_settings_save', 'wpmar_settings_nonce' ); ?>
					<button
						class="button"
						name="wpmar_admin_action"
						type="submit"
						value="force_unlock_run"
						onclick="return confirm('<?php echo esc_js( __( '実行ロックを強制解除しますか?現在バックグラウンドで実行中の場合、その処理と競合する可能性があります。', 'wp-maintenance-audit-reporter' ) ); ?>');"
					>
						<?php esc_html_e( '強制解除', 'wp-maintenance-audit-reporter' ); ?>
					</button>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'ロックされていません。', 'wp-maintenance-audit-reporter' ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( '実行履歴（所要時間・メモリ使用量）', 'wp-maintenance-audit-reporter' ); ?></h2>
			<p class="description">
				<?php esc_html_e( '監査実行が完了した際の所要時間とピークメモリ使用量、その時点の memory_limit の記録です。このプラグイン単体の負荷だけでなく、サイト全体でメモリにどれだけ余裕があるかを見る参考にもなります。新しい行ほど下に追加されます。', 'wp-maintenance-audit-reporter' ); ?>
			</p>
			<?php $run_history = self::run_history_tail(); ?>
			<?php if ( '' === $run_history ) : ?>
				<p><?php esc_html_e( '記録はまだありません。', 'wp-maintenance-audit-reporter' ); ?></p>
			<?php else : ?>
				<pre style="white-space:pre-wrap;background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:480px;overflow:auto;"><?php echo esc_html( $run_history ); ?></pre>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handles this page's POST actions. Called from {@see WPMAR_Admin_Menu::handle_post()}
	 * (shared nonce/capability gate) via the `wpmar_admin_action` switch.
	 *
	 * @param string $action Sanitized `wpmar_admin_action` value.
	 * @return void
	 */
	public static function handle_post_action( $action ) {
		switch ( $action ) {
			case 'clear_wporg_cache':
				$removed = self::clear_wporg_cache();
				add_settings_error(
					'wpmar_messages',
					'wpmar_wporg_cache_cleared',
					sprintf(
						/* translators: %d: number of rows removed */
						__( 'wp.org キャッシュを削除しました（%d 件）。', 'wp-maintenance-audit-reporter' ),
						absint( $removed )
					),
					'success'
				);
				break;
			case 'force_unlock_run':
				self::force_unlock_run();
				add_settings_error(
					'wpmar_messages',
					'wpmar_run_lock_cleared',
					__( '実行ロックを解除しました。', 'wp-maintenance-audit-reporter' ),
					'success'
				);
				break;
		}
	}

	/**
	 * Counts cached wp.org plugin/theme metadata entries (site_transient values only, not
	 * their paired `_timeout_` rows).
	 *
	 * A site transient lives in `$wpdb->options` on a single-site install, or
	 * `$wpdb->sitemeta` on multisite (see {@see WPMAR_Network_System_Status_Page}, which
	 * calls this same method - the cache is shared network-wide).
	 *
	 * @return int
	 */
	public static function wporg_cache_count() {
		global $wpdb;

		list( $table, $column ) = self::wporg_cache_table_and_column();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- diagnostics count, no caching layer for this ad-hoc LIKE query.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table/column names, not user input.
				"SELECT COUNT(*) FROM {$table} WHERE {$column} LIKE %s",
				$wpdb->esc_like( '_site_transient_wpmar_wporg_' ) . '%'
			)
		);
	}

	/**
	 * Deletes every cached wp.org plugin/theme metadata entry (value + paired timeout rows).
	 *
	 * @return int Number of rows removed.
	 */
	public static function clear_wporg_cache() {
		global $wpdb;

		list( $table, $column ) = self::wporg_cache_table_and_column();

		$removed = 0;
		foreach ( array( '_site_transient_wpmar_wporg_', '_site_transient_timeout_wpmar_wporg_' ) as $prefix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- admin-triggered bulk delete of our own cache keys.
			$result = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table/column names, not user input.
					"DELETE FROM {$table} WHERE {$column} LIKE %s",
					$wpdb->esc_like( $prefix ) . '%'
				)
			);
			$removed += is_numeric( $result ) ? (int) $result : 0;
		}

		return $removed;
	}

	/**
	 * Resolves which table/column a site transient's raw rows live in, matching
	 * `set_site_transient()`'s own storage choice (never assume - {@see WPMAR_Jobs_Repository}
	 * and friends read this from `is_multisite()`, not from guessing at a fixed table).
	 *
	 * @return array{0:string,1:string}
	 */
	protected static function wporg_cache_table_and_column() {
		global $wpdb;

		return is_multisite() ? array( $wpdb->sitemeta, 'meta_key' ) : array( $wpdb->options, 'option_name' );
	}

	/**
	 * Current-blog run-lock state (`wpmar_run_lock`, a regular per-blog transient).
	 *
	 * @return array{active:bool,remaining_sec:int}
	 */
	public static function run_lock_status() {
		$active        = ( false !== get_transient( 'wpmar_run_lock' ) );
		$expires_at    = (int) get_option( '_transient_timeout_wpmar_run_lock', 0 );
		$remaining_sec = ( $active && $expires_at > 0 ) ? max( 0, $expires_at - time() ) : 0;

		return array(
			'active'        => $active,
			'remaining_sec' => $remaining_sec,
		);
	}

	/**
	 * Clears the current blog's run lock, for manually recovering a stuck single-site run.
	 *
	 * @return void
	 */
	public static function force_unlock_run() {
		delete_transient( 'wpmar_run_lock' );
	}

	/**
	 * Tail of the persistent single-site run peak-memory/duration history
	 * ({@see WPMAR_Logger::log_run_outcome()}).
	 *
	 * @param int $max_lines Maximum trailing lines to return.
	 * @return string
	 */
	public static function run_history_tail( $max_lines = 200 ) {
		$dir = WPMAR_Logger::logs_dir();
		if ( is_wp_error( $dir ) ) {
			return '';
		}

		$path = $dir . WPMAR_Logger::RUN_HISTORY_FILE;
		if ( ! is_readable( $path ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads our own small, slow-growing history file; no HTTP request involved.
		$contents = file_get_contents( $path );
		if ( ! is_string( $contents ) || '' === trim( $contents ) ) {
			return '';
		}

		$lines = explode( "\n", trim( $contents ) );

		return implode( "\n", array_slice( $lines, -$max_lines ) );
	}
}
