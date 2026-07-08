<?php
/**
 * Diagnostics UI: recent job log tail viewer + nonce-guarded download.
 *
 * Mirrors {@see WPMAR_Reports_Page::maybe_stream_report_download()}'s auth pattern
 * (capability + per-id nonce), but resolves the file path strictly from the jobs
 * table row rather than trusting any path fragment from the request.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Diagnostics section and streams log downloads.
 */
class WPMAR_Log_Viewer {

	/** Bytes read from the tail of a log file for on-screen preview / truncated download context. */
	const TAIL_BYTES = 32768;

	/**
	 * Streams a job's log file before admin HTML is sent. Hooked on `admin_init` priority 0.
	 *
	 * @return void
	 */
	public static function maybe_stream_log_download() {
		if ( ! is_admin() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked below after the capability gate; this is a read-only stream.
		if ( ! isset( $_GET['page'] ) || WPMAR_REPORTS_PAGE_SLUG !== $_GET['page'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['wpmar_log'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '権限がありません。', 'wp-maintenance-audit-reporter' ) );
		}

		$job_id = WPMAR_Jobs_Repository::sanitize_id( wp_unslash( (string) $_GET['wpmar_log'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $job_id ) {
			wp_die( esc_html__( 'ジョブ ID が無効です。', 'wp-maintenance-audit-reporter' ) );
		}

		check_admin_referer( 'wpmar_dl_log_' . $job_id );

		$repo = new WPMAR_Jobs_Repository();
		$job  = $repo->find( $job_id );

		if ( null === $job || empty( $job['log_path'] ) ) {
			wp_die( esc_html__( '指定されたログは存在しません。', 'wp-maintenance-audit-reporter' ) );
		}

		$abs = self::resolve_log_absolute_path( (string) $job['log_path'] );
		if ( '' === $abs || ! is_readable( $abs ) ) {
			wp_die( esc_html__( 'ログファイルを読み込めませんでした。', 'wp-maintenance-audit-reporter' ) );
		}

		$size = filesize( $abs );

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'wpmar-' . $job_id . '.log' ) . '"' );
		if ( false !== $size ) {
			header( 'Content-Length: ' . (string) $size );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streams a plain-text log from our own protected directory.
		readfile( $abs );
		exit;
	}

	/**
	 * Prints the "Diagnostics" section under the reports list: recent jobs with a log,
	 * a tail preview of the selected job, and a download link.
	 *
	 * @return void
	 */
	public static function render_section() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$repo = new WPMAR_Jobs_Repository();
		$repo->sweep_stale_running();

		$jobs = $repo->find_recent_with_log( 20 );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selector, no state change.
		$selected_id = isset( $_GET['wpmar_log_view'] ) ? WPMAR_Jobs_Repository::sanitize_id( wp_unslash( (string) $_GET['wpmar_log_view'] ) ) : '';

		?>
		<h2><?php esc_html_e( '診断ログ', 'wp-maintenance-audit-reporter' ); ?></h2>
		<p class="description">
			<?php esc_html_e( '監査ジョブの実行ステップを記録したログです。途中で止まった場合、最後に記録されたステップが停止箇所の手がかりになります。', 'wp-maintenance-audit-reporter' ); ?>
		</p>

		<?php if ( empty( $jobs ) ) : ?>
			<p><?php esc_html_e( 'ログはまだありません。', 'wp-maintenance-audit-reporter' ); ?></p>
			<?php
			return;
		endif;
		?>

		<table class="widefat striped" style="max-width:900px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'ジョブ ID', 'wp-maintenance-audit-reporter' ); ?></th>
					<th><?php esc_html_e( '状態', 'wp-maintenance-audit-reporter' ); ?></th>
					<th><?php esc_html_e( '最終ステップ', 'wp-maintenance-audit-reporter' ); ?></th>
					<th><?php esc_html_e( '更新日時 (UTC)', 'wp-maintenance-audit-reporter' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $jobs as $job ) : ?>
					<?php
					$job_id    = (string) $job['id'];
					$view_url  = add_query_arg(
						array(
							'page'           => WPMAR_REPORTS_PAGE_SLUG,
							'wpmar_log_view' => $job_id,
						),
						admin_url( 'admin.php' )
					);
					$download_url = wp_nonce_url(
						add_query_arg(
							array(
								'page'      => WPMAR_REPORTS_PAGE_SLUG,
								'wpmar_log' => $job_id,
							),
							admin_url( 'admin.php' )
						),
						'wpmar_dl_log_' . $job_id
					);
					?>
					<tr>
						<td><code><?php echo esc_html( $job_id ); ?></code></td>
						<td><?php echo esc_html( (string) $job['status'] ); ?></td>
						<td><?php echo esc_html( (string) $job['step'] ); ?></td>
						<td><?php echo esc_html( (string) $job['updated_at'] ); ?></td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( '表示', 'wp-maintenance-audit-reporter' ); ?></a>
							<a class="button button-small" href="<?php echo esc_url( $download_url ); ?>"><?php esc_html_e( 'ダウンロード', 'wp-maintenance-audit-reporter' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( '' !== $selected_id ) : ?>
			<?php self::render_tail_preview( $selected_id ); ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Prints the tail of a selected job's log inside a scrollable `<pre>`.
	 *
	 * @param string $job_id Job id (already sanitized by the caller).
	 * @return void
	 */
	private static function render_tail_preview( $job_id ) {
		$repo = new WPMAR_Jobs_Repository();
		$job  = $repo->find( $job_id );

		if ( null === $job || empty( $job['log_path'] ) ) {
			return;
		}

		$abs = self::resolve_log_absolute_path( (string) $job['log_path'] );
		if ( '' === $abs || ! is_readable( $abs ) ) {
			return;
		}

		$tail  = self::read_tail( $abs, self::TAIL_BYTES );
		$lines = explode( "\n", $tail );
		$lines = array_slice( $lines, -200 );

		printf(
			'<h3>%s <code>%s</code></h3>',
			esc_html__( 'ログ末尾:', 'wp-maintenance-audit-reporter' ),
			esc_html( $job_id )
		);
		echo '<pre style="white-space:pre-wrap;background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:480px;overflow:auto;">';
		echo esc_html( implode( "\n", $lines ) );
		echo '</pre>';
	}

	/**
	 * Reads up to `$max_bytes` from the end of a file.
	 *
	 * @param string $abs       Absolute, already-validated file path.
	 * @param int    $max_bytes Maximum number of trailing bytes to read.
	 * @return string
	 */
	private static function read_tail( $abs, $max_bytes ) {
		$size = filesize( $abs );
		if ( false === $size ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read.file_operations_fopen -- read-only tail over a path already validated as inside the protected logs directory.
		$handle = fopen( $abs, 'rb' );
		if ( false === $handle ) {
			return '';
		}

		$offset = max( 0, $size - $max_bytes );
		fseek( $handle, $offset );
		$contents = stream_get_contents( $handle );
		fclose( $handle );

		return is_string( $contents ) ? $contents : '';
	}

	/**
	 * Resolves a stored `log_path` to an absolute path, requiring it to sit inside the
	 * protected logs directory specifically (tighter than the generic uploads-root check).
	 *
	 * @param string $relative Uploads-relative path as stored on the job row.
	 * @return string Empty string when invalid or outside the logs directory.
	 */
	private static function resolve_log_absolute_path( $relative ) {
		$abs = WPMAR_MD_Writer::absolute_path_from_upload_relative( $relative );
		if ( '' === $abs ) {
			return '';
		}

		$logs_dir = WPMAR_Logger::logs_dir();
		if ( is_wp_error( $logs_dir ) ) {
			return '';
		}

		$real_logs_dir = realpath( $logs_dir );
		$real_abs      = realpath( $abs );

		if ( false === $real_logs_dir || false === $real_abs ) {
			return '';
		}

		$real_logs_dir_n = wp_normalize_path( trailingslashit( $real_logs_dir ) );
		$real_abs_n      = wp_normalize_path( $real_abs );

		if ( 0 !== strpos( $real_abs_n, $real_logs_dir_n ) ) {
			return '';
		}

		return $abs;
	}
}
