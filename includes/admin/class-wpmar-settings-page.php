<?php
/**
 * Renders the consolidated settings + operator controls UI.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outputs form markup; sanitisation occurs in {@see WPMAR_Settings::merge_form_input()}.
 */
class WPMAR_Settings_Page {

	/**
	 * Outputs the HTML form.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = WPMAR_Settings::get_all();
		$cli      = WPMAR_CLI_Environment::snapshot();

		$tz_slug = isset( $settings['schedule']['tz'] ) ? $settings['schedule']['tz'] : 'Asia/Tokyo';
		$tz_obj  = null;
		try {
			$tz_obj = new DateTimeZone( $tz_slug );
		} catch ( Exception $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Fallback below.
			unset( $ignored );
			$tz_obj = new DateTimeZone( 'Asia/Tokyo' );
		}

		$next_ts  = wp_next_scheduled( WPMAR_HOOK_SCHEDULED );
		$next_lbl = __( '未スケジュール', 'wp-maintenance-audit-reporter' );
		if ( false !== $next_ts ) {
			$next_lbl = wp_date( 'Y-m-d H:i:s', $next_ts, $tz_obj );
		}

		$last_done = get_option( 'wpmar_last_audit_completed_at', '' );
		$dry_note  = WPMAR_Admin_Menu::consume_dry_run_brevity();

		// Notices from add_settings_error(); call settings_errors() in this screen (not options.php).

		// Core status readouts + optional dry-run <pre> on the POST response immediately after 「ドライラン」.
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php settings_errors( 'wpmar_messages' ); ?>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( WPMAR_Admin_Menu::admin_screen_url( WPMAR_REPORTS_PAGE_SLUG ) ); ?>">
					<?php esc_html_e( 'レポート一覧を開く', 'wp-maintenance-audit-reporter' ); ?>
				</a>
			</p>
			<p>
				<?php esc_html_e( '月次の保守レポートを生成し、差分・Markdown・メール通知を制御します。', 'wp-maintenance-audit-reporter' ); ?>
			</p>

			<h2><?php esc_html_e( 'ステータス', 'wp-maintenance-audit-reporter' ); ?></h2>
			<ul>
				<li>
					<strong><?php esc_html_e( '次回 WP-Cron', 'wp-maintenance-audit-reporter' ); ?>:</strong>
					<?php echo esc_html( $next_lbl ); ?>
				</li>
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

			<?php if ( is_string( $dry_note ) && '' !== trim( $dry_note ) ) : ?>
				<h2><?php esc_html_e( 'ドライラン要約', 'wp-maintenance-audit-reporter' ); ?></h2>
				<pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:240px;overflow:auto;"><?php echo esc_html( $dry_note ); ?></pre>
			<?php endif; ?>

			<?php
			// All actions POST back here; nonce + capability enforced in Admin_Menu.
			?>
			<form id="wpmar-settings-form" class="wpmar-settings-form" method="post" action="<?php echo esc_url( WPMAR_Admin_Menu::admin_screen_url( WPMAR_ADMIN_PAGE_SLUG ) ); ?>">
				<?php wp_nonce_field( 'wpmar_settings_save', 'wpmar_settings_nonce' ); ?>

				<h2><?php esc_html_e( 'スケジュール', 'wp-maintenance-audit-reporter' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpmar-schedule-day"><?php esc_html_e( '実行日 (1〜31)', 'wp-maintenance-audit-reporter' ); ?></label></th>
						<td>
							<input name="wpmar_schedule_day" id="wpmar-schedule-day" type="number" min="1" max="31" value="<?php echo esc_attr( (string) ( $settings['schedule']['day'] ?? 25 ) ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '時刻', 'wp-maintenance-audit-reporter' ); ?></th>
						<td>
							<label>
								<?php esc_html_e( '時', 'wp-maintenance-audit-reporter' ); ?>
								<input name="wpmar_schedule_hour" type="number" min="0" max="23" value="<?php echo esc_attr( (string) ( $settings['schedule']['hour'] ?? 2 ) ); ?>" />
							</label>
							<label>
								<?php esc_html_e( '分', 'wp-maintenance-audit-reporter' ); ?>
								<input name="wpmar_schedule_minute" type="number" min="0" max="59" value="<?php echo esc_attr( (string) ( $settings['schedule']['minute'] ?? 0 ) ); ?>" />
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

				<h2><?php esc_html_e( 'ドメインゲート', 'wp-maintenance-audit-reporter' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpmar-allowed-host"><?php esc_html_e( '許可ホスト', 'wp-maintenance-audit-reporter' ); ?></label></th>
						<td>
							<?php echo self::render_domain_gate_callout( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'セキュリティ診断（レポート）', 'wp-maintenance-audit-reporter' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'フル実行・ドライランの監査データに含めます。SSL 検査はサイトが https のときのみサーバーへ短時間接続します。', 'wp-maintenance-audit-reporter' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'SSL 証明書の期限確認', 'wp-maintenance-audit-reporter' ); ?></th>
						<td>
							<label>
								<input name="wpmar_security_ssl_enabled" type="checkbox" <?php checked( ! empty( $settings['security']['ssl_check_enabled'] ) ); ?> />
								<?php esc_html_e( '有効（推奨）', 'wp-maintenance-audit-reporter' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpmar-admin-stale-days"><?php esc_html_e( '管理者「長期未ログイン」の日数', 'wp-maintenance-audit-reporter' ); ?></label></th>
						<td>
							<input name="wpmar_admin_stale_days" id="wpmar-admin-stale-days" type="number" min="30" max="730" step="1" value="<?php echo esc_attr( (string) ( $settings['security']['admin_stale_days'] ?? 90 ) ); ?>" />
							<p class="description"><?php esc_html_e( 'この日数より古い最終セッションを「注意」として数えます（30〜730）。', 'wp-maintenance-audit-reporter' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'メール通知', 'wp-maintenance-audit-reporter' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( '有効化', 'wp-maintenance-audit-reporter' ); ?></th>
						<td>
							<label>
								<input name="wpmar_mail_enabled" type="checkbox" <?php checked( ! empty( $settings['mail']['enabled'] ) ); ?> />
								<?php esc_html_e( 'レポート送信を有効化', 'wp-maintenance-audit-reporter' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpmar-client-mail"><?php esc_html_e( 'クライアント向け宛先（改行区切り）', 'wp-maintenance-audit-reporter' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="4" name="wpmar_client_mail" id="wpmar-client-mail"><?php echo esc_textarea( implode( "\n", array_map( 'sanitize_text_field', (array) ( $settings['mail']['client_to'] ?? array() ) ) ) ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpmar-admin-mail"><?php esc_html_e( '運用宛先（改行区切り）', 'wp-maintenance-audit-reporter' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="4" name="wpmar_admin_mail" id="wpmar-admin-mail"><?php echo esc_textarea( implode( "\n", array_map( 'sanitize_text_field', (array) ( $settings['mail']['admin_to'] ?? array() ) ) ) ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpmar-from-email"><?php esc_html_e( '送信元メールアドレス', 'wp-maintenance-audit-reporter' ); ?></label></th>
						<td><input class="regular-text" name="wpmar_from_email" id="wpmar-from-email" type="email" value="<?php echo esc_attr( (string) ( $settings['mail']['from_address'] ?? '' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="wpmar-from-name"><?php esc_html_e( '送信元表示名', 'wp-maintenance-audit-reporter' ); ?></label></th>
						<td><input class="regular-text" name="wpmar_from_name" id="wpmar-from-name" type="text" value="<?php echo esc_attr( (string) ( $settings['mail']['from_name'] ?? '' ) ); ?>" /></td>
					</tr>
				</table>

				<?php
				$retention_months = isset( $settings['retention']['months'] ) ? absint( $settings['retention']['months'] ) : 12;
				$core_excludes    = isset( $settings['checksums']['core_exclude_paths'] ) && is_array( $settings['checksums']['core_exclude_paths'] )
					? $settings['checksums']['core_exclude_paths']
					: array();
				$plugin_rules     = isset( $settings['checksums']['plugin_exclude_rules'] ) && is_array( $settings['checksums']['plugin_exclude_rules'] )
					? $settings['checksums']['plugin_exclude_rules']
					: array();
				?>
				<h2><?php esc_html_e( 'チェックサム除外リスト', 'wp-maintenance-audit-reporter' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'コアは ABSPATH からの相対パス（例: wp-config.php）。プラグインは「スラッグ:相対パス」1 行に 1 エントリ（例: akismet:readme.txt）。# で始まる行はコメントとして無視されます。', 'wp-maintenance-audit-reporter' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpmar-core-excludes"><?php esc_html_e( 'コア除外パス', 'wp-maintenance-audit-reporter' ); ?></label></th>
						<td>
							<textarea class="large-text code" rows="6" name="wpmar_core_checksum_excludes" id="wpmar-core-excludes"><?php echo esc_textarea( implode( "\n", array_map( 'strval', $core_excludes ) ) ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpmar-plugin-excludes"><?php esc_html_e( 'プラグイン除外', 'wp-maintenance-audit-reporter' ); ?></label></th>
						<td>
							<textarea class="large-text code" rows="6" name="wpmar_plugin_checksum_excludes" id="wpmar-plugin-excludes"><?php echo esc_textarea( self::stringify_plugin_exclude_textarea( $plugin_rules ) ); ?></textarea>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( '保持期間', 'wp-maintenance-audit-reporter' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpmar-retention"><?php esc_html_e( 'レポート保管期間', 'wp-maintenance-audit-reporter' ); ?></label></th>
						<td>
							<select name="wpmar_retention_months" id="wpmar-retention">
								<option value="0" <?php selected( $retention_months, 0 ); ?>><?php esc_html_e( '無期限（自動削除しない）', 'wp-maintenance-audit-reporter' ); ?></option>
								<option value="12" <?php selected( $retention_months, 12 ); ?>><?php esc_html_e( '12 ヶ月より古いレポートを削除', 'wp-maintenance-audit-reporter' ); ?></option>
								<option value="24" <?php selected( $retention_months, 24 ); ?>><?php esc_html_e( '24 ヶ月より古いレポートを削除', 'wp-maintenance-audit-reporter' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'フル実行のたびに起算して古い行と Markdown ファイルを削除します。', 'wp-maintenance-audit-reporter' ); ?></p>
						</td>
					</tr>
				</table>

				<?php
				// Markdown + QA tools continue below.
				?>
				<h2><?php esc_html_e( 'Markdown 出力', 'wp-maintenance-audit-reporter' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Markdown を uploads に保存', 'wp-maintenance-audit-reporter' ); ?></th>
						<td>
							<label>
								<input name="wpmar_md_enabled" type="checkbox" <?php checked( ! empty( $settings['output']['md_enabled'] ) ); ?> />
								<?php esc_html_e( '`wp-content/uploads/wpmar/*.md` を生成する', 'wp-maintenance-audit-reporter' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( '検証ツール', 'wp-maintenance-audit-reporter' ); ?></h2>
				<p>
					<label for="wpmar-qa-mail"><?php esc_html_e( 'テストメール上書き先（単一のアドレス）', 'wp-maintenance-audit-reporter' ); ?></label><br />
					<input class="regular-text" name="wpmar_qa_mail" id="wpmar-qa-mail" type="email" placeholder="qa@example.com" />
				</p>

				<p>
					<button class="button button-primary" name="wpmar_admin_action" type="submit" value="save"><?php esc_html_e( '変更を保存', 'wp-maintenance-audit-reporter' ); ?></button>
					<button class="button" name="wpmar_admin_action" type="submit" value="dry_run"><?php esc_html_e( 'ドライラン', 'wp-maintenance-audit-reporter' ); ?></button>
					<button class="button" name="wpmar_admin_action" type="submit" value="full_run"><?php esc_html_e( '今すぐフル実行', 'wp-maintenance-audit-reporter' ); ?></button>
					<button class="button" name="wpmar_admin_action" type="submit" value="test_mail"><?php esc_html_e( 'テストメール付き実行', 'wp-maintenance-audit-reporter' ); ?></button>
				</p>
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
	 * 「許可ホスト」入力欄 plus forced-auto-update-controller 風の照合ボックス。
	 *
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return string Buffered HTML (escaped fragments).
	 */
	private static function render_domain_gate_callout( array $settings ) {
		$ctx           = WPMAR_Domain_Gate::admin_gate_callout_context( $settings );
		$detected_show = '' !== $ctx['detected'] ? $ctx['detected'] : '—';

		ob_start();
		?>
		<p class="description">
			<?php esc_html_e( '「サイトのアドレス」で使われるホスト名と突き合わせます。本番のみで結果を書き込みたいときは、下の検出ホストと同じ名前を許可ホストへ入力して保存してください。未入力のままではあらゆる環境でゲートを通過します（緩い設定です）。', 'wp-maintenance-audit-reporter' ); ?>
		</p>
		<input class="regular-text" name="wpmar_allowed_host" id="wpmar-allowed-host" type="text" value="<?php echo esc_attr( (string) ( $settings['domain']['allowed_host'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr__( '例: example.com', 'wp-maintenance-audit-reporter' ); ?>" />
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
					<?php esc_html_e( '保存済みの許可ホストと一致しています。ドメインゲートは通過し、フル実行でスナップショット等が保存されます。', 'wp-maintenance-audit-reporter' ); ?>
				</p>
			<?php else : ?>
				<p class="wpmar-domain-gate-msg wpmar-domain-gate-msg--bad">
					<span class="wpmar-domain-gate-icon" aria-hidden="true">&#10007;</span>
					<?php esc_html_e( '保存済みの許可ホストと一致しません。フル実行は開始できますが、ゲートによりスナップショット・メール・Markdown などが抑止されます。', 'wp-maintenance-audit-reporter' ); ?>
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

	/**
	 * Flattens structured plugin exclude map for textarea storage.
	 *
	 * @param array<string,array<int,string>> $rules Slug keyed bundle.
	 * @return string
	 */
	private static function stringify_plugin_exclude_textarea( array $rules ) {
		$lines = array();

		foreach ( $rules as $slug => $paths ) {
			$slug_safe = sanitize_key( (string) $slug );
			if ( '' === $slug_safe ) {
				continue;
			}

			foreach ( (array) $paths as $fragment ) {
				$lines[] = $slug_safe . ':' . (string) $fragment;
			}
		}

		return implode( "\n", $lines );
	}
}
