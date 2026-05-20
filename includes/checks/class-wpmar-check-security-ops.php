<?php
/**
 * Operational security signals for maintenance reports (SSL, EOL, permissions, etc.).
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects non-shell security and hygiene facts for {@see WPMAR_Data_Collector}.
 */
class WPMAR_Check_Security_Ops {

	/**
	 * PHP branch EOL anchors (UTC calendar date Y-m-d). Refresh when PHP.net schedule changes.
	 *
	 * @return array<string,string>
	 */
	protected static function php_eol_dates() {
		return array(
			'7.4' => '2022-11-28',
			'8.0' => '2023-11-26',
			'8.1' => '2024-11-25',
			'8.2' => '2026-12-08',
			'8.3' => '2027-11-23',
			'8.4' => '2028-12-31',
		);
	}

	/**
	 * Builds the `security` envelope for the dataset.
	 *
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return array<string,mixed>
	 */
	public function collect( array $settings ) {
		$security_settings = isset( $settings['security'] ) && is_array( $settings['security'] ) ? $settings['security'] : array();

		$ssl_on = ! array_key_exists( 'ssl_check_enabled', $security_settings ) || ! empty( $security_settings['ssl_check_enabled'] );

		$out = array(
			'ssl'                  => $ssl_on ? $this->check_ssl_certificate() : array(
				'status' => 'skipped',
				'notes'  => array(
					__( '設定により SSL サーバー証明書の接続検査をスキップしました。', 'wp-maintenance-audit-reporter' ),
				),
			),
			'php_eol'              => $this->check_php_eol_branch(),
			'recommended_versions' => $this->check_recommended_stack(),
			'admin_activity'       => $this->check_administrator_activity( $settings ),
			'wp_config'            => $this->check_wp_config_permissions(),
			'debug'                => $this->check_debug_and_environment(),
			'warning_count'        => 0,
			'summary_codes'        => array(),
		);

		$out['warning_count'] = $this->compute_warning_count( $out );
		$out['summary_codes'] = $this->collect_summary_codes( $out );

		return $out;
	}

	/**
	 * Probes HTTPS host for certificate expiry (no shell).
	 *
	 * @return array<string,mixed>
	 */
	protected function check_ssl_certificate() {
		$home = home_url( '/' );
		$bits = wp_parse_url( $home );

		if ( ! is_array( $bits ) || empty( $bits['scheme'] ) || 'https' !== $bits['scheme'] ) {
			return array(
				'status' => 'not_applicable',
				'notes'  => array(
					__( 'サイト URL が https でないため、証明書期限の接続検査は行っていません。', 'wp-maintenance-audit-reporter' ),
				),
			);
		}

		$host = isset( $bits['host'] ) ? sanitize_text_field( (string) $bits['host'] ) : '';
		if ( '' === $host ) {
			return array(
				'status' => 'unknown',
				'notes'  => array( __( 'ホスト名を解決できませんでした。', 'wp-maintenance-audit-reporter' ) ),
			);
		}

		if ( ! extension_loaded( 'openssl' ) ) {
			return array(
				'status' => 'unknown',
				'notes'  => array( __( 'openssl 拡張が無効のため証明書を解釈できません。', 'wp-maintenance-audit-reporter' ) ),
			);
		}

		$port   = isset( $bits['port'] ) ? absint( $bits['port'] ) : 443;
		$target = sprintf( 'ssl://%s:%d', $host, $port );

		$ssl_opts = array(
			'capture_peer_cert' => true,
			'SNI_enabled'       => true,
			'peer_name'         => $host,
		);

		// First attempt with full certificate verification.
		$ctx = stream_context_create(
			array(
				'ssl' => array_merge(
					$ssl_opts,
					array(
						'verify_peer'      => true,
						'verify_peer_name' => true,
					)
				),
			)
		);
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_stream_socket_client -- First-pass errors are expected for expired/self-signed certs.
		$conn = @stream_socket_client( $target, $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $ctx );

		$verification_bypassed = false;
		if ( false === $conn ) {
			// Fallback without verification to capture expired or self-signed certificate details.
			$ctx = stream_context_create(
				array(
					'ssl' => array_merge(
						$ssl_opts,
						array(
							'verify_peer'      => false,
							'verify_peer_name' => false,
						)
					),
				)
			);
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_socket_client -- TLS peer capture without curl_exec.
			$conn                  = stream_socket_client( $target, $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $ctx );
			$verification_bypassed = ( false !== $conn );
		}

		if ( false === $conn ) {
			$msg = '' !== (string) $errstr
				? sprintf(
					/* translators: 1 errno, 2 error text */
					__( 'SSL 接続に失敗しました（%1$d %2$s）。オフロード TLS 環境では検査不能になることがあります。', 'wp-maintenance-audit-reporter' ),
					(int) $errno,
					sanitize_text_field( (string) $errstr )
				)
				: __( 'SSL 接続に失敗しました。', 'wp-maintenance-audit-reporter' );

			return array(
				'status' => 'unknown',
				'notes'  => array( $msg ),
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close TLS probe socket after handshake; WP_Filesystem not applicable.
		fclose( $conn );

		$params = stream_context_get_params( $ctx );
		$cert   = isset( $params['options']['ssl']['peer_certificate'] ) ? $params['options']['ssl']['peer_certificate'] : null;

		if ( ! $cert ) {
			return array(
				'status' => 'unknown',
				'notes'  => array( __( 'ピア証明書を取得できませんでした。', 'wp-maintenance-audit-reporter' ) ),
			);
		}

		$parsed = openssl_x509_parse( $cert, false );
		if ( ! is_array( $parsed ) || empty( $parsed['validTo_time_t'] ) ) {
			return array(
				'status' => 'unknown',
				'notes'  => array( __( '証明書の有効期限フィールドを解釈できませんでした。', 'wp-maintenance-audit-reporter' ) ),
			);
		}

		$expires_ts = (int) $parsed['validTo_time_t'];
		$now        = time();
		$days_left  = (int) floor( ( $expires_ts - $now ) / DAY_IN_SECONDS );

		$status = 'ok';
		$notes  = array();

		if ( $days_left < 0 ) {
			$status  = 'expired';
			$notes[] = __( '証明書の有効期限が切れています。', 'wp-maintenance-audit-reporter' );
		} elseif ( $days_left <= 14 ) {
			$status  = 'warn';
			$notes[] = sprintf(
				/* translators: %d days */
				__( '証明書の失効まで %d 日未満です。', 'wp-maintenance-audit-reporter' ),
				max( 0, $days_left )
			);
		} elseif ( $days_left <= 45 ) {
			$status  = 'warn';
			$notes[] = sprintf(
				/* translators: %d days */
				__( '証明書の更新時期が近いです（残りおおよそ %d 日）。', 'wp-maintenance-audit-reporter' ),
				$days_left
			);
		}

		if ( $verification_bypassed ) {
			$notes[] = __( 'SSL 証明書検証をバイパスして接続しました（証明書が期限切れまたは自己署名の可能性）。', 'wp-maintenance-audit-reporter' );
		}

		return array(
			'status'       => $status,
			'expires_gmt'  => gmdate( 'c', $expires_ts ),
			'days_left'    => $days_left,
			'subject_hash' => isset( $parsed['hash'] ) ? sanitize_text_field( (string) $parsed['hash'] ) : '',
			'notes'        => $notes,
		);
	}

	/**
	 * Flags PHP branches using a static EOL calendar.
	 *
	 * @return array<string,mixed>
	 */
	protected function check_php_eol_branch() {
		$branch   = self::normalize_php_branch( PHP_VERSION );
		$schedule = self::php_eol_dates();
		$out      = array(
			'branch'      => $branch,
			'current'     => PHP_VERSION,
			'eol_date'    => '',
			'status'      => 'unknown',
			'days_to_eol' => null,
			'notes'       => array(),
		);

		if ( ! isset( $schedule[ $branch ] ) ) {
			$out['notes'][] = __( 'この PHP 系列の EOL 日付マップが未定義です（プラグイン更新が必要な可能性があります）。', 'wp-maintenance-audit-reporter' );

			return $out;
		}

		$eol_str = $schedule[ $branch ];
		$eol_ts  = strtotime( $eol_str . ' UTC' );

		if ( false === $eol_ts ) {
			$out['notes'][] = __( 'EOL 日付の解析に失敗しました。', 'wp-maintenance-audit-reporter' );

			return $out;
		}

		$out['eol_date'] = gmdate( 'c', (int) $eol_ts );

		$now                = time();
		$days_gap           = (int) floor( ( $eol_ts - $now ) / DAY_IN_SECONDS );
		$out['days_to_eol'] = $days_gap;

		if ( $days_gap < 0 ) {
			$out['status']  = 'past_eol';
			$out['notes'][] = sprintf(
				/* translators: 1 branch, 2 ISO date */
				__( 'PHP %1$s はサポート終了日（%2$s）を過ぎています。', 'wp-maintenance-audit-reporter' ),
				$branch,
				$eol_str
			);
		} elseif ( $days_gap <= 180 ) {
			$out['status']  = 'warn';
			$out['notes'][] = sprintf(
				/* translators: 1 branch, 2 days */
				__( 'PHP %1$s のサポート終了が %2$d 日以内です。', 'wp-maintenance-audit-reporter' ),
				$branch,
				max( 0, $days_gap )
			);
		} else {
			$out['status'] = 'ok';
		}

		return $out;
	}

	/**
	 * WordPress / PHP / MySQL light-touch recommendations.
	 *
	 * @return array<string,mixed>
	 */
	protected function check_recommended_stack() {
		global $wpdb;

		$wp_ver = isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '';

		$core_updates = array();
		if ( function_exists( 'get_core_updates' ) ) {
			$core_updates = get_core_updates( array( 'dismissed' => false ) );
		}

		$pending_versions = WPMAR_Data_Collector::pending_core_upgrade_versions( $core_updates );
		$wp_warn          = ! empty( $pending_versions );

		$php_recommend = version_compare( PHP_VERSION, '8.1.0', '<' );

		$mysql_ver = '';
		if ( $wpdb instanceof wpdb && method_exists( $wpdb, 'db_version' ) ) {
			$mysql_ver = (string) $wpdb->db_version();
		}

		$mysql_warn = '' !== $mysql_ver && version_compare( $mysql_ver, '5.5.5', '<' );

		return array(
			'wordpress' => array(
				'version'          => $wp_ver,
				'update_available' => $wp_warn,
				'notes'            => $wp_warn
					? array( __( 'WordPress コアの更新が利用可能です。', 'wp-maintenance-audit-reporter' ) )
					: array(),
			),
			'php'       => array(
				'version'   => PHP_VERSION,
				'below_8_1' => $php_recommend,
				'notes'     => $php_recommend
					? array( __( 'PHP 8.1 以上への更新が推奨です（セキュリティと互換性）。', 'wp-maintenance-audit-reporter' ) )
					: array(),
			),
			'mysql'     => array(
				'version' => $mysql_ver,
				'legacy'  => $mysql_warn,
				'notes'   => $mysql_warn
					? array( __( 'データベースサーバーのバージョンが古すぎる可能性があります。', 'wp-maintenance-audit-reporter' ) )
					: array(),
			),
		);
	}

	/**
	 * Aggregates administrator session recency.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return array<string,mixed>
	 */
	protected function check_administrator_activity( array $settings ) {
		$sec        = isset( $settings['security'] ) && is_array( $settings['security'] ) ? $settings['security'] : array();
		$stale_days = isset( $sec['admin_stale_days'] ) ? absint( $sec['admin_stale_days'] ) : 90;
		if ( $stale_days < 30 ) {
			$stale_days = 30;
		}
		if ( $stale_days > 730 ) {
			$stale_days = 730;
		}

		$cutoff = time() - ( $stale_days * DAY_IN_SECONDS );

		$admins = get_users(
			array(
				'role'   => 'administrator',
				'fields' => array( 'ID', 'user_login' ),
			)
		);

		$rows      = array();
		$stale_ids = array();

		foreach ( $admins as $user ) {
			if ( ! isset( $user->ID ) ) {
				continue;
			}

			$uid   = absint( $user->ID );
			$login = sanitize_text_field( (string) $user->user_login );
			$last  = $this->latest_session_login_ts( $uid );

			$row = array(
				'user_id'    => $uid,
				'user_login' => $login,
				'last_seen'  => null,
			);

			if ( null !== $last ) {
				$row['last_seen'] = gmdate( 'c', $last );
				if ( $last < $cutoff ) {
					$stale_ids[] = $uid;
				}
			} else {
				$stale_ids[]  = $uid;
				$row['notes'] = __( '記録されたセッションがありません（長期未ログインの可能性）。', 'wp-maintenance-audit-reporter' );
			}

			$rows[] = $row;
		}

		return array(
			'stale_threshold_days' => $stale_days,
			'users'                => $rows,
			'stale_user_ids'       => $stale_ids,
		);
	}

	/**
	 * Returns latest WordPress session login timestamp for a user.
	 *
	 * @param int $user_id User ID.
	 * @return int|null Unix timestamp or null.
	 */
	protected function latest_session_login_ts( $user_id ) {
		$raw = get_user_meta( absint( $user_id ), 'session_tokens', true );

		if ( empty( $raw ) || ! is_array( $raw ) ) {
			return null;
		}

		$max = 0;

		foreach ( $raw as $payload ) {
			if ( ! is_array( $payload ) ) {
				continue;
			}

			if ( isset( $payload['login'] ) && is_numeric( $payload['login'] ) ) {
				$candidate = absint( $payload['login'] );
				if ( $candidate > $max ) {
					$max = $candidate;
				}
			}
		}

		return $max > 0 ? $max : null;
	}

	/**
	 * Locates wp-config.php candidates and inspects permission bits.
	 *
	 * @return array<string,mixed>
	 */
	protected function check_wp_config_permissions() {
		$paths = array();

		if ( defined( 'ABSPATH' ) ) {
			$paths[] = ABSPATH . 'wp-config.php';
			$parent  = dirname( ABSPATH );
			if ( $parent && ABSPATH !== $parent ) {
				$paths[] = $parent . '/wp-config.php';
			}
		}

		$paths   = array_values( array_unique( $paths ) );
		$checked = array();

		foreach ( $paths as $path ) {
			if ( ! is_string( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
				$checked[] = array(
					'path'   => $path,
					'status' => 'missing_or_unreadable',
				);
				continue;
			}

			$perms = fileperms( $path );
			if ( false === $perms ) {
				$checked[] = array(
					'path'   => $path,
					'status' => 'unknown',
				);
				continue;
			}

			$oct  = $perms & 0777;
			$warn = ( ( $oct & 0022 ) !== 0 );

			$checked[] = array(
				'path'         => $path,
				'perm_octal'   => sprintf( '0%o', $oct ),
				'warn_relaxed' => $warn,
				'status'       => $warn ? 'warn' : 'ok',
			);
		}

		return array(
			'candidates' => $checked,
		);
	}

	/**
	 * Production debug surface area.
	 *
	 * @return array<string,mixed>
	 */
	protected function check_debug_and_environment() {
		$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown';

		$wp_debug     = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$script_debug = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;

		$production = ( 'production' === $env );
		$prod_warn  = $production && ( $wp_debug || $script_debug );

		return array(
			'environment_type'      => sanitize_key( $env ),
			'wp_debug'              => (bool) $wp_debug,
			'script_debug'          => (bool) $script_debug,
			'production_debug_warn' => $prod_warn,
			'notes'                 => $prod_warn
				? array( __( '本番環境タイプで WP_DEBUG または SCRIPT_DEBUG が有効です。', 'wp-maintenance-audit-reporter' ) )
				: array(),
		);
	}

	/**
	 * Counts discrete warning facets in the security bundle.
	 *
	 * @param array<string,mixed> $bundle Partial tree.
	 * @return int
	 */
	protected function compute_warning_count( array $bundle ) {
		$n = 0;

		if ( isset( $bundle['ssl']['status'] ) && in_array( $bundle['ssl']['status'], array( 'warn', 'expired', 'unknown' ), true ) ) {
			++$n;
		}

		if ( isset( $bundle['php_eol']['status'] ) && in_array( $bundle['php_eol']['status'], array( 'warn', 'past_eol' ), true ) ) {
			++$n;
		}

		$stack = isset( $bundle['recommended_versions'] ) && is_array( $bundle['recommended_versions'] ) ? $bundle['recommended_versions'] : array();
		if ( ! empty( $stack['wordpress']['update_available'] ) ) {
			++$n;
		}
		if ( ! empty( $stack['php']['below_8_1'] ) ) {
			++$n;
		}
		if ( ! empty( $stack['mysql']['legacy'] ) ) {
			++$n;
		}

		$admins = isset( $bundle['admin_activity']['stale_user_ids'] ) && is_array( $bundle['admin_activity']['stale_user_ids'] )
			? $bundle['admin_activity']['stale_user_ids']
			: array();
		if ( ! empty( $admins ) ) {
			++$n;
		}

		$wpconf = isset( $bundle['wp_config']['candidates'] ) && is_array( $bundle['wp_config']['candidates'] ) ? $bundle['wp_config']['candidates'] : array();
		foreach ( $wpconf as $line ) {
			if ( is_array( $line ) && ! empty( $line['warn_relaxed'] ) ) {
				++$n;
				break;
			}
		}

		if ( ! empty( $bundle['debug']['production_debug_warn'] ) ) {
			++$n;
		}

		return $n;
	}

	/**
	 * Builds machine-readable tokens for summary_json rows.
	 *
	 * @param array<string,mixed> $bundle Full envelope.
	 * @return string[]
	 */
	protected function collect_summary_codes( array $bundle ) {
		$codes = array();

		if ( isset( $bundle['ssl']['status'] ) && 'ok' !== $bundle['ssl']['status'] && 'not_applicable' !== $bundle['ssl']['status'] && 'skipped' !== $bundle['ssl']['status'] ) {
			$codes[] = 'ssl';
		}
		if ( isset( $bundle['php_eol']['status'] ) && in_array( $bundle['php_eol']['status'], array( 'warn', 'past_eol' ), true ) ) {
			$codes[] = 'php_eol';
		}
		if ( ! empty( $bundle['recommended_versions']['wordpress']['update_available'] ) ) {
			$codes[] = 'wp_update';
		}
		if ( ! empty( $bundle['recommended_versions']['php']['below_8_1'] ) ) {
			$codes[] = 'php_old';
		}
		if ( ! empty( $bundle['recommended_versions']['mysql']['legacy'] ) ) {
			$codes[] = 'mysql_legacy';
		}
		if ( ! empty( $bundle['admin_activity']['stale_user_ids'] ) ) {
			$codes[] = 'admin_stale';
		}

		$wpconf = isset( $bundle['wp_config']['candidates'] ) ? $bundle['wp_config']['candidates'] : array();
		foreach ( $wpconf as $line ) {
			if ( is_array( $line ) && ! empty( $line['warn_relaxed'] ) ) {
				$codes[] = 'wp_config_perm';
				break;
			}
		}

		if ( ! empty( $bundle['debug']['production_debug_warn'] ) ) {
			$codes[] = 'debug_prod';
		}

		return array_values( array_unique( $codes ) );
	}

	/**
	 * Normalizes a PHP version string to major.minor.
	 *
	 * @param string $version PHP version string.
	 * @return string
	 */
	protected static function normalize_php_branch( $version ) {
		$parts = explode( '.', (string) $version );

		if ( count( $parts ) < 2 ) {
			return sanitize_text_field( (string) $version );
		}

		return sanitize_text_field( $parts[0] . '.' . $parts[1] );
	}
}
