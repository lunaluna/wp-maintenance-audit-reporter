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
	 * @param array<string,mixed> $options Supported keys: dry, triggered_by, persist_snapshots (null = trigger-derived default; cron_network/cli_network always save, manual_network does not), mail_qa_extra, same_setting, target_blog_id.
	 * @return array<string,mixed>
	 */
	public function run( array $options = array() ) {
		$defaults = array(
			'dry'               => false,
			'triggered_by'      => 'manual_network',
			// null (not false) so should_persist_snapshots() can tell "caller didn't say" apart
			// from "caller explicitly opted out" - see that method's docblock.
			'persist_snapshots' => null,
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
	 * Public so {@see WPMAR_Job_Dispatcher} can resolve the same target list at dispatch
	 * time, ahead of creating one segment row per blog.
	 *
	 * @param array<string,mixed>      $exec             Normalised options.
	 * @param array<string,mixed>|null $network_settings Optional preloaded network settings.
	 * @return array<int,int>
	 */
	public static function resolve_blog_ids( array $exec, $network_settings = null ) {
		$target_id = absint( $exec['target_blog_id'] ?? 0 );
		if ( $target_id > 0 ) {
			// Return empty array for a nonexistent blog ID to prevent switch_to_blog on a ghost site (e.g. via Cron path that bypasses UI validation).
			return get_blog_details( $target_id ) ? array( $target_id ) : array();
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

			$duration_sec = (int) max( round( microtime( true ) - $t0, 0 ), 0 );

			return self::finalize_rollup( $segments, $exec, $delivery, $blog_ids, $duration_sec );
		} finally {
			delete_site_transient( self::LOCK_TRANSIENT );
		}
	}

	/**
	 * Narrows the rollup's segments to the sites the report should cover.
	 *
	 * Deliberately applied here and nowhere else: every execution path (admin dry run,
	 * admin "run now", WP-Cron, WP-CLI --network, async job) converges on
	 * finalize_rollup(), so one filter covers all five with no way to miss one.
	 *
	 * This does NOT change which sites are audited. Every site still runs
	 * run_site_segment() and still updates its own wpmar_snapshots table, so a site
	 * excluded from the report keeps a fresh diff baseline and rejoins the report
	 * without a months-wide changelog. Scoping the *audit* is what
	 * `sites.exclude_blog_ids` does - a different setting, on purpose.
	 *
	 * @param array<int,array<string,mixed>> $segments         Per-site rows, see finalize_rollup().
	 * @param array<string,mixed>            $network_settings {@see WPMAR_Network_Settings::get_all()}.
	 * @return array<int,array<string,mixed>>
	 */
	protected static function filter_segments_for_report( array $segments, array $network_settings ) {
		if ( empty( $segments ) ) {
			return $segments;
		}

		$report = isset( $network_settings['report'] ) && is_array( $network_settings['report'] )
			? $network_settings['report']
			: WPMAR_Network_Settings::defaults()['report'];

		$scope = isset( $report['scope'] ) ? (string) $report['scope'] : 'all';

		if ( 'all' === $scope ) {
			return $segments;
		}

		$main_id  = WPMAR_Network::main_site_id();
		$selected = array();
		if ( 'main_and_selected' === $scope && ! empty( $report['blog_ids'] ) && is_array( $report['blog_ids'] ) ) {
			$selected = array_map( 'absint', $report['blog_ids'] );
		}

		$filtered = array();
		foreach ( $segments as $segment ) {
			if ( ! is_array( $segment ) || ! isset( $segment['blog_id'] ) ) {
				continue;
			}
			$bid = absint( $segment['blog_id'] );
			if ( $bid === $main_id || in_array( $bid, $selected, true ) ) {
				$filtered[] = $segment;
			}
		}

		if ( empty( $filtered ) ) {
			// A misconfigured scope (e.g. a main_only run where the main site's own
			// segment failed the domain gate) must not silently ship an empty report -
			// fall back to the unfiltered set and leave a trail for diagnosis.
			WPMAR_Logger::log(
				WPMAR_Logger::LEVEL_WARN,
				'report scope filter matched no segments, falling back to the full segment set',
				array(
					'scope' => $scope,
				)
			);
			return $segments;
		}

		return $filtered;
	}

	/**
	 * Merges per-site segment rows into one report: markup, mail, PDF, `wpmar_reports`
	 * insert, retention purge, and cron rescheduling.
	 *
	 * Split out of {@see self::run_on_main_site()} so {@see WPMAR_Job_Dispatcher::run_network_aggregate()}
	 * can run the exact same finish line once every site's independent async segment job
	 * has reached `done`/`failed`, instead of duplicating this logic for the per-site-job
	 * design. Every caller is expected to already be running on the main site (this
	 * writes to `wpmar_reports`, `wpmar_last_network_audit_completed_at`, etc., which -
	 * like {@see self::LOCK_TRANSIENT} - only have meaningful data on the main site).
	 *
	 * $segments accepts either shape interchangeably: the array
	 * {@see WPMAR_Runner::run_site_segment()} returns (synchronous path), or a
	 * `wpmar_network_segments` DB row (async aggregate path) - both carry the same
	 * `blog_id`/`site_name`/`home_url`/`domain_gate_ok`/`changelog_counts`/`client_body`/
	 * `admin_body` keys. A DB row additionally carries `status`; when it's `failed`,
	 * {@see WPMAR_Runner::merge_network_markup_segments()} renders the "this site errored"
	 * note instead of that site's (nonexistent) body.
	 *
	 * Applies {@see self::filter_segments_for_report()} to narrow which segments feed the
	 * markup/mail/per_blog summary. `sites_audited` and `blog_ids` deliberately keep
	 * counting/listing every audited site regardless of that filter - `report_blog_ids`
	 * in the summary JSON carries the narrowed list instead.
	 *
	 * @param array<int,array<string,mixed>> $segments     Per-site rows (see above).
	 * @param array<string,mixed>            $exec         Normalised run options; only `triggered_by`/`mail_qa_extra` are read here.
	 * @param array<string,mixed>            $delivery     {@see WPMAR_Network_Settings::rollup_delivery_settings()}.
	 * @param array<int,int>                 $blog_ids     Every blog id targeted by this run (for the summary JSON), not only the ones with a segment row.
	 * @param int                            $duration_sec Wall-clock seconds the overall run took, however the caller chooses to measure that.
	 * @return array<string,mixed>
	 */
	public static function finalize_rollup( array $segments, array $exec, array $delivery, array $blog_ids, $duration_sec ) {
		$exec = wp_parse_args(
			$exec,
			array(
				'triggered_by'  => 'cron_network',
				'mail_qa_extra' => '',
			)
		);

		$network_settings = WPMAR_Network_Settings::get_all();
		$report_segments  = self::filter_segments_for_report( $segments, $network_settings );

		$client_body = WPMAR_Runner::merge_network_client_markup( $report_segments );
		$admin_body  = WPMAR_Runner::merge_network_operator_markup( $report_segments );

		$domain_ok_count = 0;
		$total_changes   = 0;
		$per_blog        = array();
		$report_blog_ids = array();
		foreach ( $report_segments as $segment ) {
			if ( ! is_array( $segment ) ) {
				continue;
			}
			$bid = isset( $segment['blog_id'] ) ? absint( $segment['blog_id'] ) : 0;
			if ( $bid <= 0 ) {
				continue;
			}
			$report_blog_ids[] = $bid;
			$ok                = ! empty( $segment['domain_gate_ok'] );
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
				'status'    => isset( $segment['status'] ) ? sanitize_key( (string) $segment['status'] ) : 'done',
			);
		}
		$report_blog_ids = array_values( array_unique( $report_blog_ids ) );

		$any_domain_ok = ( $domain_ok_count > 0 );
		$status_flag   = $any_domain_ok ? 'success' : 'skipped_domain';

		$md_relative = '';
		if ( $any_domain_ok && ! empty( $delivery['output']['md_enabled'] ) ) {
			$domain_slug = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );
			if ( '' === $domain_slug ) {
				$domain_slug = 'site';
			}
			$file_result = WPMAR_MD_Writer::write_markdown_file(
				sprintf( 'wpmar-network-report-%s-admin-%s', $domain_slug, gmdate( 'Ymd-His' ) ),
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
				'report_blog_ids' => $report_blog_ids,
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
				'duration_sec'   => (int) max( 0, $duration_sec ),
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
			$domain_slug_pdf = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );
			if ( '' === $domain_slug_pdf ) {
				$domain_slug_pdf = 'site';
			}
			$pdf_rel = WPMAR_PDF_Writer::write_pdf_from_markdown(
				WPMAR_PDF_Writer::markdown_body_for_client_pdf(
					array(
						'body_client_md' => $client_body,
					)
				),
				sprintf( 'wpmar-network-report-%s-client-%s-%d', $domain_slug_pdf, gmdate( 'Ymd' ), (int) $row_id )
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
	}

	/**
	 * Snapshot persistence policy for network rollup runs.
	 *
	 * Public so {@see WPMAR_Job_Dispatcher} can apply the same policy once per dispatch
	 * and hand the single resulting flag to every per-site segment job, instead of each
	 * one re-deriving it from `$exec['triggered_by']` independently.
	 *
	 * `persist_snapshots` is tri-state: `null` (absent/unspecified) falls back to the
	 * trigger-derived default below; explicit `false` always opts out; any other explicit
	 * value is honoured as-is. See {@see WPMAR_Runner::should_persist_snapshots()} for the
	 * incident (c9b345e) this guards against.
	 *
	 * @param array<string,mixed> $exec Options.
	 * @return bool
	 */
	public static function should_persist_snapshots( array $exec ) {
		$persist_option = array_key_exists( 'persist_snapshots', $exec ) ? $exec['persist_snapshots'] : null;

		// Explicit false opt-out takes priority over any trigger default.
		if ( false === $persist_option ) {
			return false;
		}

		if ( null === $persist_option ) {
			$triggered = isset( $exec['triggered_by'] ) ? sanitize_key( (string) $exec['triggered_by'] ) : 'manual_network';
			return 'cron_network' === $triggered || 'cli_network' === $triggered;
		}

		return ! empty( $persist_option );
	}
}
