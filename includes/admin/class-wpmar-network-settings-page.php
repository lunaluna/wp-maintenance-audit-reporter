<?php
/**
 * Network admin settings + rollup controls.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the network Maintenance Audit screen.
 */
class WPMAR_Network_Settings_Page {

	/**
	 * Renders the network settings and rollup controls screen.
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

		$settings = WPMAR_Network_Settings::get_all();
		$dry_note = WPMAR_Network_Admin_Menu::consume_dry_run_brevity();
		$main_id  = WPMAR_Network::main_site_id();
		$reports  = get_admin_url( $main_id, 'admin.php?page=' . WPMAR_REPORTS_PAGE_SLUG );
		$next_ts  = wp_next_scheduled( WPMAR_HOOK_SCHEDULED );
		$tz_slug  = isset( $settings['schedule']['tz'] ) ? $settings['schedule']['tz'] : 'Asia/Tokyo';
		$tz_obj   = new DateTimeZone( 'Asia/Tokyo' );
		try {
			$tz_obj = new DateTimeZone( $tz_slug );
		} catch ( Exception $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- fallback above.
		}
		$next_lbl = __( '未スケジュール', 'wp-maintenance-audit-reporter' );
		if ( false !== $next_ts ) {
			$next_lbl = wp_date( 'Y-m-d H:i:s', $next_ts, $tz_obj );
		}
		$target_count = count( WPMAR_Network::target_blog_ids( $settings ) );
		?>
		<div class="wrap wpmar-maintenance-settings">
			<h1><?php esc_html_e( 'Maintenance Audit — ネットワーク', 'wp-maintenance-audit-reporter' ); ?></h1>
			<p><?php esc_html_e( 'すべての対象サイトを巡回し、クライアント向け・管理者向け各1本の集約レポートをメインサイトに保存します。', 'wp-maintenance-audit-reporter' ); ?></p>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( $reports ); ?>">
					<?php esc_html_e( 'メインサイトのレポート一覧を開く', 'wp-maintenance-audit-reporter' ); ?>
				</a>
			</p>

			<div class="wpmar-section-panel">
				<h2><?php esc_html_e( 'ステータス', 'wp-maintenance-audit-reporter' ); ?></h2>
				<ul class="wpmar-section-panel-body">
					<li><strong><?php esc_html_e( 'ネットワーク監査', 'wp-maintenance-audit-reporter' ); ?>:</strong> <?php echo ! empty( $settings['network_audit_enabled'] ) ? esc_html__( '有効', 'wp-maintenance-audit-reporter' ) : esc_html__( '無効', 'wp-maintenance-audit-reporter' ); ?></li>
					<li><strong><?php esc_html_e( '対象サイト数', 'wp-maintenance-audit-reporter' ); ?>:</strong> <?php echo esc_html( (string) $target_count ); ?></li>
					<li><strong><?php esc_html_e( '次回 WP-Cron（メインサイト）', 'wp-maintenance-audit-reporter' ); ?>:</strong> <?php echo esc_html( $next_lbl ); ?></li>
				</ul>
			</div>

			<?php if ( is_string( $dry_note ) && '' !== trim( $dry_note ) ) : ?>
				<div class="wpmar-section-panel">
					<h2><?php esc_html_e( 'ドライラン要約', 'wp-maintenance-audit-reporter' ); ?></h2>
					<pre class="wpmar-dry-run-summary"><?php echo esc_html( $dry_note ); ?></pre>
				</div>
			<?php endif; ?>

			<form id="wpmar-settings-form" class="wpmar-settings-form" method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=wpmar_network_settings' ) ); ?>">
				<?php wp_nonce_field( 'wpmar_network_settings_save', 'wpmar_network_settings_nonce' ); ?>

				<div class="wpmar-section-panel">
					<h2><?php esc_html_e( 'ネットワーク監査', 'wp-maintenance-audit-reporter' ); ?></h2>
					<p>
						<label>
							<input name="wpmar_network_audit_enabled" type="checkbox" value="1" <?php checked( ! empty( $settings['network_audit_enabled'] ) ); ?> />
							<?php esc_html_e( 'ネットワーク集約監査を有効化（メインサイトで全サイトを巡回）', 'wp-maintenance-audit-reporter' ); ?>
						</label>
					</p>
					<p class="description"><?php esc_html_e( '有効にすると WP-Cron と手動実行はメインサイトのみが担当し、子サイトの個別実行は抑止されます。', 'wp-maintenance-audit-reporter' ); ?></p>
				</div>

				<div class="wpmar-section-panel">
					<h2><?php esc_html_e( 'スケジュール', 'wp-maintenance-audit-reporter' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wpmar-schedule-day"><?php esc_html_e( '実行日 (1〜31)', 'wp-maintenance-audit-reporter' ); ?></label></th>
							<td><input name="wpmar_schedule_day" id="wpmar-schedule-day" type="number" min="1" max="31" value="<?php echo esc_attr( (string) ( $settings['schedule']['day'] ?? 25 ) ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( '時刻', 'wp-maintenance-audit-reporter' ); ?></th>
							<td>
								<label>
									<input name="wpmar_schedule_hour" id="wpmar-schedule-hour" type="number" min="0" max="23" value="<?php echo esc_attr( (string) ( $settings['schedule']['hour'] ?? 2 ) ); ?>" />
									<?php esc_html_e( '時', 'wp-maintenance-audit-reporter' ); ?>
								</label>
								<label>
									<input name="wpmar_schedule_minute" id="wpmar-schedule-minute" type="number" min="0" max="59" value="<?php echo esc_attr( (string) ( $settings['schedule']['minute'] ?? 0 ) ); ?>" />
									<?php esc_html_e( '分', 'wp-maintenance-audit-reporter' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wpmar-schedule-tz"><?php esc_html_e( 'タイムゾーン', 'wp-maintenance-audit-reporter' ); ?></label></th>
							<td><input class="regular-text" name="wpmar_schedule_tz" id="wpmar-schedule-tz" type="text" value="<?php echo esc_attr( (string) ( $settings['schedule']['tz'] ?? 'Asia/Tokyo' ) ); ?>" /></td>
						</tr>
					</table>
				</div>

				<div class="wpmar-section-panel">
					<h2><?php esc_html_e( '対象サイト', 'wp-maintenance-audit-reporter' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( '含めるサイト', 'wp-maintenance-audit-reporter' ); ?></th>
							<td>
								<label><input name="wpmar_include_archived" type="checkbox" <?php checked( ! empty( $settings['sites']['include_archived'] ) ); ?> /> <?php esc_html_e( 'アーカイブ済み', 'wp-maintenance-audit-reporter' ); ?></label><br />
								<label><input name="wpmar_include_spam" type="checkbox" <?php checked( ! empty( $settings['sites']['include_spam'] ) ); ?> /> <?php esc_html_e( 'スパム', 'wp-maintenance-audit-reporter' ); ?></label><br />
								<label><input name="wpmar_include_deleted" type="checkbox" <?php checked( ! empty( $settings['sites']['include_deleted'] ) ); ?> /> <?php esc_html_e( '削除済み', 'wp-maintenance-audit-reporter' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wpmar-max-sites"><?php esc_html_e( '最大サイト数', 'wp-maintenance-audit-reporter' ); ?></label></th>
							<td><input name="wpmar_max_sites" id="wpmar-max-sites" type="number" min="1" max="500" value="<?php echo esc_attr( (string) ( $settings['sites']['max_sites'] ?? 100 ) ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpmar-exclude-blog-ids"><?php esc_html_e( '除外する blog ID', 'wp-maintenance-audit-reporter' ); ?></label></th>
							<td>
								<textarea class="large-text code" rows="3" name="wpmar_exclude_blog_ids" id="wpmar-exclude-blog-ids"><?php echo esc_textarea( implode( "\n", array_map( 'strval', (array) ( $settings['sites']['exclude_blog_ids'] ?? array() ) ) ) ); ?></textarea>
								<p class="description"><?php esc_html_e( '1行1件、またはカンマ区切り。', 'wp-maintenance-audit-reporter' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="wpmar-section-panel">
					<h2><?php esc_html_e( 'ドメインゲート', 'wp-maintenance-audit-reporter' ); ?></h2>
					<p class="description"><?php esc_html_e( '各サイトの home_url と照合します。サイト設定に許可ホストが無い場合、ここで指定したホストをフォールバックとして使います。サブディレクトリ型マルチサイトではパスプレフィックスも指定できます。', 'wp-maintenance-audit-reporter' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wpmar-allowed-host"><?php esc_html_e( '許可ホスト（フォールバック）', 'wp-maintenance-audit-reporter' ); ?></label></th>
							<td><input class="regular-text" name="wpmar_allowed_host" id="wpmar-allowed-host" type="text" value="<?php echo esc_attr( (string) ( $settings['domain']['allowed_host'] ?? '' ) ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpmar-allowed-path-prefix"><?php esc_html_e( '許可パスプレフィックス（任意）', 'wp-maintenance-audit-reporter' ); ?></label></th>
							<td>
								<input class="regular-text" name="wpmar_allowed_path_prefix" id="wpmar-allowed-path-prefix" type="text" value="<?php echo esc_attr( (string) ( $settings['domain']['allowed_path_prefix'] ?? '' ) ); ?>" placeholder="blog/site-slug" />
								<p class="description"><?php esc_html_e( '未入力のときはホストのみ照合。入力した場合、home_url のパスがこのプレフィックスと一致するサイトだけがゲートを通過します。', 'wp-maintenance-audit-reporter' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="wpmar-section-panel">
					<h2><?php esc_html_e( 'メール通知', 'wp-maintenance-audit-reporter' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( '有効化', 'wp-maintenance-audit-reporter' ); ?></th>
							<td><label><input name="wpmar_mail_enabled" type="checkbox" <?php checked( ! empty( $settings['mail']['enabled'] ) ); ?> /> <?php esc_html_e( '集約レポート送信を有効化', 'wp-maintenance-audit-reporter' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpmar-client-mail"><?php esc_html_e( 'クライアント宛先', 'wp-maintenance-audit-reporter' ); ?></label></th>
							<td><textarea class="large-text" rows="3" name="wpmar_client_mail" id="wpmar-client-mail"><?php echo esc_textarea( implode( "\n", (array) ( $settings['mail']['client_to'] ?? array() ) ) ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpmar-admin-mail"><?php esc_html_e( '管理者宛先', 'wp-maintenance-audit-reporter' ); ?></label></th>
							<td><textarea class="large-text" rows="3" name="wpmar_admin_mail" id="wpmar-admin-mail"><?php echo esc_textarea( implode( "\n", (array) ( $settings['mail']['admin_to'] ?? array() ) ) ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpmar-from-email"><?php esc_html_e( 'From', 'wp-maintenance-audit-reporter' ); ?></label></th>
							<td>
								<input class="regular-text" name="wpmar_from_email" id="wpmar-from-email" type="email" value="<?php echo esc_attr( (string) ( $settings['mail']['from_address'] ?? '' ) ); ?>" />
								<input class="regular-text" name="wpmar_from_name" type="text" value="<?php echo esc_attr( (string) ( $settings['mail']['from_name'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( '差出人名', 'wp-maintenance-audit-reporter' ); ?>" />
							</td>
						</tr>
					</table>
				</div>

				<div class="wpmar-section-panel">
					<h2><?php esc_html_e( '出力・保持', 'wp-maintenance-audit-reporter' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Markdown / PDF', 'wp-maintenance-audit-reporter' ); ?></th>
							<td>
								<label><input name="wpmar_md_enabled" type="checkbox" <?php checked( ! empty( $settings['output']['md_enabled'] ) ); ?> /> <?php esc_html_e( '管理者向け Markdown を保存', 'wp-maintenance-audit-reporter' ); ?></label><br />
								<label><input name="wpmar_pdf_enabled" type="checkbox" <?php checked( ! empty( $settings['output']['pdf_enabled'] ) ); ?> /> <?php esc_html_e( 'クライアント向け PDF を保存', 'wp-maintenance-audit-reporter' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wpmar-retention-months"><?php esc_html_e( '保持期間', 'wp-maintenance-audit-reporter' ); ?></label></th>
							<td>
								<select name="wpmar_retention_months" id="wpmar-retention-months">
									<?php
									$months = absint( $settings['retention']['months'] ?? 12 );
									foreach ( array(
										0  => __( '無期限', 'wp-maintenance-audit-reporter' ),
										12 => '12',
										24 => '24',
									) as $val => $label ) :
										?>
										<option value="<?php echo esc_attr( (string) $val ); ?>" <?php selected( $months, $val ); ?>><?php echo esc_html( (string) $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</table>
				</div>

				<div class="wpmar-section-panel">
					<h2><?php esc_html_e( '検証ツール', 'wp-maintenance-audit-reporter' ); ?></h2>
					<p>
						<label for="wpmar-qa-mail"><?php esc_html_e( 'テストメール上書き先（メールアドレスを1件だけ指定可）', 'wp-maintenance-audit-reporter' ); ?></label><br />
						<input class="regular-text" name="wpmar_qa_mail" id="wpmar-qa-mail" type="email" placeholder="qa@example.com" />
					</p>
				</div>

				<p class="wpmar-manual-run-options description">
					<label for="wpmar-persist-snapshots">
						<input name="wpmar_persist_snapshots" id="wpmar-persist-snapshots" type="checkbox" value="1" />
						<?php esc_html_e( 'スナップショットを保存する（差分比較用）', 'wp-maintenance-audit-reporter' ); ?>
					</label>
				</p>

				<p class="wpmar-section-panel-actions">
					<button class="button button-primary" name="wpmar_admin_action" type="submit" value="save"><?php esc_html_e( '変更を保存', 'wp-maintenance-audit-reporter' ); ?></button>
					<button class="button" name="wpmar_admin_action" type="submit" value="dry_run"><?php esc_html_e( 'ドライラン', 'wp-maintenance-audit-reporter' ); ?></button>
					<button class="button" name="wpmar_admin_action" type="submit" value="full_run"><?php esc_html_e( '今すぐ実行', 'wp-maintenance-audit-reporter' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}
