<?php
/**
 * End-to-end orchestration: harvest, diff, persist, mail, reschedule.
 *
 * Cron, admin actions, and CLI all funnel through {@see self::run()} with different flags.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateful coordinator around repositories, notifier, and Markdown writer.
 */
class WPMAR_Runner {

	/**
	 * Executes audits according to behavioural flags.
	 *
	 * @param array<string,mixed> $options Supported keys: dry, triggered_by (manual|cron|cli|manual_test), mail_override.
	 * @return array<string,mixed>
	 */
	public function run( array $options = array() ) {
		$defaults = array(
			'dry'           => false,
			'triggered_by'  => 'manual',
			'mail_override' => '',
			'capture_cli'   => defined( 'WP_CLI' ) && WP_CLI,
		);

		$exec = wp_parse_args( $options, $defaults );

		if ( ! empty( $exec['capture_cli'] ) ) {
			WPMAR_CLI_Environment::maybe_capture();
		}

		// Dry-run is the only path that intentionally skips DB snapshots + report rows.
		if ( ! empty( $exec['dry'] ) ) {
			return $this->handle_dry_run( $exec );
		}

		// Simple mutex: concurrent runs (CLI + Cron overlap) should not clobber snapshots.
		if ( false !== get_transient( 'wpmar_run_lock' ) ) {
			return array(
				'skipped' => true,
				'reason'  => 'busy',
			);
		}

		set_transient( 'wpmar_run_lock', 1, 20 * MINUTE_IN_SECONDS );

		$t0 = microtime( true );

		try {
			$settings       = WPMAR_Settings::get_all();
			$data_collector = new WPMAR_Data_Collector();
			$dataset        = $data_collector->gather();

			// Hostname mismatch keeps staging installs from overwriting production snapshots.
			$domain_gate_ok = WPMAR_Domain_Gate::is_allowed( $settings );
			$pairs          = $this->canonical_snapshots_from_report( $dataset );

			// Pull previous JSON blobs prior to rewriting - drives diff headings in mail/MD.
			$snapshot_repo = new WPMAR_Snapshot_Repository();
			$prior_snap    = array();
			foreach ( array_keys( $pairs ) as $dimension ) {
				$prior_snap[ $dimension ] = $snapshot_repo->latest( $dimension );
			}

			list( $changelog_counts, $changelog_md ) = $this->difference_summary( $prior_snap, $pairs );

			if ( ! $domain_gate_ok ) {
				$pairs = array(); // Prevent polluting snapshots from unauthorised hosts.
			}

			$report_repo = new WPMAR_Report_Repository();
			$md_relative = '';

			if ( $domain_gate_ok ) {
				// Persist newest snapshot per dimension, prune older than two rows each.
				foreach ( $pairs as $type => $canonical ) {
					$snapshot_repo->save( $type, $canonical );
					$snapshot_repo->prune_keep( $type, 2 );
				}
			}

			$client_body = self::render_client_markup( $dataset, $changelog_md, $changelog_counts, $domain_gate_ok );
			$admin_body  = self::render_operator_markup( $dataset, $changelog_md, $domain_gate_ok, $changelog_counts );

			if ( $domain_gate_ok && ! empty( $settings['output']['md_enabled'] ) ) {
				// Uploaded Markdown mirrors the verbose admin-facing email payload.
				$slug_candidate = gmdate( 'YmdHis' );
				$file_result    = WPMAR_MD_Writer::write_markdown_file(
					sprintf( 'wpmar-report-%s', $slug_candidate ),
					$admin_body
				);
				if ( ! is_wp_error( $file_result ) && is_string( $file_result ) ) {
					$md_relative = $file_result;
				}
			}

			$status_flag = $domain_gate_ok ? 'success' : 'skipped_domain';

			$payload_summary = wp_json_encode(
				array(
					'changes'                => absint( $changelog_counts ),
					'domain_ok'              => $domain_gate_ok,
					'dataset_version'        => isset( $dataset['core']['version'] ) ? (string) $dataset['core']['version'] : '',
					'security_warning_count' => isset( $dataset['security']['warning_count'] ) ? absint( $dataset['security']['warning_count'] ) : 0,
					'security_summary'       => self::build_security_summary_line(
						isset( $dataset['security'] ) && is_array( $dataset['security'] ) ? $dataset['security'] : array()
					),
					'security_codes'         => isset( $dataset['security']['summary_codes'] ) && is_array( $dataset['security']['summary_codes'] )
						? array_values( array_map( 'strval', $dataset['security']['summary_codes'] ) )
						: array(),
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
			);

			if ( false === $payload_summary ) {
				$payload_summary = '{}';
			}

			// Mail intentionally precedes INSERT so mail_sent captures the factual dispatch result.
			$mail_sent_flag = 0;
			if ( $domain_gate_ok && ! empty( $settings['mail']['enabled'] ) ) {
				$mail_sent_flag = WPMAR_Notifier_Mail::send_pair(
					$settings,
					$client_body,
					$admin_body,
					isset( $exec['mail_override'] ) ? $exec['mail_override'] : array()
				)
					? 1
					: 0;
			}

			$row_id = $report_repo->insert(
				array(
					'status'         => $status_flag,
					'triggered_by'   => sanitize_key( $exec['triggered_by'] ),
					'domain_matched' => $domain_gate_ok ? 1 : 0,
					'mail_sent'      => $mail_sent_flag,
					'change_count'   => absint( $changelog_counts ),
					'duration_sec'   => (int) max( round( microtime( true ) - $t0, 0 ), 0 ),
					'summary_json'   => $payload_summary,
					'body_md'        => $admin_body,
					'md_file_path'   => $md_relative,
				)
			);

			$retention_months = isset( $settings['retention']['months'] ) ? absint( $settings['retention']['months'] ) : 12;
			if ( $retention_months > 0 && null !== $row_id ) {
				$report_repo->purge_older_than_months( $retention_months );
			}

			// Monthly single-event chaining is recalculated after every concrete run.
			WPMAR_Scheduler::reschedule();

			update_option( 'wpmar_last_audit_completed_at', gmdate( 'c' ), false );

			return array(
				'report_id' => $row_id,
				'mail_sent' => (bool) $mail_sent_flag,
				'status'    => $status_flag,
			);

		} finally {
			// Release mutex even when exceptions bubble - guard is best-effort only.
			delete_transient( 'wpmar_run_lock' );
		}
	}

	/**
	 * Validates critical paths during dry QA without persisting artefacts.
	 *
	 * @param array<string,mixed> $exec Normalised invocation flags.
	 * @return array<string,mixed>
	 */
	protected function handle_dry_run( array $exec ) {
		unset( $exec );

		WPMAR_CLI_Environment::maybe_capture();

		$collector = new WPMAR_Data_Collector();
		$facts     = $collector->gather();

		$tz           = wp_timezone();
		$brevity_data = array(
			'site'                   => sanitize_text_field( get_option( 'blogname' ) ),
			'dry_run_at'             => wp_date( 'Y-m-d H:i:s T', time(), $tz ),
			'dry_run_at_utc'         => gmdate( 'c' ),
			'core_version'           => sanitize_text_field( $facts['core']['version'] ?? '' ),
			'theme_count'            => isset( $facts['themes']['inventory'] ) && is_array( $facts['themes']['inventory'] ) ? count( $facts['themes']['inventory'] ) : 0,
			'plugins_count'          => isset( $facts['plugins']['inventory'] ) && is_array( $facts['plugins']['inventory'] ) ? count( $facts['plugins']['inventory'] ) : 0,
			'security_warning_count' => isset( $facts['security']['warning_count'] ) ? absint( $facts['security']['warning_count'] ) : 0,
			'security_summary'       => self::build_security_summary_line(
				isset( $facts['security'] ) && is_array( $facts['security'] ) ? $facts['security'] : array()
			),
		);

		$brevity_string = wp_json_encode(
			$brevity_data,
			JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE
		);

		if ( ! is_string( $brevity_string ) || '' === $brevity_string ) {
			$brevity_string = '{"error":"wpmar_dry_preview_encode_failed"}';
		}

		return array(
			'dry_preview' => $facts,
			'dry_brevity' => $brevity_string,
		);
	}

	/**
	 * Normalises payloads for associative diffing buckets.
	 *
	 * @param array<string,mixed> $facts Fresh dataset envelope.
	 * @return array<string,array<string,mixed>>
	 */
	protected function canonical_snapshots_from_report( array $facts ) {
		$core_snap = array(
			'version' => sanitize_text_field( $facts['core']['version'] ?? '' ),
			'locale'  => sanitize_text_field( $facts['core']['locale'] ?? '' ),
		);

		// Theme + plugin maps are intentionally slug => version for compact diffing.
		$t_map = array();
		if ( ! empty( $facts['themes']['inventory'] ) && is_array( $facts['themes']['inventory'] ) ) {
			foreach ( $facts['themes']['inventory'] as $entry ) {
				if ( isset( $entry['slug'], $entry['version'] ) ) {
					$t_map[ sanitize_key( $entry['slug'] ) ] = sanitize_text_field( $entry['version'] );
				}
			}
		}

		$p_map = array();
		if ( ! empty( $facts['plugins']['inventory'] ) && is_array( $facts['plugins']['inventory'] ) ) {
			foreach ( $facts['plugins']['inventory'] as $entry ) {
				if ( isset( $entry['slug'], $entry['version'] ) ) {
					$p_map[ sanitize_key( $entry['slug'] ) ] = sanitize_text_field( $entry['version'] );
				}
			}
		}

		// User rows collapse to a salted signature (email + roles) to surface account drift.
		$u_map = array();
		if ( ! empty( $facts['users'] ) && is_array( $facts['users'] ) ) {
			foreach ( $facts['users'] as $user_row ) {
				if ( isset( $user_row['id'] ) ) {
					$composite = strtolower( sanitize_email( isset( $user_row['email'] ) ? $user_row['email'] : '' ) ) . '|' . sanitize_text_field(
						$user_row['roles'] ?? ''
					);
					$u_map[ sanitize_key( (string) $user_row['id'] ) ] = $composite;
				}
			}
		}

		return array(
			'core'    => $core_snap,
			'themes'  => $t_map,
			'plugins' => $p_map,
			'users'   => $u_map,
		);
	}

	/**
	 * Produces changelog markdown + aggregated counter.
	 *
	 * @param array<string,?array<mixed,string>> $before Prior canonical maps.
	 * @param array<string,array<string,mixed>>  $fresh  Incoming canonical snapshots.
	 * @return array{0:int,1:string}
	 */
	protected function difference_summary( array $before, array $fresh ) {
		$tally     = 0;
		$fragments = array();

		// Without any historical JSON we defer meaningful arithmetic diffs until the second run.
		$has_prior = false;
		foreach ( array( 'core', 'themes', 'plugins', 'users' ) as $dimension ) {
			if ( ! empty( $before[ $dimension ] ) && is_array( $before[ $dimension ] ) ) {
				$has_prior = true;
				break;
			}
		}

		if ( ! $has_prior ) {
			$prior_message = __( '初めての収集です。次回実行時に差分が比較されます。', 'wp-maintenance-audit-reporter' );

			return array(
				0,
				'* ' . $prior_message . "\n",
			);
		}

		$historic_core = isset( $before['core'] ) && is_array( $before['core'] ) ? $before['core'] : array();
		$fresh_core    = isset( $fresh['core'] ) && is_array( $fresh['core'] ) ? $fresh['core'] : array();
		$core_before   = isset( $historic_core['version'] ) ? sanitize_text_field( (string) $historic_core['version'] ) : '';
		$core_after    = isset( $fresh_core['version'] ) ? sanitize_text_field( (string) $fresh_core['version'] ) : '';

		if ( '' !== $core_before && '' !== $core_after && $core_before !== $core_after ) {
			++$tally;
			$fragments[] = '* ' . sprintf(
				/* translators: 1 before version, 2 after version */
				__( 'WordPress コア: %1$s → %2$s', 'wp-maintenance-audit-reporter' ),
				$core_before,
				$core_after
			) . "\n";
		}

		foreach ( array( 'themes', 'plugins' ) as $entity ) {
			// Walk the symmetric difference of slug keys - captures install, upgrade, and removal events.
			$historic = isset( $before[ $entity ] ) && is_array( $before[ $entity ] ) ? $before[ $entity ] : array();
			$newmap   = isset( $fresh[ $entity ] ) && is_array( $fresh[ $entity ] ) ? $fresh[ $entity ] : array();

			$combined_ids = array_unique( array_merge( array_keys( $historic ), array_keys( $newmap ) ) );
			sort( $combined_ids );

			foreach ( $combined_ids as $slug ) {
				$historical_version = isset( $historic[ $slug ] ) ? (string) $historic[ $slug ] : '';
				$freshest_version   = isset( $newmap[ $slug ] ) ? (string) $newmap[ $slug ] : '';
				$item_label         = 'themes' === $entity ? __( 'テーマ', 'wp-maintenance-audit-reporter' ) : __( 'プラグイン', 'wp-maintenance-audit-reporter' );

				if ( '' === $historical_version ) {
					++$tally;
					$fragments[] = '* ' . sprintf(
						/* translators: 1 item type, 2 slug, 3 version */
						__( '新規 %1$s: %2$s (version %3$s)', 'wp-maintenance-audit-reporter' ),
						$item_label,
						sanitize_text_field( $slug ),
						sanitize_text_field( $freshest_version )
					) . "\n";
					continue;
				}

				if ( '' === $freshest_version ) {
					++$tally;
					$fragments[] = '* ' . sprintf(
						/* translators: 1 item type, 2 slug, 3 old version */
						__( '削除済み %1$s: %2$s (旧 version %3$s)', 'wp-maintenance-audit-reporter' ),
						$item_label,
						sanitize_text_field( $slug ),
						sanitize_text_field( $historical_version )
					) . "\n";
					continue;
				}

				if ( $historical_version !== $freshest_version ) {
					++$tally;
					$fragments[] = '* ' . sprintf(
						/* translators: 1 type, 2 slug, 3 old, 4 new */
						__( '%1$s %2$s: %3$s → %4$s', 'wp-maintenance-audit-reporter' ),
						$item_label,
						sanitize_text_field( $slug ),
						sanitize_text_field( $historical_version ),
						sanitize_text_field( $freshest_version )
					) . "\n";
				}
			}
		}

		if ( isset( $before['users'], $fresh['users'] ) && is_array( $before['users'] ) && is_array( $fresh['users'] ) ) {
			// Signatures originate from canonical_snapshots_from_report()'s hashed tuples.
			$historic_users = $before['users'];
			$new_users      = $fresh['users'];
			$id_union       = array_unique( array_merge( array_keys( $historic_users ), array_keys( $new_users ) ) );
			sort( $id_union );
			foreach ( $id_union as $user_id_slug ) {
				$historic_sig = isset( $historic_users[ $user_id_slug ] ) ? (string) $historic_users[ $user_id_slug ] : '';
				$fresh_sig    = isset( $new_users[ $user_id_slug ] ) ? (string) $new_users[ $user_id_slug ] : '';
				if ( $historic_sig === $fresh_sig ) {
					continue;
				}

				if ( '' === $historic_sig ) {
					++$tally;
					$delta_user = sprintf(
						/* translators: user id */
						__( 'ユーザー追加: #%d', 'wp-maintenance-audit-reporter' ),
						absint( $user_id_slug )
					);
					$fragments[] = '* ' . $delta_user . "\n";
					continue;
				}

				if ( '' === $fresh_sig ) {
					++$tally;
					$delta_user = sprintf(
						/* translators: user id */
						__( 'ユーザー削除: #%d', 'wp-maintenance-audit-reporter' ),
						absint( $user_id_slug )
					);
					$fragments[] = '* ' . $delta_user . "\n";
					continue;
				}

				++$tally;
				$delta_user = sprintf(
					/* translators: user id */
					__( 'ユーザー更新: #%d', 'wp-maintenance-audit-reporter' ),
					absint( $user_id_slug )
				);
				$fragments[] = '* ' . $delta_user . "\n";
			}
		}

		if ( empty( $fragments ) ) {
			$delta_none  = __( '差分は検出されませんでした。', 'wp-maintenance-audit-reporter' );
			$fragments[] = sprintf( "* %s\n", $delta_none );
		}

		return array(
			$tally,
			implode( '', $fragments ),
		);
	}

	/**
	 * Accessible Markdown copy for stakeholder mail + UI exports.
	 *
	 * @param array<string,mixed> $facts          Fresh dataset envelope.
	 * @param string              $changelog      Plaintext changelog diff.
	 * @param int                 $changelog_size Change counter.
	 * @param bool                $gate           Domain authorised flag.
	 * @return string
	 */
	public static function render_client_markup( array $facts, $changelog, $changelog_size, $gate ) {
		$title = __( 'サイト保守レポート', 'wp-maintenance-audit-reporter' );
		$copy  = __( '自動生成された読みやすい要約です。詳細ログはサイト管理者のみに送付されています。', 'wp-maintenance-audit-reporter' );
		$body  = '# ' . $title . "\n\n" . $copy . "\n\n";

		if ( ! $gate ) {
			$body .= __( 'ドメインチェックで停止したため、スナップショット更新とメール送信は行われませんでした。', 'wp-maintenance-audit-reporter' ) . "\n\n";
		}

		$body .= '## ' . __( 'チェンジカウント', 'wp-maintenance-audit-reporter' ) . ': ' . absint( $changelog_size ) . "\n\n";
		$body .= '## ' . __( '差分', 'wp-maintenance-audit-reporter' ) . "\n\n";
		$body .= wp_strip_all_tags( (string) $changelog ) . "\n\n";
		$body .= '## ' . __( 'チェックサム照合', 'wp-maintenance-audit-reporter' ) . "\n\n";
		$body .= self::render_checksum_client_section( isset( $facts['checksums'] ) && is_array( $facts['checksums'] ) ? $facts['checksums'] : array() );
		$body .= "\n\n";

		$body .= '## ' . __( '運用・セキュリティ', 'wp-maintenance-audit-reporter' ) . "\n\n";
		$body .= self::render_security_client_section(
			isset( $facts['security'] ) && is_array( $facts['security'] ) ? $facts['security'] : array()
		);
		$body .= "\n\n";

		$body .= '## ' . __( '利用可能な WordPress アップデート（コアのみ）', 'wp-maintenance-audit-reporter' ) . "\n\n";

		if ( empty( $facts['core']['available_updates'] ) ) {
			$body .= __( '通知なし。', 'wp-maintenance-audit-reporter' ) . "\n";
		} else {
			foreach ( (array) $facts['core']['available_updates'] as $core_line ) {
				$body .= '* ' . sanitize_text_field( (string) $core_line ) . "\n";
			}
		}

		return trim( $body );
	}

	/**
	 * One-line summary for JSON payloads and dry-run brevity.
	 *
	 * @param array<string,mixed> $security Security envelope.
	 * @return string
	 */
	public static function build_security_summary_line( array $security ) {
		$n = isset( $security['warning_count'] ) ? absint( $security['warning_count'] ) : 0;
		if ( $n <= 0 ) {
			return __( '運用セキュリティ上の追加警告は検出されませんでした（または対象外）。', 'wp-maintenance-audit-reporter' );
		}

		return sprintf(
			/* translators: %d: aggregate warning categories */
			__( '運用セキュリティ: %d 件の注意カテゴリがあります。詳細は管理者向けログを参照してください。', 'wp-maintenance-audit-reporter' ),
			$n
		);
	}

	/**
	 * Human bullets for stakeholder mail.
	 *
	 * @param array<string,mixed> $sec Security envelope.
	 * @return string
	 */
	protected static function render_security_client_section( array $sec ) {
		$lines   = array();
		$lines[] = self::build_security_summary_line( $sec );

		if ( ! empty( $sec['ssl'] ) && is_array( $sec['ssl'] ) ) {
			$st = isset( $sec['ssl']['status'] ) ? sanitize_key( (string) $sec['ssl']['status'] ) : '';
			if ( 'ok' === $st || 'not_applicable' === $st || 'skipped' === $st ) {
				$lines[] = '* ' . __( 'TLS 証明書: 追加の期限警告なし（または対象外）。', 'wp-maintenance-audit-reporter' );
			} else {
				$lines[] = '* ' . __( 'TLS 証明書: 要確認（詳細は管理者ログ）。', 'wp-maintenance-audit-reporter' );
			}
		}

		if ( ! empty( $sec['php_eol']['status'] ) && in_array( $sec['php_eol']['status'], array( 'warn', 'past_eol', 'unknown' ), true ) ) {
			$lines[] = '* ' . __( 'PHP バージョン/サポート: 要確認。', 'wp-maintenance-audit-reporter' );
		}

		$stack = isset( $sec['recommended_versions'] ) && is_array( $sec['recommended_versions'] ) ? $sec['recommended_versions'] : array();
		if ( ! empty( $stack['wordpress']['update_available'] ) || ! empty( $stack['php']['below_8_1'] ) || ! empty( $stack['mysql']['legacy'] ) ) {
			$lines[] = '* ' . __( 'コア/PHP/MySQL の推奨バージョン: 改善の余地あり。', 'wp-maintenance-audit-reporter' );
		}

		if ( ! empty( $sec['admin_activity']['stale_user_ids'] ) ) {
			$lines[] = '* ' . __( '管理者アカウント: 長期未ログインの可能性があります。', 'wp-maintenance-audit-reporter' );
		}

		if ( ! empty( $sec['debug']['production_debug_warn'] ) ) {
			$lines[] = '* ' . __( '本番環境でデバッグ定数が有効です。', 'wp-maintenance-audit-reporter' );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Human-readable checksum bullets for stakeholder mail.
	 *
	 * @param array<string,mixed> $checksum Envelope produced by {@see WPMAR_Check_Checksums::collect()}.
	 * @return string
	 */
	protected static function render_checksum_client_section( array $checksum ) {
		$core    = isset( $checksum['core'] ) && is_array( $checksum['core'] ) ? $checksum['core'] : array();
		$plugins = isset( $checksum['plugins'] ) && is_array( $checksum['plugins'] ) ? $checksum['plugins'] : array();

		$lines = array();

		if ( ! empty( $core ) ) {
			if ( ! empty( $core['error'] ) ) {
				$lines[] = '* ' . sprintf(
					/* translators: %s: error text */
					__( 'コア: API エラー (%s)', 'wp-maintenance-audit-reporter' ),
					sanitize_text_field( (string) $core['error'] )
				);
			} elseif ( empty( $core['manifest_ok'] ) ) {
				$lines[] = '* ' . __( 'コア: チェックサム一覧を取得できませんでした。', 'wp-maintenance-audit-reporter' );
			} else {
				$mismatch_n = isset( $core['mismatches'] ) && is_array( $core['mismatches'] ) ? count( $core['mismatches'] ) : 0;
				if ( ! empty( $core['ok'] ) && 0 === $mismatch_n ) {
					$lines[] = '* ' . __( 'コア: すべて一致しました。', 'wp-maintenance-audit-reporter' );
				} else {
					$lines[] = '* ' . sprintf(
						/* translators: %d: mismatch count */
						__( 'コア: 不一致または欠損 %d 件', 'wp-maintenance-audit-reporter' ),
						absint( $mismatch_n )
					);
				}

				$lines[] = '  * ' . sprintf(
					/* translators: 1: files checked, 2: files skipped (excluded) */
					__( '照合ファイル数: %1$d / 除外スキップ: %2$d', 'wp-maintenance-audit-reporter' ),
					absint( $core['checked_files'] ?? 0 ),
					absint( $core['skipped_files'] ?? 0 )
				);

				if ( ! empty( $core['manifest_locale_fallback'] ) && ! empty( $core['manifest_locale'] ) ) {
					$lines[] = '  * ' . sprintf(
						/* translators: 1: site locale (e.g. ja), 2: manifest locale used (typically en_US) */
						__( 'api.wordpress.org にサイト言語 (%1$s) 用のコアチェックサムが無かったため、%2$s のマニフェストで照合しました。', 'wp-maintenance-audit-reporter' ),
						sanitize_text_field( (string) ( $core['locale'] ?? '' ) ),
						sanitize_text_field( (string) ( $core['manifest_locale'] ?? 'en_US' ) )
					);
				}
			}
		}

		if ( ! empty( $plugins ) ) {
			foreach ( $plugins as $slug => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$slug_safe = sanitize_key( (string) $slug );
				$status    = isset( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : '';

				if ( 'no_checksums' === $status ) {
					$lines[] = '* ' . sprintf(
						/* translators: %s: plugin slug */
						__( 'プラグイン %s: チェックサム未提供（公式ディレクトリ外の可能性）', 'wp-maintenance-audit-reporter' ),
						$slug_safe
					);

					continue;
				}

				if ( 'error' === $status && ! empty( $row['error'] ) ) {
					$lines[] = '* ' . sprintf(
						/* translators: 1 slug, 2 error */
						__( 'プラグイン %1$s: エラー (%2$s)', 'wp-maintenance-audit-reporter' ),
						$slug_safe,
						sanitize_text_field( (string) $row['error'] )
					);

					continue;
				}

				$mismatch_n = isset( $row['mismatches'] ) && is_array( $row['mismatches'] ) ? count( $row['mismatches'] ) : 0;
				if ( 'ok' === $status && 0 === $mismatch_n ) {
					$lines[] = '* ' . sprintf(
						/* translators: %s slug */
						__( 'プラグイン %s: OK', 'wp-maintenance-audit-reporter' ),
						$slug_safe
					);
				} else {
					$lines[] = '* ' . sprintf(
						/* translators: 1 slug, 2 count */
						__( 'プラグイン %1$s: 不一致 %2$d 件', 'wp-maintenance-audit-reporter' ),
						$slug_safe,
						absint( $mismatch_n )
					);
				}
			}
		}

		if ( empty( $lines ) ) {
			return __( 'チェックサム情報がありません。', 'wp-maintenance-audit-reporter' );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Exhaustive plaintext export for admins + Markdown disk persistence.
	 *
	 * @param array<string,mixed> $facts          Fresh envelope.
	 * @param string              $changelog      Diff body.
	 * @param bool                $gate           Domain gate acknowledgement.
	 * @param int                 $changelog_size Counter.
	 * @return string
	 */
	public static function render_operator_markup( array $facts, $changelog, $gate, $changelog_size ) {
		// Mirrors the transient payload produced during gather(); can be sizable on large multisites.
		$json_block = wp_json_encode( $facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
		if ( false === $json_block ) {
			$json_block = '{}';
		}

		$intro = $gate
			? __( 'Domain gate authorised run.', 'wp-maintenance-audit-reporter' )
			: __( 'Domain gate blocked this invocation — snapshots were not mutated.', 'wp-maintenance-audit-reporter' );

		$header = sprintf(
			"# %s\n\n%s\n\n## %s: %d\n%s\n\n## RAW JSON snapshot\n",
			__( 'サイト保守レポート — 詳細ログ', 'wp-maintenance-audit-reporter' ),
			$intro,
			__( 'Diff items counted', 'wp-maintenance-audit-reporter' ),
			absint( $changelog_size ),
			wp_strip_all_tags( (string) $changelog )
		);

		return $header . $json_block . "\n";
	}
}
