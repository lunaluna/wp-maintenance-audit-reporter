<?php
/**
 * Multisite rollup: audit every target blog, merge into one report on the main site.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates switch_to_blog loops and consolidated delivery.
 */
class WPMAR_Network_Runner {

	const LOCK_TRANSIENT = 'wpmar_network_run_lock';

	/**
	 * Executes a network rollup audit.
	 *
	 * @param array<string,mixed> $options Supported keys: dry, triggered_by, persist_snapshots, mail_qa_extra, same_setting, target_blog_id.
	 * @return array<string,mixed>
	 */
	public function run( array $options = array() ) {
		$defaults = array(
			'dry'               => false,
			'triggered_by'      => 'manual_network',
			'persist_snapshots' => false,
			'mail_qa_extra'     => '',
			'same_setting'      => false,
			'target_blog_id'    => 0,
		);
		$exec     = wp_parse_args( $options, $defaults );

		if ( ! empty( $exec['dry'] ) ) {
			return $this->handle_dry_run( $exec );
		}

		if ( ! WPMAR_Network_Settings::is_network_audit_enabled() ) {
			return array(
				'skipped' => true,
				'reason'  => 'network_audit_disabled',
			);
		}

		return WPMAR_Network::on_main_site(
			function () use ( $exec ) {
				return $this->run_on_main_site( $exec );
			}
		);
	}

	/**
	 * Resolves the list of blog IDs to audit based on exec options.
	 *
	 * Priority: target_blog_id > same_setting > all target sites.
	 *
	 * @param array<string,mixed>      $exec             Normalised options.
	 * @param array<string,mixed>|null $network_settings Optional preloaded network settings.
	 * @return array<int,int>
	 */
	protected static function resolve_blog_ids( array $exec, $network_settings = null ) {
		$target_id = absint( $exec['target_blog_id'] ?? 0 );
		if ( $target_id > 0 ) {
			return array( $target_id );
		}

		if ( ! empty( $exec['same_setting'] ) ) {
			return array( WPMAR_Network::main_site_id() );
		}

		return WPMAR_Network::target_blog_ids( $network_settings );
	}

	/**
	 * Network dry-run summary without persistence.
	 *
	 * @param array<string,mixed> $exec Normalised options.
	 * @return array<string,mixed>
	 */
	protected function handle_dry_run( array $exec = array() ) {
		$blog_ids = self::resolve_blog_ids( $exec );
		$rows     = array();

		foreach ( $blog_ids as $blog_id ) {
			WPMAR_Network::on_blog(
				$blog_id,
				function () use ( &$rows, $blog_id ) {
					$collector = new WPMAR_Data_Collector();
					$facts     = $collector->gather();
					$rows[]    = array(
						'blog_id'        => $blog_id,
						'site'           => sanitize_text_field( get_bloginfo( 'name' ) ),
						'home_url'       => home_url(),
						'core_version'   => sanitize_text_field( $facts['core']['version'] ?? '' ),
						'plugins_count'  => isset( $facts['plugins']['inventory'] ) && is_array( $facts['plugins']['inventory'] ) ? count( $facts['plugins']['inventory'] ) : 0,
						'themes_count'   => isset( $facts['themes']['inventory'] ) && is_array( $facts['themes']['inventory'] ) ? count( $facts['themes']['inventory'] ) : 0,
						'domain_allowed' => WPMAR_Domain_Gate::is_allowed(
							WPMAR_Domain_Gate::merge_network_gate_settings(
								WPMAR_Settings::get_all(),
								WPMAR_Network_Settings::get_all()
							)
						),
					);
				}
			);
		}

		$brevity = wp_json_encode(
			array(
				'network_rollup' => true,
				'sites'          => $rows,
			),
			JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE
		);

		if ( ! is_string( $brevity ) || '' === $brevity ) {
			$brevity = '{"error":"wpmar_network_dry_preview_encode_failed"}';
		}

		return array(
			'dry_brevity' => $brevity,
		);
	}

	/**
	 * Rollup body executed while switched to the main site.
	 *
	 * @param array<string,mixed> $exec Normalised options.
	 * @return array<string,mixed>
	 */
	protected function run_on_main_site( array $exec ) {
		if ( false !== get_site_transient( self::LOCK_TRANSIENT ) ) {
			return array(
				'skipped' => true,
				'reason'  => 'busy',
			);
		}
		set_site_transient( self::LOCK_TRANSIENT, 1, 20 * MINUTE_IN_SECONDS );

		$t0               = microtime( true );
		$network_settings = WPMAR_Network_Settings::get_all();
		$delivery         = WPMAR_Network_Settings::rollup_delivery_settings();
		$blog_ids         = self::resolve_blog_ids( $exec, $network_settings );
		$runner           = new WPMAR_Runner();
		$segments         = array();
		$persist          = self::should_persist_snapshots( $exec );

		try {
			foreach ( $blog_ids as $blog_id ) {
				$segment = WPMAR_Network::on_blog(
					$blog_id,
					function () use ( $runner, $network_settings, $persist ) {
						$gate_settings = WPMAR_Domain_Gate::merge_network_gate_settings(
							WPMAR_Settings::get_all(),
							$network_settings
						);

						return $runner->run_site_segment(
							array(
								'persist_snapshots' => $persist,
								'gate_settings'     => $gate_settings,
							)
						);
					}
				);

				if ( is_array( $segment ) ) {
					$segments[] = $segment;
				}
			}

			$client_body = WPMAR_Runner::merge_network_client_markup( $segments );
			$admin_body  = WPMAR_Runner::merge_network_operator_markup( $segments );

			$domain_ok_count = 0;
			$total_changes   = 0;
			$per_blog        = array();
			foreach ( $segments as $segment ) {
				if ( ! is_array( $segment ) ) {
					continue;
				}
				$bid = isset( $segment['blog_id'] ) ? absint( $segment['blog_id'] ) : 0;
				if ( $bid <= 0 ) {
					continue;
				}
				$ok = ! empty( $segment['domain_gate_ok'] );
				if ( $ok ) {
					++$domain_ok_count;
				}
				$changes          = isset( $segment['changelog_counts'] ) ? absint( $segment['changelog_counts'] ) : 0;
				$total_changes   += $changes;
				$per_blog[ $bid ] = array(
					'blog_id'   => $bid,
					'site_name' => isset( $segment['site_name'] ) ? sanitize_text_field( (string) $segment['site_name'] ) : '',
					'home_url'  => isset( $segment['home_url'] ) ? esc_url_raw( (string) $segment['home_url'] ) : '',
					'domain_ok' => $ok,
					'changes'   => $changes,
				);
			}

			$any_domain_ok = ( $domain_ok_count > 0 );
			$status_flag   = $any_domain_ok ? 'success' : 'skipped_domain';
			$duration_sec  = (int) max( round( microtime( true ) - $t0, 0 ), 0 );

			$md_relative = '';
			if ( $any_domain_ok && ! empty( $delivery['output']['md_enabled'] ) ) {
				$file_result = WPMAR_MD_Writer::write_markdown_file(
					sprintf( 'wpmar-network-report-%s', gmdate( 'YmdHis' ) ),
					$admin_body
				);
				if ( ! is_wp_error( $file_result ) && is_string( $file_result ) ) {
					$md_relative = $file_result;
				}
			}

			$payload_summary = wp_json_encode(
				array(
					'network_rollup'  => true,
					'blog_ids'        => array_values( array_map( 'absint', $blog_ids ) ),
					'sites_audited'   => count( $segments ),
					'sites_domain_ok' => $domain_ok_count,
					'changes'         => $total_changes,
					'domain_ok'       => $any_domain_ok,
					'per_blog'        => array_values( $per_blog ),
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
			);
			if ( false === $payload_summary ) {
				$payload_summary = '{}';
			}

			$mail_sent_flag = 0;
			if ( $any_domain_ok && ! empty( $delivery['mail']['enabled'] ) ) {
				$mail_sent_flag = WPMAR_Notifier_Mail::send_pair(
					$delivery,
					$client_body,
					$admin_body,
					array(),
					isset( $exec['mail_qa_extra'] ) ? (string) $exec['mail_qa_extra'] : ''
				)
					? 1
					: 0;
			}

			$report_repo = new WPMAR_Report_Repository();
			$row_id      = $report_repo->insert(
				array(
					'status'         => $status_flag,
					'triggered_by'   => sanitize_key( $exec['triggered_by'] ),
					'domain_matched' => $any_domain_ok ? 1 : 0,
					'mail_sent'      => $mail_sent_flag,
					'change_count'   => absint( $total_changes ),
					'duration_sec'   => $duration_sec,
					'summary_json'   => $payload_summary,
					'body_md'        => $admin_body,
					'body_client_md' => $client_body,
					'md_file_path'   => $md_relative,
				)
			);

			if ( $any_domain_ok && null !== $row_id ) {
				WPMAR_Notification_Dispatcher::dispatch(
					$delivery,
					array(
						'report_id'      => (int) $row_id,
						'body_client_md' => $client_body,
						'body_admin_md'  => $admin_body,
						'mail_sent'      => (bool) $mail_sent_flag,
						'triggered_by'   => sanitize_key( $exec['triggered_by'] ),
						'home_url'       => network_home_url(),
					)
				);
			}

			if ( null !== $row_id && $any_domain_ok && ! empty( $delivery['output']['pdf_enabled'] ) && WPMAR_PDF_Writer::is_available() ) {
				$pdf_rel = WPMAR_PDF_Writer::write_pdf_from_markdown(
					WPMAR_PDF_Writer::markdown_body_for_client_pdf(
						array(
							'body_client_md' => $client_body,
						)
					),
					'wpmar-network-report-' . (int) $row_id
				);
				if ( ! is_wp_error( $pdf_rel ) && is_string( $pdf_rel ) && '' !== $pdf_rel ) {
					$report_repo->update_pdf_file_path( (int) $row_id, $pdf_rel );
				}
			}

			$retention_months = isset( $delivery['retention']['months'] ) ? absint( $delivery['retention']['months'] ) : 12;
			if ( $retention_months > 0 && null !== $row_id ) {
				$report_repo->purge_older_than_months( $retention_months );
			}

			WPMAR_Scheduler::reschedule();

			update_site_option( 'wpmar_last_network_audit_completed_at', gmdate( 'c' ) );

			return array(
				'report_id'      => $row_id,
				'mail_sent'      => (bool) $mail_sent_flag,
				'status'         => $status_flag,
				'sites_audited'  => count( $segments ),
				'network_rollup' => true,
			);

		} finally {
			delete_site_transient( self::LOCK_TRANSIENT );
		}
	}

	/**
	 * Snapshot persistence policy for network rollup runs.
	 *
	 * @param array<string,mixed> $exec Options.
	 * @return bool
	 */
	protected static function should_persist_snapshots( array $exec ) {
		// Explicit false opt-out takes priority over any trigger default.
		if ( isset( $exec['persist_snapshots'] ) && false === $exec['persist_snapshots'] ) {
			return false;
		}

		$triggered = isset( $exec['triggered_by'] ) ? sanitize_key( (string) $exec['triggered_by'] ) : 'manual_network';
		if ( 'cron_network' === $triggered || 'cli_network' === $triggered ) {
			return true;
		}

		return ! empty( $exec['persist_snapshots'] );
	}
}
