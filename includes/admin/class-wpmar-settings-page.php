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

		$last_done   = get_option( 'wpmar_last_audit_completed_at', '' );
		$dry_note    = WPMAR_Admin_Menu::consume_dry_run_brevity();
		$runs_locked = WPMAR_Network::per_site_runs_disabled();

		// Validate mail recipient settings and surface warnings before rendering notices.
		if ( ! empty( $settings['mail']['enabled'] ) ) {
			$has_client = ! empty( $settings['mail']['client_to'] );
			$has_admin  = ! empty( $settings['mail']['admin_to'] );

			if ( ! $has_client && ! $has_admin ) {
				add_settings_error(
					'wpmar_messages',
					'wpmar_mail_no_recipients',
					__( 'メール通知が有効ですが、クライアント向け宛先・管理者向け宛先がどちらも空です。少なくとも一方にメールアドレスを設定してください。', 'wp-maintenance-audit-reporter' ),
					'error'
				);
			} else {
				if ( ! $has_client ) {
					add_settings_error(
						'wpmar_messages',
						'wpmar_mail_no_client',
						__( 'メール通知が有効ですが、クライアント向け宛先が空のためクライアントへのメールは送信されません。', 'wp-maintenance-audit-reporter' ),
						'warning'
					);
				}
				if ( ! $has_admin ) {
					add_settings_error(
						'wpmar_messages',
						'wpmar_mail_no_admin',
						__( 'メール通知が有効ですが、管理者向け宛先が空のため管理者へのメールは送信されません。', 'wp-maintenance-audit-reporter' ),
						'warning'
					);
				}
			}
		}

		// Notices from add_settings_error(); call settings_errors() in this screen (not options.php).

		// Core status readouts + optional dry-run <pre> on the POST response immediately after 「ドライラン」.
		?>
		<div class="wrap wpmar-maintenance-settings">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php settings_errors( 'wpmar_messages' ); ?>
			<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
				<div class="notice notice-error">
					<p>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: WP-CLI command */
								__( '<strong>WP-Cron が無効（DISABLE_WP_CRON）です。</strong>スケジュールによる定期実行および「今すぐ実行」はどちらも機能しません。WP-CLI（%s）を使用するか、サーバーの外部 Cron から <code>wp cron event run --due-now</code> を定期的に呼び出してください。', 'wp-maintenance-audit-reporter' ),
								'<code>wp maintenance-audit run</code>'
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>
			<?php if ( $runs_locked ) : ?>
				<div class="notice notice-info">
					<p>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: network admin settings link */
								__( 'ネットワーク集約監査が有効です。このサイトの個別実行は無効です。集約レポートは %s から実行・確認してください。', 'wp-maintenance-audit-reporter' ),
								'<a href="' . esc_url( network_admin_url( 'admin.php?page=' . WPMAR_NETWORK_ADMIN_PAGE_SLUG ) ) . '">' . esc_html__( 'ネットワーク設定', 'wp-maintenance-audit-reporter' ) . '</a>'
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>
			<?php WPMAR_Admin_Menu::maybe_render_audit_storage_empty_notice(); ?>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( WPMAR_Admin_Menu::admin_screen_url( WPMAR_REPORTS_PAGE_SLUG ) ); ?>">
					<?php esc_html_e( 'レポート一覧を開く', 'wp-maintenance-audit-reporter' ); ?>
				</a>
			</p>
			<p>
				<?php esc_html_e( '月次の保守レポートを生成し、差分・Markdown（管理者向け）・メール通知を制御します。', 'wp-maintenance-audit-reporter' ); ?>
			</p>

			<div class="wpmar-section-panel">
				<h2><?php esc_html_e( 'ステータス', 'wp-maintenance-audit-reporter' ); ?></h2>
				<ul class="wpmar-section-panel-body">
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
			</div>

			<?php if ( is_string( $dry_note ) && '' !== trim( $dry_note ) ) : ?>
				<div class="wpmar-section-panel">
					<h2><?php esc_html_e( 'ドライラン要約', 'wp-maintenance-audit-reporter' ); ?></h2>
					<pre class="wpmar-dry-run-summary"><?php echo esc_html( $dry_note ); ?></pre>
				</div>
			<?php endif; ?>

			<?php
			// All actions POST back here; nonce + capability enforced in Admin_Menu.
			?>
			<form id="wpmar-settings-form" class="wpmar-settings-form" method="post" action="<?php echo esc_url( WPMAR_Admin_Menu::admin_screen_url( WPMAR_ADMIN_PAGE_SLUG ) ); ?>">
				<?php wp_nonce_field( 'wpmar_settings_save', 'wpmar_settings_nonce' ); ?>

				<div class="wpmar-section-panel">
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
					<h2><?php esc_html_e( 'ドメインゲート', 'wp-maintenance-audit-reporter' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wpmar-allowed-host"><?php esc_html_e( '許可ホスト', 'wp-maintenance-audit-reporter' ); ?></label></th>
							<td>
								<?php echo self::render_domain_gate_callout( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</td>
						</tr>
					</table>
				</div>

				<div class="wpmar-section-panel">
					<h2><?php esc_html_e( 'セキュリティ診断（レポート）', 'wp-maintenance-audit-reporter' ); ?></h2>
					<p class="description">
						<?php esc_html_e( '実行・ドライランのいずれの監査でもデータに含めます。SSL 検査はサイトが https のときのみサーバーへ短時間接続します。', 'wp-maintenance-audit-reporter' ); ?>
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
				</div>

				<div class="wpmar-section-panel">
					<h2><?php esc_html_e( 'オプション：データベースサイズチェック', 'wp-maintenance-audit-reporter' ); ?></h2>
					<p class="description"><?php esc_html_e( 'チェックを入れたときのみ、監査収集中に information_schema を参照してテーブルごとのサイズの上位サンプルを集計します（ホスティングにより失敗することがあります）。', 'wp-maintenance-audit-reporter' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( '上位テーブルサイズを集計', 'wp-maintenance-audit-reporter' ); ?></th>
							<td>
								<label>
									<input name="wpmar_db_size_check_enabled" id="wpmar-db-size-check-enabled" type="checkbox" <?php checked( ! empty( $settings['performance']['db_size_enabled'] ) ); ?> />
									<?php esc_html_e( '有効（既定 OFF）', 'wp-maintenance-audit-reporter' ); ?>
								</label>
							</td>
						</tr>
					</table>
				</div>

				<div class="wpmar-section-panel">
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
							<th scope="row"><label for="wpmar-admin-mail"><?php esc_html_e( '管理者向け宛先（改行区切り）', 'wp-maintenance-audit-reporter' ); ?></label></th>
							<td>
								<textarea class="large-text" rows="4" name="wpmar_admin_mail" id="wpmar-admin-mail"><?php echo esc_textarea( implode( "\n", array_map( 'sanitize_text_field', (array) ( $settings['mail']['admin_to'] ?? array() ) ) ) ); ?></textarea>
							</td>
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

				<?php
				$retention_months = isset( $settings['retention']['months'] ) ? absint( $settings['retention']['months'] ) : 12;
				$core_excludes    = isset( $settings['checksums']['core_exclude_paths'] ) && is_array( $settings['checksums']['core_exclude_paths'] )
					? $settings['checksums']['core_exclude_paths']
					: array();
				$plugin_rules     = isset( $settings['checksums']['plugin_exclude_rules'] ) && is_array( $settings['checksums']['plugin_exclude_rules'] )
					? $settings['checksums']['plugin_exclude_rules']
					: array();
				?>
				<div class="wpmar-section-panel">
					<h2><?php esc_html_e( 'チェックサム除外リスト', 'wp-maintenance-audit-reporter' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'コアは ABSPATH からの相対パス（例: wp-config.php）。プラグインは「スラッグ:相対パス」1 行に 1 エントリ（例: akismet:readme.txt）。ディレクトリ以下をすべて除外するには末尾に / または /* を付けてください（例: wp-admin/ または wp-admin/*）。# で始まる行はコメントとして無視されます。', 'wp-maintenance-audit-reporter' ); ?>
					</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wpmar-core-excludes"><?php esc_html_e( 'コア除外パス', 'wp-maintenance-audit-reporter' ); ?></label></th>
							<td>
								<textarea class="large-text code" rows="6" name="wpmar_core_checksum_excludes" id="wpmar-core-excludes"><?php echo esc_textarea( implode( "\n", array_map( 'strval', $core_excludes ) ) ); ?></textarea>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wpmar-plugin-excludes"><?php esc_html_e( 'プラグイン除外パス', 'wp-maintenance-audit-reporter' ); ?></label></th>
							<td>
								<textarea class="large-text code" rows="6" name="wpmar_plugin_checksum_excludes" id="wpmar-plugin-excludes"><?php echo esc_textarea( self::stringify_plugin_exclude_textarea( $plugin_rules ) ); ?></textarea>
							</td>
						</tr>
					</table>
				</div>

				<div class="wpmar-section-panel">
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
								<p class="description"><?php esc_html_e( '最新の実行から起算して、指定した期間より古いレポートのデータとそのデータから生成されたファイルを自動で削除します。', 'wp-maintenance-audit-reporter' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<?php
				// Markdown + QA tools continue below.
				?>
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
					<?php if ( ! $runs_locked ) : ?>
						<button class="button" name="wpmar_admin_action" type="submit" value="dry_run"><?php esc_html_e( 'ドライラン', 'wp-maintenance-audit-reporter' ); ?></button>
						<button class="button" name="wpmar_admin_action" type="submit" value="full_run"><?php esc_html_e( '今すぐ実行', 'wp-maintenance-audit-reporter' ); ?></button>
					<?php endif; ?>
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
