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
		$last_done    = get_site_option( 'wpmar_last_network_audit_completed_at', '' );
		$cli          = WPMAR_CLI_Environment::snapshot();
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
					<li>
						<strong><?php esc_html_e( '直近の完了時刻 (UTC 保存)', 'wp-maintenance-audit-reporter' ); ?>:</strong>
						<?php echo esc_html( is_string( $last_done ) ? $last_done : '' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'WP-CLI', 'wp-maintenance-audit-reporter' ); ?>:</strong>
						<?php
						if ( ! empty( $cli['is_available'] ) ) {
							printf(
								/* translators: 1 CLI version, 2 last seen ISO time */
								esc_html__( '検出済み (version %1$s, last %2$s)', 'wp-maintenance-audit-reporter' ),
								esc_html( (string) ( $cli['wp_cli_version'] ?? '' ) ),
								esc_html( (string) ( $cli['last_seen_at'] ?? '' ) )
							);
						} else {
							esc_html_e( '未取得（CLI でコマンドを一度実行すると記録されます）', 'wp-maintenance-audit-reporter' );
						}
						?>
					</li>
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
							<td>
							<input class="regular-text" name="wpmar_schedule_tz" id="wpmar-schedule-tz" type="text" value="<?php echo esc_attr( (string) ( $settings['schedule']['tz'] ?? 'Asia/Tokyo' ) ); ?>" />
							<p class="description"><?php esc_html_e( '例: Asia/Tokyo。PHP が解釈できる識別子を指定してください。', 'wp-maintenance-audit-reporter' ); ?></p>
						</td>
						</tr>
					</table>
				</div>

				<div class="wpmar-section-panel">
					<h2><?php esc_html_e( '対象サイト', 'wp-maintenance-audit-reporter' ); ?></h2>
					<table class="form-table" role="presentation">
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
					<p class="description"><?php esc_html_e( '各サイトの home_url と照合します。サイト設定に許可ホストが無い場合、ここで指定したホストをフォールバックとして使います。', 'wp-maintenance-audit-reporter' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wpmar-allowed-host"><?php esc_html_e( '許可ホスト（フォールバック）', 'wp-maintenance-audit-reporter' ); ?></label></th>
							<td>
								<?php echo self::render_domain_gate_callout( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
							<th scope="row"><label for="wpmar-from-email"><?php esc_html_e( '送信元メールアドレス（オプション）', 'wp-maintenance-audit-reporter' ); ?></label></th>
							<td><input class="regular-text" name="wpmar_from_email" id="wpmar-from-email" type="email" value="<?php echo esc_attr( (string) ( $settings['mail']['from_address'] ?? '' ) ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpmar-from-name"><?php esc_html_e( '送信元表示名（オプション）', 'wp-maintenance-audit-reporter' ); ?></label></th>
							<td><input class="regular-text" name="wpmar_from_name" id="wpmar-from-name" type="text" value="<?php echo esc_attr( (string) ( $settings['mail']['from_name'] ?? '' ) ); ?>" /></td>
						</tr>
					</table>
				</div>

				<div class="wpmar-section-panel">
					<h2><?php esc_html_e( '保持期間', 'wp-maintenance-audit-reporter' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wpmar-retention-months"><?php esc_html_e( 'レポート保管期間', 'wp-maintenance-audit-reporter' ); ?></label></th>
							<td>
								<select name="wpmar_retention_months" id="wpmar-retention-months">
									<?php
									$months = absint( $settings['retention']['months'] ?? 12 );
									foreach ( array(
										0  => __( '無期限（自動削除しない）', 'wp-maintenance-audit-reporter' ),
										12 => '12 ヶ月より古いレポートを削除',
										24 => '24 ヶ月より古いレポートを削除',
									) as $val => $label ) :
										?>
										<option value="<?php echo esc_attr( (string) $val ); ?>" <?php selected( $months, $val ); ?>><?php echo esc_html( (string) $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( '最新の実行から起算して、指定した期間より古いレポートのデータとそのデータから生成されたファイルを自動で削除します。', 'wp-maintenance-audit-reporter' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="wpmar-section-panel">
					<h2><?php esc_html_e( 'レポートをファイルとして自動保存', 'wp-maintenance-audit-reporter' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Markdown を uploads に書き出して保存（管理者向け）', 'wp-maintenance-audit-reporter' ); ?></th>
							<td>
								<label>
									<input name="wpmar_md_enabled" type="checkbox" <?php checked( ! empty( $settings['output']['md_enabled'] ) ); ?> />
									<?php esc_html_e( '実行時に自動で `wp-content/uploads/wpmar/` に md ファイルを保存（管理者向け）', 'wp-maintenance-audit-reporter' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'PDF を uploads に書き出して保存（クライアント向け）', 'wp-maintenance-audit-reporter' ); ?></th>
							<td>
								<label>
									<input name="wpmar_pdf_enabled" type="checkbox" <?php checked( ! empty( $settings['output']['pdf_enabled'] ) ); ?> />
									<?php esc_html_e( '実行時に自動で `uploads/wpmar/pdf/` に PDF レポートを保存（クライアント向け）', 'wp-maintenance-audit-reporter' ); ?>
								</label>
								<?php if ( ! WPMAR_PDF_Installer::is_installed() ) : ?>
									<p class="description" style="color:#996800;margin-top:4px;">
										<?php esc_html_e( 'PDF ライブラリが未インストールのため、この設定は現在機能しません。下の「PDF ライブラリ（mPDF）」セクションからインストールしてください。', 'wp-maintenance-audit-reporter' ); ?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					</table>
				</div>

				<?php WPMAR_PDF_Installer::render_panel(); ?>

				<div class="wpmar-section-panel">
					<h2><?php esc_html_e( '検証ツール', 'wp-maintenance-audit-reporter' ); ?></h2>
					<p>
						<label for="wpmar-qa-mail"><?php esc_html_e( 'テストメール上書き先（メールアドレスを1件だけ指定可）', 'wp-maintenance-audit-reporter' ); ?></label><br />
						<input class="regular-text" name="wpmar_qa_mail" id="wpmar-qa-mail" type="email" placeholder="qa@example.com" />
					</p>
					<p class="description">
						<?php esc_html_e( 'メール通知が有効なとき、「今すぐ実行」でここにアドレスを入れていると、設定どおりの宛先への送信に加え、クライアント向けレポートメールと管理者向けレポートメールをそれぞれ1通ずつ（最大2通）このアドレスにも追加送信します。既にクライアント宛先／管理者宛先に含まれるアドレスと同じ場合は、該当する種類の重複送信はしません。', 'wp-maintenance-audit-reporter' ); ?>
					</p>
				</div>

				<p class="wpmar-manual-run-options description">
					<label for="wpmar-persist-snapshots">
						<input name="wpmar_persist_snapshots" id="wpmar-persist-snapshots" type="checkbox" value="1" />
						<?php esc_html_e( 'スナップショットを保存する（差分比較用）', 'wp-maintenance-audit-reporter' ); ?>
					</label><br />
					<span class="description">
						<?php esc_html_e( '「今すぐ実行」でチェックを入れたときのみ、DB のスナップショット行を更新します。チェックなしの手動実行ではレポートのみ作成し、スナップショットは更新しません。WP-Cron の定期実行では常にスナップショットを保存します。', 'wp-maintenance-audit-reporter' ); ?>
					</span>
					<span class="description" style="display:block;margin-top:8px;">
						<?php esc_html_e( '変更履歴の差分は、保存済みスナップショット（比較の基準）と、この実行で収集した現在のサイト状態を常に突き合わせて計算します。スナップショットを保存しなくても、レポート本文・一覧データは常に今回の収集結果（現在のファイル状態）に基づきます。', 'wp-maintenance-audit-reporter' ); ?>
					</span>
				</p>

				<p class="wpmar-section-panel-actions">
					<button class="button button-primary" name="wpmar_admin_action" type="submit" value="save"><?php esc_html_e( '変更を保存', 'wp-maintenance-audit-reporter' ); ?></button>
					<button class="button" name="wpmar_admin_action" type="submit" value="dry_run"><?php esc_html_e( 'ドライラン', 'wp-maintenance-audit-reporter' ); ?></button>
					<button class="button" name="wpmar_admin_action" type="submit" value="full_run"><?php esc_html_e( '今すぐ実行', 'wp-maintenance-audit-reporter' ); ?></button>
				</p>
				<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
					<p class="description" style="color:#b32d2e;">
						<?php esc_html_e( '⚠ WP-Cron が無効（DISABLE_WP_CRON）です。「今すぐ実行」は使用できません。WP-CLI で実行してください：', 'wp-maintenance-audit-reporter' ); ?>
						<code>wp maintenance-audit run --network</code>
					</p>
				<?php else : ?>
					<p class="description">
						<?php esc_html_e( '「今すぐ実行」はサイト数が多い場合に 504 タイムアウトが発生するため、バックグラウンド（WP-Cron）でキューに追加して実行します。即時・確実に実行したい場合は WP-CLI を使用してください：', 'wp-maintenance-audit-reporter' ); ?>
						<code>wp maintenance-audit run --network</code>
					</p>
				<?php endif; ?>
			</form>

			<div
				id="wpmar-busy-overlay"
				class="wpmar-busy-overlay"
				hidden
				aria-hidden="true"
				aria-live="polite"
				aria-busy="false"
				role="status"
			>
				<div class="wpmar-busy-panel">
					<span class="wpmar-spinner" aria-hidden="true"></span>
					<p id="wpmar-busy-message" class="wpmar-busy-message"></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the allowed-host input and detection feedback for the domain gate row.
	 *
	 * @param array<string,mixed> $settings Network settings.
	 * @return string Buffered HTML (escaped fragments).
	 */
	private static function render_domain_gate_callout( array $settings ) {
		$ctx           = WPMAR_Domain_Gate::admin_gate_callout_context( $settings );
		$detected_show = '' !== $ctx['detected'] ? $ctx['detected'] : '—';

		ob_start();
		?>
		<input class="regular-text" name="wpmar_allowed_host" id="wpmar-allowed-host" type="text" value="<?php echo esc_attr( (string) ( $settings['domain']['allowed_host'] ?? '' ) ); ?>" />
		<div class="wpmar-domain-gate-feedback">
			<p class="wpmar-domain-gate-detected">
				<strong><?php esc_html_e( '検出されたサイトホスト:', 'wp-maintenance-audit-reporter' ); ?></strong>
				<code><?php echo esc_html( $detected_show ); ?></code>
			</p>
			<?php if ( 'empty' === $ctx['state'] ) : ?>
				<p class="wpmar-domain-gate-msg wpmar-domain-gate-msg--warn">
					<span class="wpmar-domain-gate-icon" aria-hidden="true">&#9888;</span>
					<?php esc_html_e( '許可ホストが未入力です。ステージングで保存を抑止したい場合は検出ホストどおり入力して保存してください。', 'wp-maintenance-audit-reporter' ); ?>
				</p>
			<?php elseif ( 'match' === $ctx['state'] ) : ?>
				<p class="wpmar-domain-gate-msg wpmar-domain-gate-msg--ok">
					<span class="wpmar-domain-gate-icon" aria-hidden="true">&#10003;</span>
					<?php esc_html_e( '保存済みの許可ホストと一致しています。ドメインゲートは通過し、実行でスナップショット等が保存されます。', 'wp-maintenance-audit-reporter' ); ?>
				</p>
			<?php else : ?>
				<p class="wpmar-domain-gate-msg wpmar-domain-gate-msg--bad">
					<span class="wpmar-domain-gate-icon" aria-hidden="true">&#10007;</span>
					<?php esc_html_e( '保存済みの許可ホストと一致しません。実行は開始できますが、ゲートによりスナップショット・メール・管理者向け Markdown などが抑止されます。', 'wp-maintenance-audit-reporter' ); ?>
				</p>
				<p class="wpmar-domain-gate-compare">
					<?php
					printf(
						'%s <code>%s</code> / %s <code>%s</code>',
						esc_html__( '保存値:', 'wp-maintenance-audit-reporter' ),
						esc_html( '' !== trim( (string) $ctx['saved_display'] ) ? (string) $ctx['saved_display'] : '—' ),
						esc_html__( '検出値:', 'wp-maintenance-audit-reporter' ),
						esc_html( $detected_show )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}
}
