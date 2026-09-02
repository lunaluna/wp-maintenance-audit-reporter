<?php
/**
 * Network-level "システム機能" screen: wp.org cache size, both run locks, and any
 * currently in-flight network run's per-site segment status.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the network diagnostics/recovery screen and its POST actions.
 */
class WPMAR_Network_System_Status_Page {

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( WPMAR_Network_Admin_Menu::CAPABILITY ) ) {
			return;
		}

		if ( isset( $_GET['wpmar_network_msg'] ) && '1' === sanitize_key( wp_unslash( $_GET['wpmar_network_msg'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash from redirect; value restricted to '1'.
			settings_errors( 'wpmar_network_messages' );
		}

		$cache_count = WPMAR_System_Status_Page::wporg_cache_count();
		$lock        = self::network_run_lock_status();
		$active_runs = self::active_network_runs();
		// Posts through the same network_admin_edit_wpmar_network_settings flow the
		// settings screen's own form uses (WPMAR_Network_Admin_Menu::handle_post() is only
		// ever invoked via that action) - admin.php cannot receive this page's POSTs directly.
		$action_url = network_admin_url( 'edit.php?action=wpmar_network_settings' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'システム機能（ネットワーク）', 'wp-maintenance-audit-reporter' ); ?></h1>
			<hr class="wp-header-end" />
			<p class="description">
				<?php esc_html_e( 'ネットワーク集約監査の実行ロック・進行中セグメントの状態を確認し、スタックした実行を手動で復旧できます。wp.org キャッシュはサイト単位画面と共有です。', 'wp-maintenance-audit-reporter' ); ?>
			</p>

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
				<?php wp_nonce_field( 'wpmar_network_settings_save', 'wpmar_network_settings_nonce' ); ?>
				<input type="hidden" name="wpmar_return_page" value="<?php echo esc_attr( WPMAR_NETWORK_SYSTEM_STATUS_PAGE_SLUG ); ?>" />
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

			<h2><?php esc_html_e( '実行ロック（ネットワーク・多重実行防止）', 'wp-maintenance-audit-reporter' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'ネットワーク集約監査が同時に重複しないようにするための仕組みです。実行が完了すれば自動的に解除されます。実行が異常終了してロックが残ったままになっている場合のみ、下のボタンで強制解除してください。', 'wp-maintenance-audit-reporter' ); ?>
			</p>
			<?php if ( $lock['active'] ) : ?>
				<p>
					<?php
					printf(
						/* translators: %d: seconds remaining before the lock auto-expires */
						esc_html__( 'ロック中です（残り約 %d 秒で自動的に解除されます）。集約監査が正常に進行中の場合は解除しないでください。', 'wp-maintenance-audit-reporter' ),
						absint( $lock['remaining_sec'] )
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( $action_url ); ?>">
					<?php wp_nonce_field( 'wpmar_network_settings_save', 'wpmar_network_settings_nonce' ); ?>
					<input type="hidden" name="wpmar_return_page" value="<?php echo esc_attr( WPMAR_NETWORK_SYSTEM_STATUS_PAGE_SLUG ); ?>" />
					<button
						class="button"
						name="wpmar_admin_action"
						type="submit"
						value="force_unlock_network_run"
						onclick="return confirm('<?php echo esc_js( __( 'ネットワークの実行ロックを強制解除しますか?現在バックグラウンドで実行中の場合、その処理と競合する可能性があります。', 'wp-maintenance-audit-reporter' ) ); ?>');"
					>
						<?php esc_html_e( '強制解除', 'wp-maintenance-audit-reporter' ); ?>
					</button>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'ロックされていません。', 'wp-maintenance-audit-reporter' ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( '進行中のネットワーク実行', 'wp-maintenance-audit-reporter' ); ?></h2>
			<?php if ( empty( $active_runs ) ) : ?>
				<p><?php esc_html_e( '現在、進行中のサイト単位ジョブはありません（完了した実行のセグメント行は集約完了時に削除されます）。', 'wp-maintenance-audit-reporter' ); ?></p>
			<?php else : ?>
				<?php foreach ( $active_runs as $run ) : ?>
					<h3><code><?php echo esc_html( $run['run_id'] ); ?></code></h3>
					<table class="widefat striped" style="max-width:600px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'queued', 'wp-maintenance-audit-reporter' ); ?></th>
								<th><?php esc_html_e( 'running', 'wp-maintenance-audit-reporter' ); ?></th>
								<th><?php esc_html_e( 'done', 'wp-maintenance-audit-reporter' ); ?></th>
								<th><?php esc_html_e( 'failed', 'wp-maintenance-audit-reporter' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><?php echo esc_html( (string) $run['counts']['queued'] ); ?></td>
								<td><?php echo esc_html( (string) $run['counts']['running'] ); ?></td>
								<td><?php echo esc_html( (string) $run['counts']['done'] ); ?></td>
								<td><?php echo esc_html( (string) $run['counts']['failed'] ); ?></td>
							</tr>
						</tbody>
					</table>

					<?php if ( ! empty( $run['failed'] ) ) : ?>
						<table class="widefat striped" style="max-width:900px;">
							<thead>
								<tr>
									<th><?php esc_html_e( 'blog ID', 'wp-maintenance-audit-reporter' ); ?></th>
									<th><?php esc_html_e( 'サイト名', 'wp-maintenance-audit-reporter' ); ?></th>
									<th><?php esc_html_e( '試行回数', 'wp-maintenance-audit-reporter' ); ?></th>
									<th><?php esc_html_e( 'エラー', 'wp-maintenance-audit-reporter' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $run['failed'] as $segment ) : ?>
									<tr>
										<td><?php echo esc_html( (string) absint( $segment['blog_id'] ?? 0 ) ); ?></td>
										<td><?php echo esc_html( (string) ( $segment['site_name'] ?? '' ) ); ?></td>
										<td><?php echo esc_html( (string) absint( $segment['attempts'] ?? 0 ) ); ?></td>
										<td><?php echo esc_html( (string) ( $segment['error'] ?? '' ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				<?php endforeach; ?>
			<?php endif; ?>

			<h2><?php esc_html_e( 'セグメント処理履歴（所要時間・メモリ使用量）', 'wp-maintenance-audit-reporter' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'サイト単位ジョブが完了・失敗した際の所要時間とメモリ使用量の記録です。実行中のセグメント行は集約完了時に削除されますが、このログは削除されずに残るため、リトライ・タイムアウトの各フィルタ値を実測データに基づいて調整する際の参考になります。新しい行ほど上に表示されます。', 'wp-maintenance-audit-reporter' ); ?>
			</p>
			<?php $segment_history = self::segment_history_tail(); ?>
			<?php if ( '' === $segment_history ) : ?>
				<p><?php esc_html_e( '記録はまだありません。', 'wp-maintenance-audit-reporter' ); ?></p>
			<?php else : ?>
				<pre style="white-space:pre-wrap;background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:480px;overflow:auto;"><?php echo esc_html( $segment_history ); ?></pre>
			<?php endif; ?>

			<?php self::render_snapshot_preview_section(); ?>
		</div>
		<?php
	}

	/**
	 * Prints the cross-site snapshot preview: a site picker, then (once a site is
	 * selected) that one site's Markdown preview via
	 * {@see WPMAR_Snapshot_Preview::markdown_for_repository()}.
	 *
	 * Only WPMAR_Network::target_blog_ids() - the network settings' own allow-list
	 * (archived/spam/deleted exclusions, exclude_blog_ids, max_sites) - is ever
	 * offered in the <select> or accepted from the URL; an arbitrary blog id is
	 * rejected by sanitize_selected_blog_id(). Exactly one blog is read per
	 * request, never all of them (20 sites x 4 types would mean 80 tables read
	 * for a single page load).
	 *
	 * @return void
	 */
	public static function render_snapshot_preview_section() {
		$allowed = WPMAR_Network::target_blog_ids();
		?>
		<h2><?php esc_html_e( 'スナップショット（差分比較の基準データ）', 'wp-maintenance-audit-reporter' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'サイトを選ぶと、そのサイトに保存されている差分比較の基準データ（コア・テーマ・プラグイン・ユーザーの直近2世代）を表示します。監査レポート本文の控えではありません。', 'wp-maintenance-audit-reporter' ); ?>
		</p>

		<?php if ( empty( $allowed ) ) : ?>
			<p><?php esc_html_e( '対象サイトがありません。', 'wp-maintenance-audit-reporter' ); ?></p>
			<?php
			return;
		endif;
		?>

		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selector, no state change; validated below against the allow-list regardless.
		$requested   = isset( $_GET['wpmar_snapshot_blog'] ) ? sanitize_text_field( wp_unslash( $_GET['wpmar_snapshot_blog'] ) ) : '';
		$selected_id = self::sanitize_selected_blog_id( $requested, $allowed );
		?>

		<form method="get" action="<?php echo esc_url( network_admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( WPMAR_NETWORK_SYSTEM_STATUS_PAGE_SLUG ); ?>" />
			<select name="wpmar_snapshot_blog">
				<option value=""><?php esc_html_e( '選択してください', 'wp-maintenance-audit-reporter' ); ?></option>
				<?php foreach ( self::snapshot_site_choices( $allowed ) as $blog_id => $label ) : ?>
					<option value="<?php echo esc_attr( (string) $blog_id ); ?>" <?php selected( $selected_id, $blog_id ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button"><?php esc_html_e( '表示', 'wp-maintenance-audit-reporter' ); ?></button>
		</form>

		<?php if ( 0 === $selected_id ) : ?>
			<p><?php esc_html_e( '選択してください。', 'wp-maintenance-audit-reporter' ); ?></p>
			<?php
			return;
		endif;
		?>

		<pre style="white-space:pre-wrap;background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:480px;overflow:auto;"><?php echo esc_html( self::snapshot_markdown_for_blog( $selected_id ) ); ?></pre>
		<?php
	}

	/**
	 * Reads one already-permitted blog's snapshot rows and renders them to
	 * Markdown. Switches into the blog only for the duration of the closure
	 * (WPMAR_Network::on_blog() restores the previous blog in a finally block),
	 * and constructs WPMAR_Snapshot_Repository strictly inside that closure -
	 * its constructor fixes $wpdb->prefix once, so building it outside would keep
	 * reading whichever blog's tables were active before the switch.
	 *
	 * Unlike {@see self::active_network_runs()} above (which reads
	 * `wpmar_network_segments` via a raw, unwrapped `$wpdb->prefix` because it only
	 * ever targets the current request's own blog), this always operates on a
	 * *different*, admin-selected blog, so it must follow
	 * {@see self::segment_history_tail()}'s on_main_site()-wrapped pattern instead.
	 *
	 * @param int $blog_id Already validated against WPMAR_Network::target_blog_ids().
	 * @return string
	 */
	protected static function snapshot_markdown_for_blog( $blog_id ) {
		return WPMAR_Network::on_blog(
			$blog_id,
			static function () {
				global $wpdb;

				$repo    = new WPMAR_Snapshot_Repository();
				$context = array(
					'table'      => $wpdb->prefix . 'wpmar_snapshots',
					'site_label' => sprintf(
						'%1$s（blog_id %2$d / %3$s）',
						get_bloginfo( 'name' ),
						get_current_blog_id(),
						home_url( '/' )
					),
				);

				return WPMAR_Snapshot_Preview::markdown_for_repository( $repo, $context );
			}
		);
	}

	/**
	 * `blog_id => "blogname（blog_id N）"` labels for the <select>, without
	 * switching blogs (get_blog_details() reads the cached blog row directly).
	 * Falls back to a "Blog #N" placeholder for a blog whose name can't be
	 * resolved, matching {@see WPMAR_Runner::render_network_markup()}'s fallback.
	 *
	 * @param array<int,int> $allowed Permitted blog ids (WPMAR_Network::target_blog_ids()).
	 * @return array<int,string>
	 */
	protected static function snapshot_site_choices( array $allowed ) {
		$choices = array();

		foreach ( $allowed as $blog_id ) {
			$details = get_blog_details( $blog_id );
			$name    = ( $details && ! empty( $details->blogname ) ) ? (string) $details->blogname : sprintf( 'Blog #%d', $blog_id );

			$choices[ $blog_id ] = sprintf( '%1$s（blog_id %2$d）', $name, $blog_id );
		}

		return $choices;
	}

	/**
	 * Resolves the requested blog_id against the allow-list, or 0 when it is not
	 * permitted.
	 *
	 * Pure so the allow-list check can be unit-tested without get_sites()/
	 * switch_to_blog(): the caller passes WPMAR_Network::target_blog_ids() in,
	 * this decides. Deliberately does not use absint() - absint( -5 ) is 5, which
	 * would let a negative value slip through as if it were a different, and
	 * possibly permitted, blog id.
	 *
	 * @param mixed          $requested Raw `$_GET` value.
	 * @param array<int,int> $allowed   Permitted blog ids.
	 * @return int Permitted blog id, or 0.
	 */
	protected static function sanitize_selected_blog_id( $requested, array $allowed ) {
		if ( ! is_numeric( $requested ) ) {
			return 0;
		}

		$blog_id = (int) $requested;
		if ( $blog_id <= 0 ) {
			return 0;
		}

		return in_array( $blog_id, $allowed, true ) ? $blog_id : 0;
	}

	/**
	 * Handles this page's POST actions. Called from {@see WPMAR_Network_Admin_Menu::handle_post()}
	 * (shared nonce/capability gate) via the `wpmar_admin_action` switch.
	 *
	 * @param string $action Sanitized `wpmar_admin_action` value.
	 * @return void
	 */
	public static function handle_post_action( $action ) {
		switch ( $action ) {
			case 'clear_wporg_cache':
				$removed = WPMAR_System_Status_Page::clear_wporg_cache();
				add_settings_error(
					'wpmar_network_messages',
					'wpmar_wporg_cache_cleared',
					sprintf(
						/* translators: %d: number of rows removed */
						__( 'wp.org キャッシュを削除しました（%d 件）。', 'wp-maintenance-audit-reporter' ),
						absint( $removed )
					),
					'success'
				);
				break;
			case 'force_unlock_network_run':
				self::force_unlock_network_run();
				add_settings_error(
					'wpmar_network_messages',
					'wpmar_network_run_lock_cleared',
					__( 'ネットワークの実行ロックを解除しました。', 'wp-maintenance-audit-reporter' ),
					'success'
				);
				break;
		}
	}

	/**
	 * Network run-lock state (`wpmar_network_run_lock`, {@see WPMAR_Network_Runner::LOCK_TRANSIENT}
	 * - a site transient, network-wide rather than per-blog).
	 *
	 * @return array{active:bool,remaining_sec:int}
	 */
	public static function network_run_lock_status() {
		$active        = ( false !== get_site_transient( WPMAR_Network_Runner::LOCK_TRANSIENT ) );
		$expires_at    = (int) get_site_option( '_site_transient_timeout_' . WPMAR_Network_Runner::LOCK_TRANSIENT, 0 );
		$remaining_sec = ( $active && $expires_at > 0 ) ? max( 0, $expires_at - time() ) : 0;

		return array(
			'active'        => $active,
			'remaining_sec' => $remaining_sec,
		);
	}

	/**
	 * Clears the network run lock, for manually recovering a stuck network rollup.
	 *
	 * Does not touch `wpmar_network_segments` rows or the parent job row - if a run is
	 * genuinely stuck, {@see WPMAR_Jobs_Repository::sweep_stale_running()} /
	 * {@see WPMAR_Network_Segments_Repository::sweep_stale_running()} are what resolve
	 * those; this only frees up the lock so a *new* run can be dispatched.
	 *
	 * @return void
	 */
	public static function force_unlock_network_run() {
		delete_site_transient( WPMAR_Network_Runner::LOCK_TRANSIENT );
	}

	/**
	 * Every run_id currently holding rows in `wpmar_network_segments`, with status
	 * counts and the failed-segment detail rows.
	 *
	 * Only ever shows in-flight runs: {@see WPMAR_Job_Dispatcher::run_network_aggregate()}
	 * deletes a run's rows as soon as it finalizes, so a completed run leaves nothing
	 * here to look back on (see {@see WPMAR_Logger::log_segment_outcome()} for the
	 * persistent history that survives past that point).
	 *
	 * @return array<int,array{run_id:string,counts:array<string,int>,failed:array<int,array<string,mixed>>}>
	 */
	public static function active_network_runs() {
		global $wpdb;

		$table = $wpdb->prefix . 'wpmar_network_segments';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- diagnostics list, deliberately uncached (must reflect live segment state).
		$run_ids = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table name from prefix literal.
			"SELECT DISTINCT run_id FROM `{$table}` ORDER BY id DESC"
		);

		$repo = new WPMAR_Network_Segments_Repository();
		$runs = array();

		foreach ( (array) $run_ids as $run_id ) {
			$run_id = (string) $run_id;
			$rows   = $repo->find_by_run( $run_id );

			$runs[] = array(
				'run_id' => $run_id,
				'counts' => $repo->counts_by_status( $run_id ),
				'failed' => array_values(
					array_filter(
						$rows,
						static function ( $row ) {
							return isset( $row['status'] ) && 'failed' === (string) $row['status'];
						}
					)
				),
			);
		}

		return $runs;
	}

	/**
	 * Tail of the persistent per-segment duration/memory history
	 * ({@see WPMAR_Logger::log_segment_outcome()}). Always resolves the main site's copy,
	 * since every `mark_done()`/`mark_failed()` call that writes it runs from there (same
	 * rationale as {@see self::active_network_runs()}'s siblings on this page).
	 *
	 * Newest entry first (the file itself is append-only oldest-to-newest; this reverses
	 * just the returned, already-trimmed slice for display).
	 *
	 * @param int $max_lines Maximum trailing lines to return.
	 * @return string
	 */
	public static function segment_history_tail( $max_lines = 200 ) {
		return WPMAR_Network::on_main_site(
			static function () use ( $max_lines ) {
				$dir = WPMAR_Logger::logs_dir();
				if ( is_wp_error( $dir ) ) {
					return '';
				}

				$path = $dir . WPMAR_Logger::SEGMENT_HISTORY_FILE;
				if ( ! is_readable( $path ) ) {
					return '';
				}

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads our own small, slow-growing history file (see the class doc on SEGMENT_HISTORY_FILE); no HTTP request involved.
				$contents = file_get_contents( $path );
				if ( ! is_string( $contents ) || '' === trim( $contents ) ) {
					return '';
				}

				$lines = explode( "\n", trim( $contents ) );

				return implode( "\n", array_reverse( array_slice( $lines, -$max_lines ) ) );
			}
		);
	}
}
