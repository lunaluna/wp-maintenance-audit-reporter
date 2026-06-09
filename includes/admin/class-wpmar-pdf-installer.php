<?php
/**
 * Downloads and installs the mPDF vendor bundle from GitHub Releases.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles on-demand PDF library installation from the admin UI.
 */
class WPMAR_PDF_Installer {

	const AJAX_ACTION        = 'wpmar_install_pdf_library';
	const AJAX_PREFLIGHT     = 'wpmar_pdf_preflight';
	const AJAX_MANUAL_UPLOAD = 'wpmar_pdf_manual_upload';

	/**
	 * Registers Ajax hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_ajax' ) );
		add_action( 'wp_ajax_' . self::AJAX_PREFLIGHT, array( __CLASS__, 'handle_preflight_ajax' ) );
		add_action( 'wp_ajax_' . self::AJAX_MANUAL_UPLOAD, array( __CLASS__, 'handle_manual_upload_ajax' ) );
	}

	/**
	 * Whether the PDF vendor bundle is already present.
	 *
	 * @return bool
	 */
	public static function is_installed() {
		return (bool) apply_filters( 'wpmar_pdf_is_installed', WPMAR_PDF_Writer::is_available() );
	}

	/**
	 * URL of the vendor-pdf.zip asset on GitHub Releases.
	 * Override via the `wpmar_pdf_vendor_zip_url` filter if you host the bundle elsewhere.
	 *
	 * @return string
	 */
	public static function get_download_url() {
		return (string) apply_filters(
			'wpmar_pdf_vendor_zip_url',
			'https://github.com/lunaluna/wp-maintenance-audit-reporter/releases/download/' . WPMAR_VERSION . '/vendor-pdf.zip'
		);
	}

	/**
	 * Ajax endpoint: checks write permissions and disk space before downloading.
	 *
	 * @return void
	 */
	public static function handle_preflight_ajax() {
		check_ajax_referer( 'wpmar_pdf_installer', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( '権限がありません。', 'wp-maintenance-audit-reporter' ) ),
				403
			);
		}

		$result = self::preflight_check();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success();
	}

	/**
	 * Ajax endpoint: downloads and extracts the vendor bundle.
	 *
	 * @return void
	 */
	public static function handle_ajax() {
		check_ajax_referer( 'wpmar_pdf_installer', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( '権限がありません。', 'wp-maintenance-audit-reporter' ) ),
				403
			);
		}

		$result = self::install();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array( 'message' => __( 'PDF ライブラリのインストールが完了しました。ページを再読み込みします…', 'wp-maintenance-audit-reporter' ) )
		);
	}

	/**
	 * Ajax endpoint: receives a manually-uploaded vendor-pdf.zip and extracts it.
	 *
	 * Used when the automatic GitHub download is unavailable (firewall, network restriction, etc.).
	 *
	 * @return void
	 */
	public static function handle_manual_upload_ajax() {
		check_ajax_referer( 'wpmar_pdf_installer', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( '権限がありません。', 'wp-maintenance-audit-reporter' ) ),
				403
			);
		}

		// Validate that a file was actually sent.
		if ( empty( $_FILES['vendor_zip'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- binary upload; sanitizing would corrupt it.
			wp_send_json_error(
				array( 'message' => __( 'ファイルがアップロードされていません。', 'wp-maintenance-audit-reporter' ) )
			);
		}

		$error_code = isset( $_FILES['vendor_zip']['error'] ) ? (int) $_FILES['vendor_zip']['error'] : UPLOAD_ERR_NO_FILE; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( UPLOAD_ERR_OK !== $error_code ) {
			$message = UPLOAD_ERR_INI_SIZE === $error_code
				? __( 'ファイルサイズが PHP の upload_max_filesize 制限を超えています。サーバー設定をご確認ください。', 'wp-maintenance-audit-reporter' )
				: sprintf(
					/* translators: %d: PHP upload error code */
					__( 'ファイルのアップロードに失敗しました（エラーコード: %d）。', 'wp-maintenance-audit-reporter' ),
					$error_code
				);
			wp_send_json_error( array( 'message' => $message ) );
		}

		// Validate file extension.
		$file_name = isset( $_FILES['vendor_zip']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['vendor_zip']['name'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( 'zip' !== strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) ) ) {
			wp_send_json_error(
				array( 'message' => __( 'ZIP ファイルのみアップロード可能です。', 'wp-maintenance-audit-reporter' ) )
			);
		}

		// Verify the ZIP magic bytes (PK header) to reject non-zip files with a .zip extension.
		$tmp_path = isset( $_FILES['vendor_zip']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['vendor_zip']['tmp_name'] ) ) : '';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading 2 bytes from a server-side temp file; no HTTP request involved.
		$header = file_get_contents( $tmp_path, false, null, 0, 2 );
		if ( 'PK' !== $header ) {
			wp_send_json_error(
				array( 'message' => __( 'ファイルが有効な ZIP 形式ではありません。', 'wp-maintenance-audit-reporter' ) )
			);
		}

		// Re-run preflight before extracting.
		$preflight = self::preflight_check();
		if ( is_wp_error( $preflight ) ) {
			wp_send_json_error( array( 'message' => $preflight->get_error_message() ) );
		}

		$result = self::extract_zip( $tmp_path, WPMAR_PLUGIN_DIR );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Register newly extracted classes in the current request.
		$autoload = WPMAR_PLUGIN_DIR . 'vendor/autoload.php';
		if ( is_readable( $autoload ) && ! class_exists( '\Mpdf\Mpdf', false ) ) {
			require_once $autoload;
		}

		wp_send_json_success(
			array( 'message' => __( 'PDF ライブラリのインストールが完了しました。ページを再読み込みします…', 'wp-maintenance-audit-reporter' ) )
		);
	}

	/**
	 * Checks whether the server environment allows writing and has enough free space.
	 * Required free space: 150 MB (zip ~30 MB + extracted ~94 MB + margin).
	 *
	 * @return true|WP_Error
	 */
	private static function preflight_check() {
		$target = WPMAR_PLUGIN_DIR;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem has no equivalent for permission checks; PHP native is used widely in WP core for this purpose.
		if ( ! is_writable( $target ) ) {
			return new WP_Error(
				'wpmar_not_writable',
				sprintf(
					/* translators: %s: absolute path to the plugin directory */
					__( 'ディレクトリへの書き込み権限がありません: %s。FTP またはサーバー管理画面でディレクトリのパーミッションを 755 に変更してください。', 'wp-maintenance-audit-reporter' ),
					esc_html( $target )
				)
			);
		}

		$required = 150 * 1024 * 1024; // 150 MB
		$free     = function_exists( 'disk_free_space' ) ? disk_free_space( $target ) : false;
		if ( false !== $free && $free < $required ) {
			return new WP_Error(
				'wpmar_disk_full',
				sprintf(
					/* translators: 1: required size, 2: current free size */
					__( 'ディスクの空き容量が不足しています（必要: %1$s 以上、現在の空き: %2$s）。不要なファイルを削除してから再試行してください。', 'wp-maintenance-audit-reporter' ),
					size_format( $required ),
					size_format( $free )
				)
			);
		}

		return true;
	}

	/**
	 * Downloads the bundle zip and extracts it into the plugin directory.
	 *
	 * @return true|WP_Error
	 */
	private static function install() {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$url = self::get_download_url();
		$tmp = download_url( $url, 180 );

		if ( is_wp_error( $tmp ) ) {
			return new WP_Error(
				'wpmar_pdf_download_failed',
				sprintf(
					/* translators: %s: error message from wp_remote_get */
					__( 'ダウンロードに失敗しました: %s', 'wp-maintenance-audit-reporter' ),
					$tmp->get_error_message()
				)
			);
		}

		$result = self::extract_zip( $tmp, WPMAR_PLUGIN_DIR );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- temp file cleanup after download_url().
		@unlink( $tmp );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Register newly extracted classes in the current request.
		$autoload = WPMAR_PLUGIN_DIR . 'vendor/autoload.php';
		if ( is_readable( $autoload ) && ! class_exists( '\Mpdf\Mpdf', false ) ) {
			require_once $autoload;
		}

		return true;
	}

	/**
	 * Extracts a zip archive to a destination directory.
	 * Prefers ZipArchive; falls back to WP's unzip_file().
	 *
	 * @param string $zip_path    Absolute path to the zip file.
	 * @param string $destination Absolute directory to extract into.
	 * @return true|WP_Error
	 */
	private static function extract_zip( $zip_path, $destination ) {
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			if ( true !== $zip->open( $zip_path ) ) {
				return new WP_Error(
					'wpmar_zip_open',
					__( 'ZIP ファイルを開けませんでした。', 'wp-maintenance-audit-reporter' )
				);
			}
			$zip->extractTo( $destination );
			$zip->close();
			return true;
		}

		// Fallback: WordPress built-in (requires WP_Filesystem).
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		WP_Filesystem();

		$unzip_result = unzip_file( $zip_path, $destination );
		if ( is_wp_error( $unzip_result ) ) {
			return new WP_Error(
				'wpmar_zip_extract',
				sprintf(
					/* translators: %s: error message from unzip_file */
					__( 'ZIP の展開に失敗しました: %s', 'wp-maintenance-audit-reporter' ),
					$unzip_result->get_error_message()
				)
			);
		}

		return true;
	}

	/**
	 * Renders the PDF library status panel inside the settings page.
	 *
	 * @return void
	 */
	public static function render_panel() {
		$installed = self::is_installed();
		?>
		<div class="wpmar-section-panel" id="wpmar-pdf-library-panel">
			<h2><?php esc_html_e( 'PDF ライブラリ（mPDF）', 'wp-maintenance-audit-reporter' ); ?></h2>
			<?php if ( $installed ) : ?>
				<p>
					<span style="color:#0a7c00;font-weight:bold;">&#10003;</span>
					<?php esc_html_e( 'mPDF ライブラリはインストール済みです。PDF 出力が有効です。', 'wp-maintenance-audit-reporter' ); ?>
				</p>
			<?php else : ?>
				<p>
					<?php esc_html_e( 'PDF 出力には mPDF ライブラリ（展開後 約 94 MB）が必要です。ボタンを押すとライブラリを GitHub Releases からダウンロードし、このプラグインの vendor/ ディレクトリ配下に自動展開します。', 'wp-maintenance-audit-reporter' ); ?>
				</p>
				<p>
					<button type="button" class="button button-primary" id="wpmar-install-pdf-btn">
						<?php esc_html_e( 'PDF ライブラリをインストール', 'wp-maintenance-audit-reporter' ); ?>
					</button>
					<span id="wpmar-pdf-install-status" style="display:none;margin-left:10px;vertical-align:middle;"></span>
				</p>
				<p class="description">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: download URL shown as <code> */
							__( 'ダウンロード元: %s', 'wp-maintenance-audit-reporter' ),
							'<code>' . esc_html( self::get_download_url() ) . '</code>'
						),
						array( 'code' => array() )
					);
					?>
				</p>
				<p class="description">
					<?php esc_html_e( 'PDF ライブラリがインストールできない場合でも、クライアント向けレポートをマークダウン形式でダウンロードできます。', 'wp-maintenance-audit-reporter' ); ?>
				</p>
				<div id="wpmar-manual-upload-wrap" style="display:none;margin-top:1.5em;padding:1em;background:#fff8e1;border-left:4px solid #f0b849;">
					<p><strong><?php esc_html_e( '手動インストール', 'wp-maintenance-audit-reporter' ); ?></strong></p>
					<p class="description">
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %s: anchor tag linking to vendor-pdf.zip */
								__( 'サーバーからの自動ダウンロードに失敗した場合は、%s をお使いのパソコンにダウンロードし、以下からアップロードしてください。', 'wp-maintenance-audit-reporter' ),
								'<a href="' . esc_url( self::get_download_url() ) . '" target="_blank" rel="noopener noreferrer">vendor-pdf.zip</a>'
							),
							array(
								'a' => array(
									'href'   => array(),
									'target' => array(),
									'rel'    => array(),
								),
							)
						);
						?>
					</p>
					<p>
						<input type="file" id="wpmar-vendor-zip-file" accept=".zip" />
						<button type="button" class="button" id="wpmar-manual-upload-btn" style="margin-left:8px;">
							<?php esc_html_e( 'アップロードしてインストール', 'wp-maintenance-audit-reporter' ); ?>
						</button>
						<span id="wpmar-manual-upload-status" style="display:none;margin-left:10px;vertical-align:middle;"></span>
					</p>
				</div>
				<?php self::render_install_script(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Outputs the inline JS that drives the install button.
	 *
	 * @return void
	 */
	private static function render_install_script() {
		$nonce    = wp_create_nonce( 'wpmar_pdf_installer' );
		$messages = array(
			'checking'    => __( '環境を確認中…', 'wp-maintenance-audit-reporter' ),
			'downloading' => __( 'ダウンロード中… しばらくお待ちください（数分かかる場合があります）', 'wp-maintenance-audit-reporter' ),
			'uploading'   => __( 'アップロード・展開中… しばらくお待ちください', 'wp-maintenance-audit-reporter' ),
			'no_file'     => __( 'ZIP ファイルを選択してください。', 'wp-maintenance-audit-reporter' ),
			'timeout'     => __( 'タイムアウトしました。サーバーの PHP タイムアウト設定（max_execution_time）をご確認ください。', 'wp-maintenance-audit-reporter' ),
			'network'     => __( 'ネットワークエラーが発生しました。', 'wp-maintenance-audit-reporter' ),
			'fallback'    => __( 'インストールに失敗しました。', 'wp-maintenance-audit-reporter' ),
		);
		?>
		<script>
		(function ($) {
			var btn       = document.getElementById('wpmar-install-pdf-btn');
			var status    = document.getElementById('wpmar-pdf-install-status');
			var uploadBtn = document.getElementById('wpmar-manual-upload-btn');
			var uploadSt  = document.getElementById('wpmar-manual-upload-status');
			if (!btn) { return; }

			var nonce        = <?php echo wp_json_encode( $nonce ); ?>;
			var actionMain   = <?php echo wp_json_encode( self::AJAX_ACTION ); ?>;
			var actionPre    = <?php echo wp_json_encode( self::AJAX_PREFLIGHT ); ?>;
			var actionUpload = <?php echo wp_json_encode( self::AJAX_MANUAL_UPLOAD ); ?>;
			var msg          = {
				checking:    <?php echo wp_json_encode( $messages['checking'] ); ?>,
				downloading: <?php echo wp_json_encode( $messages['downloading'] ); ?>,
				uploading:   <?php echo wp_json_encode( $messages['uploading'] ); ?>,
				noFile:      <?php echo wp_json_encode( $messages['no_file'] ); ?>,
				timeout:     <?php echo wp_json_encode( $messages['timeout'] ); ?>,
				network:     <?php echo wp_json_encode( $messages['network'] ); ?>,
				fallback:    <?php echo wp_json_encode( $messages['fallback'] ); ?>
			};

			function setStatus(text, color) {
				status.style.display = '';
				status.style.color   = color || '';
				status.textContent   = text;
			}

			function setUploadStatus(text, color) {
				if (!uploadSt) { return; }
				uploadSt.style.display = '';
				uploadSt.style.color   = color || '';
				uploadSt.textContent   = text;
			}

			function showManualUpload() {
				var wrap = document.getElementById('wpmar-manual-upload-wrap');
				if (wrap) { wrap.style.display = ''; }
			}

			function doInstall() {
				setStatus(msg.downloading, '');
				$.ajax({
					url:     ajaxurl,
					method:  'POST',
					timeout: 240000,
					data: { action: actionMain, nonce: nonce },
					success: function (res) {
						if (res && res.success) {
							setStatus(res.data.message, '#0a7c00');
							setTimeout(function () { location.reload(); }, 2000);
						} else {
							var errMsg = (res && res.data && res.data.message)
								? res.data.message
								: msg.fallback;
							setStatus(errMsg, '#cc0000');
							btn.disabled = false;
							showManualUpload();
						}
					},
					error: function (xhr, statusStr) {
						var errMsg = ('timeout' === statusStr) ? msg.timeout : msg.network;
						setStatus(errMsg, '#cc0000');
						btn.disabled = false;
						showManualUpload();
					}
				});
			}

			btn.addEventListener('click', function () {
				btn.disabled = true;
				setStatus(msg.checking, '');

				$.ajax({
					url:    ajaxurl,
					method: 'POST',
					data:   { action: actionPre, nonce: nonce },
					success: function (res) {
						if (res && res.success) {
							doInstall();
						} else {
							var errMsg = (res && res.data && res.data.message)
								? res.data.message
								: msg.fallback;
							setStatus(errMsg, '#cc0000');
							btn.disabled = false;
						}
					},
					error: function () {
						setStatus(msg.network, '#cc0000');
						btn.disabled = false;
					}
				});
			});

			if (uploadBtn) {
				uploadBtn.addEventListener('click', function () {
					var fileInput = document.getElementById('wpmar-vendor-zip-file');
					if (!fileInput || !fileInput.files.length) {
						setUploadStatus(msg.noFile, '#cc0000');
						return;
					}

					uploadBtn.disabled = true;
					setUploadStatus(msg.uploading, '');

					var formData = new FormData();
					formData.append('action', actionUpload);
					formData.append('nonce', nonce);
					formData.append('vendor_zip', fileInput.files[0]);

					$.ajax({
						url:         ajaxurl,
						method:      'POST',
						data:        formData,
						processData: false,
						contentType: false,
						timeout:     120000,
						success: function (res) {
							if (res && res.success) {
								setUploadStatus(res.data.message, '#0a7c00');
								setTimeout(function () { location.reload(); }, 2000);
							} else {
								var errMsg = (res && res.data && res.data.message)
									? res.data.message
									: msg.fallback;
								setUploadStatus(errMsg, '#cc0000');
								uploadBtn.disabled = false;
							}
						},
						error: function (xhr, statusStr) {
							var errMsg = ('timeout' === statusStr) ? msg.timeout : msg.network;
							setUploadStatus(errMsg, '#cc0000');
							uploadBtn.disabled = false;
						}
					});
				});
			}
		}(jQuery));
		</script>
		<?php
	}
}
