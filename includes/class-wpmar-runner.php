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
	 * @param array<string,mixed> $options Supported keys: dry, triggered_by (manual|cron|cli), mail_override, mail_qa_extra (optional duplicate client + admin copy to one address), persist_snapshots (manual only; cron/cli always save).
	 * @return array<string,mixed>
	 */
	public function run( array $options = array() ) {
		$defaults = array(
			'dry'               => false,
			'triggered_by'      => 'manual',
			'mail_override'     => '',
			'mail_qa_extra'     => '',
			'capture_cli'       => defined( 'WP_CLI' ) && WP_CLI,
			'persist_snapshots' => false,
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

			// Changelog compares TWO sides: (A) latest saved snapshot per dimension in DB, vs (B) this run's live
			// inventory from `gather()`. The checkbox / cron flag only decides whether we persist (B) back into
			// the snapshot table after the report — it does NOT change side (B), so each run always reflects
			// current files/state in the mail/MD bodies and in the diff's "after" side.

			// Pull previous JSON blobs prior to rewriting - drives diff headings in mail/MD.
			$snapshot_repo = new WPMAR_Snapshot_Repository();
			$prior_snap    = array();
			foreach ( array_keys( $pairs ) as $dimension ) {
				$prior_snap[ $dimension ] = $snapshot_repo->latest( $dimension );
			}

			$display_names = self::build_display_name_maps( $dataset );

			list( $changelog_counts, $changelog_md, $changelog_md_client ) = $this->difference_summary( $prior_snap, $pairs, $display_names );

			if ( ! $domain_gate_ok ) {
				$pairs = array(); // Prevent polluting snapshots from unauthorised hosts.
			}

			$persist_snapshots = self::should_persist_snapshots( $exec );

			$report_repo = new WPMAR_Report_Repository();
			$md_relative = '';

			if ( $domain_gate_ok && $persist_snapshots ) {
				// Persist newest snapshot per dimension, prune older than two rows each.
				foreach ( $pairs as $type => $canonical ) {
					$snapshot_repo->save( $type, $canonical );
					$snapshot_repo->prune_keep( $type, 2 );
				}
			}

			$duration_sec = (int) max( round( microtime( true ) - $t0, 0 ), 0 );

			$client_body = self::render_client_markup( $dataset, $changelog_md_client, $changelog_counts, $domain_gate_ok );
			$admin_body  = self::render_operator_markup( $dataset, $changelog_md, $domain_gate_ok, $changelog_counts, $duration_sec );

			if ( $domain_gate_ok && ! empty( $settings['output']['md_enabled'] ) ) {
				// Uploaded Markdown mirrors the verbose admin-facing email payload.
				$domain_slug = (string) wp_parse_url( home_url(), PHP_URL_HOST );
				if ( '' === $domain_slug ) {
					$domain_slug = 'site';
				}
				$slug_candidate = gmdate( 'Ymd-His' );
				$file_result    = WPMAR_MD_Writer::write_markdown_file(
					sprintf( 'wpmar-report-%s-admin-%s', $domain_slug, $slug_candidate ),
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
					isset( $exec['mail_override'] ) ? $exec['mail_override'] : array(),
					isset( $exec['mail_qa_extra'] ) ? (string) $exec['mail_qa_extra'] : ''
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
					'body_client_md' => $client_body,
					'md_file_path'   => $md_relative,
				)
			);

			if ( $domain_gate_ok && null !== $row_id ) {
				WPMAR_Notification_Dispatcher::dispatch(
					$settings,
					array(
						'report_id'      => (int) $row_id,
						'body_client_md' => $client_body,
						'body_admin_md'  => $admin_body,
						'mail_sent'      => (bool) $mail_sent_flag,
						'triggered_by'   => sanitize_key( $exec['triggered_by'] ),
						'home_url'       => home_url(),
					)
				);
			}

			if ( null !== $row_id && $domain_gate_ok && ! empty( $settings['output']['pdf_enabled'] ) && WPMAR_PDF_Writer::is_available() ) {
				$domain_slug_pdf = (string) wp_parse_url( home_url(), PHP_URL_HOST );
				if ( '' === $domain_slug_pdf ) {
					$domain_slug_pdf = 'site';
				}
				$pdf_rel = WPMAR_PDF_Writer::write_pdf_from_markdown(
					WPMAR_PDF_Writer::markdown_body_for_client_pdf(
						array(
							'body_client_md' => $client_body,
						)
					),
					sprintf( 'wpmar-report-%s-client-%s-%d', $domain_slug_pdf, gmdate( 'Ymd' ), (int) $row_id )
				);
				if ( ! is_wp_error( $pdf_rel ) && is_string( $pdf_rel ) && '' !== $pdf_rel ) {
					$report_repo->update_pdf_file_path( (int) $row_id, $pdf_rel );
				}
			}

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
	 * Collects, diffs, optionally persists snapshots, and renders bodies for the current blog only.
	 *
	 * Used by {@see WPMAR_Network_Runner}; does not insert reports, send mail, or reschedule cron.
	 *
	 * @param array<string,mixed> $options Keys: persist_snapshots, gate_settings (optional settings for domain gate).
	 * @return array<string,mixed>
	 */
	public function run_site_segment( array $options = array() ) {
		$defaults = array(
			'persist_snapshots' => true,
			'gate_settings'     => null,
		);
		$exec     = wp_parse_args( $options, $defaults );
		$t0       = microtime( true );

		$blog_id   = get_current_blog_id();
		$site_name = sanitize_text_field( get_bloginfo( 'name' ) );
		$home      = home_url();

		$settings = WPMAR_Settings::get_all();
		if ( is_array( $exec['gate_settings'] ) ) {
			$settings = $exec['gate_settings'];
		}

		$data_collector = new WPMAR_Data_Collector();
		$dataset        = $data_collector->gather();

		$domain_gate_ok = WPMAR_Domain_Gate::is_allowed( $settings );
		$pairs          = $this->canonical_snapshots_from_report( $dataset );

		$snapshot_repo = new WPMAR_Snapshot_Repository();
		$prior_snap    = array();
		foreach ( array_keys( $pairs ) as $dimension ) {
			$prior_snap[ $dimension ] = $snapshot_repo->latest( $dimension );
		}

		$display_names = self::build_display_name_maps( $dataset );

		list( $changelog_counts, $changelog_md, $changelog_md_client ) = $this->difference_summary( $prior_snap, $pairs, $display_names );

		if ( ! $domain_gate_ok ) {
			$pairs = array();
		}

		if ( $domain_gate_ok && ! empty( $exec['persist_snapshots'] ) ) {
			foreach ( $pairs as $type => $canonical ) {
				$snapshot_repo->save( $type, $canonical );
				$snapshot_repo->prune_keep( $type, 2 );
			}
		}

		$duration_sec = (int) max( round( microtime( true ) - $t0, 0 ), 0 );

		$client_body = self::render_client_markup( $dataset, $changelog_md_client, $changelog_counts, $domain_gate_ok );
		$admin_body  = self::render_operator_markup( $dataset, $changelog_md, $domain_gate_ok, $changelog_counts, $duration_sec );

		return array(
			'blog_id'          => (int) $blog_id,
			'site_name'        => $site_name,
			'home_url'         => esc_url_raw( $home ),
			'domain_gate_ok'   => $domain_gate_ok,
			'dataset'          => $dataset,
			'changelog_md'     => $changelog_md,
			'changelog_counts' => absint( $changelog_counts ),
			'client_body'      => $client_body,
			'admin_body'       => $admin_body,
			'duration_sec'     => $duration_sec,
		);
	}

	/**
	 * Merges per-site client bodies into one stakeholder Markdown document.
	 *
	 * @param array<int,array<string,mixed>> $segments Output of {@see self::run_site_segment()}.
	 * @return string
	 */
	public static function merge_network_client_markup( array $segments ) {
		return self::merge_network_markup_segments(
			$segments,
			'client',
			__( '# ネットワーク保守レポート（クライアント向け）', 'wp-maintenance-audit-reporter' )
		);
	}

	/**
	 * Merges per-site operator bodies into one administrator Markdown document.
	 *
	 * @param array<int,array<string,mixed>> $segments Segment rows.
	 * @return string
	 */
	public static function merge_network_operator_markup( array $segments ) {
		return self::merge_network_markup_segments(
			$segments,
			'admin',
			__( '# ネットワーク保守レポート（管理者向け）', 'wp-maintenance-audit-reporter' )
		);
	}

	/**
	 * Joins per-site Markdown segments under a network title.
	 *
	 * @param array<int,array<string,mixed>> $segments  Per-site rows.
	 * @param string                         $audience  client|admin.
	 * @param string                         $title     Document title line.
	 * @return string
	 */
	protected static function merge_network_markup_segments( array $segments, $audience, $title ) {
		$blocks = array();

		foreach ( $segments as $segment ) {
			if ( ! is_array( $segment ) ) {
				continue;
			}

			$site_name = isset( $segment['site_name'] ) ? sanitize_text_field( (string) $segment['site_name'] ) : '';
			$home_url  = isset( $segment['home_url'] ) ? esc_url_raw( (string) $segment['home_url'] ) : '';
			$blog_id   = isset( $segment['blog_id'] ) ? absint( $segment['blog_id'] ) : 0;

			$heading = sprintf(
				"## %s (%s)\n\n",
				'' !== $site_name ? $site_name : sprintf( 'Blog #%d', $blog_id ),
				'' !== $home_url ? $home_url : '—'
			);

			if ( empty( $segment['domain_gate_ok'] ) ) {
				$blocks[] = $heading . __( '※ ドメインゲートにより、このサイトの監査結果は保存・通知対象外として扱われました。', 'wp-maintenance-audit-reporter' );
				continue;
			}

			$body_key = ( 'admin' === $audience ) ? 'admin_body' : 'client_body';
			$body     = isset( $segment[ $body_key ] ) ? trim( (string) $segment[ $body_key ] ) : '';
			if ( '' === $body ) {
				continue;
			}

			$blocks[] = $heading . $body;
		}

		if ( empty( $blocks ) ) {
			return $title . "\n\n" . __( '対象サイトからレポート本文を生成できませんでした。', 'wp-maintenance-audit-reporter' ) . "\n";
		}

		return $title . "\n\n" . implode( "\n\n---\n\n", $blocks ) . "\n";
	}

	/**
	 * Whether to write snapshot rows: WP-Cron always; CLI always; manual paths only when opted in.
	 *
	 * @param array<string,mixed> $exec Normalised {@see self::run()} options.
	 * @return bool
	 */
	protected static function should_persist_snapshots( array $exec ) {
		// Explicit false opt-out takes priority over any trigger default.
		if ( isset( $exec['persist_snapshots'] ) && false === $exec['persist_snapshots'] ) {
			return false;
		}

		$triggered = isset( $exec['triggered_by'] ) ? sanitize_key( (string) $exec['triggered_by'] ) : 'manual';
		if ( 'cron' === $triggered || 'cron_network' === $triggered ) {
			return true;
		}
		// Unattended CLI runs mirror legacy “always persist” behaviour.
		if ( 'cli' === $triggered || 'cli_network' === $triggered ) {
			return true;
		}

		return ! empty( $exec['persist_snapshots'] );
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
	 * Builds slug => display-name maps from the live inventory.
	 *
	 * Client-facing copy prefers human display names; snapshots stay slug-keyed for compact diffing.
	 *
	 * @param array<string,mixed> $facts Fresh dataset envelope from {@see WPMAR_Data_Collector::gather()}.
	 * @return array{themes:array<string,string>,plugins:array<string,string>}
	 */
	protected static function build_display_name_maps( array $facts ) {
		$themes  = array();
		$plugins = array();

		if ( ! empty( $facts['themes']['inventory'] ) && is_array( $facts['themes']['inventory'] ) ) {
			foreach ( $facts['themes']['inventory'] as $e ) {
				if ( is_array( $e ) && ! empty( $e['slug'] ) && ! empty( $e['name'] ) ) {
					$themes[ sanitize_key( $e['slug'] ) ] = sanitize_text_field( $e['name'] );
				}
			}
		}

		if ( ! empty( $facts['plugins']['inventory'] ) && is_array( $facts['plugins']['inventory'] ) ) {
			foreach ( $facts['plugins']['inventory'] as $e ) {
				if ( is_array( $e ) && ! empty( $e['slug'] ) && ! empty( $e['title'] ) ) {
					$plugins[ sanitize_key( $e['slug'] ) ] = sanitize_text_field( $e['title'] );
				}
			}
		}

		return array(
			'themes'  => $themes,
			'plugins' => $plugins,
		);
	}

	/**
	 * Produces changelog markdown + aggregated counter.
	 *
	 * Returns two markdown bodies: one slug-keyed for operators, one display-name-keyed for clients.
	 *
	 * @param array<string,?array<mixed,string>> $before Prior canonical maps.
	 * @param array<string,array<string,mixed>>  $fresh  Incoming canonical snapshots.
	 * @param array<string,array<string,string>> $names  slug => display-name maps (themes, plugins).
	 * @return array{0:int,1:string,2:string}
	 */
	protected function difference_summary( array $before, array $fresh, array $names = array() ) {
		$tally            = 0;
		$fragments        = array();
		$client_fragments = array();

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
			$prior_line    = '* ' . $prior_message . "\n";

			return array(
				0,
				$prior_line,
				$prior_line,
			);
		}

		$historic_core = isset( $before['core'] ) && is_array( $before['core'] ) ? $before['core'] : array();
		$fresh_core    = isset( $fresh['core'] ) && is_array( $fresh['core'] ) ? $fresh['core'] : array();
		$core_before   = isset( $historic_core['version'] ) ? sanitize_text_field( (string) $historic_core['version'] ) : '';
		$core_after    = isset( $fresh_core['version'] ) ? sanitize_text_field( (string) $fresh_core['version'] ) : '';

		if ( '' !== $core_before && '' !== $core_after && $core_before !== $core_after ) {
			++$tally;
			$core_line = '* ' . sprintf(
				/* translators: 1 before version, 2 after version */
				__( 'WordPress コア: %1$s → %2$s', 'wp-maintenance-audit-reporter' ),
				$core_before,
				$core_after
			) . "\n";

			$fragments[]        = $core_line;
			$client_fragments[] = $core_line;
		}

		foreach ( array( 'themes', 'plugins' ) as $entity ) {
			// Walk the symmetric difference of slug keys - captures install, upgrade, and removal events.
			$historic = isset( $before[ $entity ] ) && is_array( $before[ $entity ] ) ? $before[ $entity ] : array();
			$newmap   = isset( $fresh[ $entity ] ) && is_array( $fresh[ $entity ] ) ? $fresh[ $entity ] : array();

			$combined_ids = array_unique( array_merge( array_keys( $historic ), array_keys( $newmap ) ) );
			sort( $combined_ids );

			$name_map = isset( $names[ $entity ] ) && is_array( $names[ $entity ] ) ? $names[ $entity ] : array();

			foreach ( $combined_ids as $slug ) {
				$historical_version = isset( $historic[ $slug ] ) ? (string) $historic[ $slug ] : '';
				$freshest_version   = isset( $newmap[ $slug ] ) ? (string) $newmap[ $slug ] : '';
				$item_label         = 'themes' === $entity ? __( 'テーマ', 'wp-maintenance-audit-reporter' ) : __( 'プラグイン', 'wp-maintenance-audit-reporter' );

				$slug_safe = sanitize_text_field( $slug );
				// Client copy prefers the display name; falls back to slug (e.g. removed items absent from inventory).
				$display = isset( $name_map[ $slug ] ) && '' !== $name_map[ $slug ] ? $name_map[ $slug ] : $slug_safe;

				if ( '' === $historical_version ) {
					++$tally;
					/* translators: 1 item type, 2 slug/name, 3 version */
					$tmpl               = __( '新規 %1$s: %2$s (version %3$s)', 'wp-maintenance-audit-reporter' );
					$fragments[]        = '* ' . sprintf( $tmpl, $item_label, $slug_safe, sanitize_text_field( $freshest_version ) ) . "\n";
					$client_fragments[] = '* ' . sprintf( $tmpl, $item_label, $display, sanitize_text_field( $freshest_version ) ) . "\n";
					continue;
				}

				if ( '' === $freshest_version ) {
					++$tally;
					/* translators: 1 item type, 2 slug/name, 3 old version */
					$tmpl               = __( '削除済み %1$s: %2$s (旧 version %3$s)', 'wp-maintenance-audit-reporter' );
					$fragments[]        = '* ' . sprintf( $tmpl, $item_label, $slug_safe, sanitize_text_field( $historical_version ) ) . "\n";
					$client_fragments[] = '* ' . sprintf( $tmpl, $item_label, $display, sanitize_text_field( $historical_version ) ) . "\n";
					continue;
				}

				if ( $historical_version !== $freshest_version ) {
					++$tally;
					/* translators: 1 type, 2 slug/name, 3 old, 4 new */
					$tmpl               = __( '%1$s %2$s: %3$s → %4$s', 'wp-maintenance-audit-reporter' );
					$fragments[]        = '* ' . sprintf( $tmpl, $item_label, $slug_safe, sanitize_text_field( $historical_version ), sanitize_text_field( $freshest_version ) ) . "\n";
					$client_fragments[] = '* ' . sprintf( $tmpl, $item_label, $display, sanitize_text_field( $historical_version ), sanitize_text_field( $freshest_version ) ) . "\n";
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
					$fragments[]        = '* ' . $delta_user . "\n";
					$client_fragments[] = '* ' . $delta_user . "\n";
					continue;
				}

				if ( '' === $fresh_sig ) {
					++$tally;
					$delta_user = sprintf(
						/* translators: user id */
						__( 'ユーザー削除: #%d', 'wp-maintenance-audit-reporter' ),
						absint( $user_id_slug )
					);
					$fragments[]        = '* ' . $delta_user . "\n";
					$client_fragments[] = '* ' . $delta_user . "\n";
					continue;
				}

				++$tally;
				$delta_user = sprintf(
					/* translators: user id */
					__( 'ユーザー更新: #%d', 'wp-maintenance-audit-reporter' ),
					absint( $user_id_slug )
				);
				$fragments[]        = '* ' . $delta_user . "\n";
				$client_fragments[] = '* ' . $delta_user . "\n";
			}
		}

		if ( empty( $fragments ) ) {
			$delta_none         = __( '差分は検出されませんでした。', 'wp-maintenance-audit-reporter' );
			$delta_none_line    = sprintf( "* %s\n", $delta_none );
			$fragments[]        = $delta_none_line;
			$client_fragments[] = $delta_none_line;
		}

		return array(
			$tally,
			implode( '', $fragments ),
			implode( '', $client_fragments ),
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
		// Mirror `maintenance-scripts` client mail: intro, # 【変更履歴】, pending updates, outdated plugins, # 【ユーザー情報】, then plugin extras.
		$body = __( '※こちらは定期メンテナンスのご報告です。ご確認ください。', 'wp-maintenance-audit-reporter' ) . "\n\n";

		if ( ! $gate ) {
			$body .= __( 'ドメインチェックで停止したため、スナップショット更新とメール送信は行われませんでした。', 'wp-maintenance-audit-reporter' ) . "\n\n";
		}

		$body .= self::render_client_change_history_shell_style( $changelog, $changelog_size );
		$body .= self::render_client_pending_updates_shell_style( $facts );
		$body .= self::render_client_outdated_plugins_shell_style( $facts );

		$body .= self::render_client_publishers_shell_style( $facts );

		$client_name_maps = self::build_display_name_maps( $facts );

		$body .= '## ' . __( 'ファイル改ざんチェック', 'wp-maintenance-audit-reporter' ) . "\n\n";
		$body .= self::render_checksum_client_section(
			isset( $facts['checksums'] ) && is_array( $facts['checksums'] ) ? $facts['checksums'] : array(),
			$client_name_maps['plugins']
		);
		$body .= "\n\n";

		$body .= '## ' . __( '運用・セキュリティ', 'wp-maintenance-audit-reporter' ) . "\n\n";
		$body .= self::render_security_client_section(
			isset( $facts['security'] ) && is_array( $facts['security'] ) ? $facts['security'] : array()
		);
		$body .= "\n\n";

		$body .= '## ' . __( 'オプション：データベースサイズチェック', 'wp-maintenance-audit-reporter' ) . "\n\n";
		$body .= self::render_performance_client_section(
			isset( $facts['performance'] ) && is_array( $facts['performance'] ) ? $facts['performance'] : array()
		);
		$body .= "\n\n";

		$body .= self::filtered_report_sections_markdown(
			array(
				'audience'       => 'client',
				'facts'          => $facts,
				'changelog'      => $changelog,
				'changelog_size' => $changelog_size,
				'domain_gate_ok' => $gate,
			)
		);

		return trim( $body );
	}

	/**
	 * `# 【変更履歴】` block shaped like maintenance-scripts `CHANGE_LOG`.
	 *
	 * @param string $changelog      Strip-taggable diff body.
	 * @param int    $changelog_size Count of diff items.
	 * @return string
	 */
	protected static function render_client_change_history_shell_style( $changelog, $changelog_size ) {
		$raw = trim( wp_strip_all_tags( (string) $changelog ) );
		$out = '# 【変更履歴】' . "\n\n";

		if ( preg_match( '/初めての収集/u', $raw ) ) {
			return $out . $raw . "\n\n";
		}

		if ( 0 === (int) $changelog_size || preg_match( '/差分は検出されませんでした/u', $raw ) ) {
			return $out . __( '今回の月次保守ではコア・テーマ・プラグインのアップデートや変更はありませんでした。', 'wp-maintenance-audit-reporter' ) . "\n\n";
		}

		return $out . $raw . "\n\n";
	}

	/**
	 * Separator + pending core/theme/plugin updates, matching `maintenance-scripts` `PENDING_UPDATES_SECTION`.
	 *
	 * @param array<string,mixed> $facts Dataset from {@see WPMAR_Data_Collector::gather()}.
	 * @return string
	 */
	protected static function render_client_pending_updates_shell_style( array $facts ) {
		$sep = "\n- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -\n\n";

		$core_lines = array();
		if ( ! empty( $facts['core']['available_updates'] ) && is_array( $facts['core']['available_updates'] ) ) {
			foreach ( $facts['core']['available_updates'] as $ver ) {
				$ver = sanitize_text_field( (string) $ver );
				if ( '' === $ver ) {
					continue;
				}
				$core_lines[] = sprintf(
					/* translators: %s: pending WordPress version */
					__( '* WordPress コアには新しいバージョン %s があります。', 'wp-maintenance-audit-reporter' ),
					$ver
				);
			}
		}

		$theme_lines  = self::collect_pending_theme_update_lines();
		$plugin_lines = self::collect_pending_plugin_update_lines();

		$has_any = ! empty( $core_lines ) || ! empty( $theme_lines ) || ! empty( $plugin_lines );

		$body = $sep;
		if ( ! $has_any ) {
			$body .= __( 'いまお使いのコア・テーマ・プラグインはすべて最新バーションです', 'wp-maintenance-audit-reporter' ) . "\n\n";

			return $body;
		}

		if ( ! empty( $core_lines ) ) {
			$body .= '#【現在アップデートがある WordPress 本体】' . "\n\n";
			$body .= __( '※ なるべく早くアップデートを実施してください', 'wp-maintenance-audit-reporter' ) . "\n\n";
			$body .= implode( "\n", $core_lines ) . "\n\n";
		}

		if ( ! empty( $theme_lines ) ) {
			$n     = count( $theme_lines );
			$body .= sprintf(
				/* translators: %d: count of themes with updates */
				'#【現在アップデートがあるテーマ / %d件】',
				$n
			) . "\n\n";
			$body .= __( '※ なるべく早くアップデートを実施してください', 'wp-maintenance-audit-reporter' ) . "\n\n";
			$body .= implode( "\n", $theme_lines ) . "\n\n";
		}

		if ( ! empty( $plugin_lines ) ) {
			$n     = count( $plugin_lines );
			$body .= sprintf(
				/* translators: %d: count of plugins with updates */
				'#【現在アップデートがあるプラグイン / %d件】',
				$n
			) . "\n\n";
			$body .= __( '※ なるべく早くアップデートを実施してください', 'wp-maintenance-audit-reporter' ) . "\n\n";
			$body .= implode( "\n", $plugin_lines ) . "\n\n";
		}

		return $body;
	}

	/**
	 * `## 【現在更新が滞っているプラグイン】` block from wp.org `last_updated` age (180+ / 365+ days), matching `maintenance-scripts`.
	 *
	 * @param array<string,mixed> $facts Dataset from {@see WPMAR_Data_Collector::gather()}.
	 * @return string
	 */
	protected static function render_client_outdated_plugins_shell_style( array $facts ) {
		$raw = isset( $facts['plugins_outdated'] ) && is_array( $facts['plugins_outdated'] )
			? $facts['plugins_outdated']
			: array();

		$tier_365 = isset( $raw['tier_365'] ) && is_array( $raw['tier_365'] ) ? $raw['tier_365'] : array();
		$tier_180 = isset( $raw['tier_180'] ) && is_array( $raw['tier_180'] ) ? $raw['tier_180'] : array();

		if ( empty( $tier_365 ) && empty( $tier_180 ) ) {
			return '';
		}

		$sort_fn = static function ( $a, $b ) {
			$ta = is_array( $a ) && isset( $a['title'] ) ? (string) $a['title'] : '';
			$tb = is_array( $b ) && isset( $b['title'] ) ? (string) $b['title'] : '';
			return strnatcasecmp( $ta, $tb );
		};
		usort( $tier_365, $sort_fn );
		usort( $tier_180, $sort_fn );

		$lines = array();
		foreach ( $tier_365 as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$title = isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '';
			if ( '' === $title ) {
				continue;
			}
			$lines[] = sprintf(
				/* translators: %s: plugin title */
				__( '* プラグイン %s は1年以上更新されていません。必ず開発状況を確認してください。', 'wp-maintenance-audit-reporter' ),
				$title
			);
		}
		foreach ( $tier_180 as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$title = isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '';
			if ( '' === $title ) {
				continue;
			}
			$lines[] = sprintf(
				/* translators: %s: plugin title */
				__( '* プラグイン %s は半年以上更新されていません。注意してください。', 'wp-maintenance-audit-reporter' ),
				$title
			);
		}

		if ( empty( $lines ) ) {
			return '';
		}

		$n = count( $lines );

		$body  = "\n## ";
		$body .= sprintf(
			/* translators: %d: number of plugins with stale WordPress.org last_updated */
			__( '【現在更新が滞っているプラグイン / %d件】', 'wp-maintenance-audit-reporter' ),
			$n
		);
		$body .= "\n\n";
		$body .= __( '※ 以下のプラグインは開発が終了しているかもしれません。プラグインディレクトリ、フォーラムまたは公式サイトなどで情報を確認してください。', 'wp-maintenance-audit-reporter' ) . "\n\n";
		$body .= implode( "\n", $lines ) . "\n\n";

		return $body;
	}

	/**
	 * Theme lines: `* テーマ {Name} には新しいバージョン …` matching shell output.
	 *
	 * @return array<int,string>
	 */
	protected static function collect_pending_theme_update_lines() {
		$transient = get_site_transient( 'update_themes' );
		if ( ! is_object( $transient ) || empty( $transient->response ) || ! is_array( $transient->response ) ) {
			return array();
		}

		$lines = array();
		foreach ( $transient->response as $stylesheet => $data ) {
			if ( ! is_array( $data ) || empty( $data['new_version'] ) ) {
				continue;
			}
			$new = sanitize_text_field( (string) $data['new_version'] );

			$stylesheet_clean = sanitize_text_field( (string) $stylesheet );

			$theme_obj = wp_get_theme( $stylesheet_clean );
			$name      = $theme_obj->exists() ? sanitize_text_field( $theme_obj->get( 'Name' ) ) : $stylesheet_clean;
			if ( '' === $name ) {
				$name = $stylesheet_clean;
			}

			$installed = $theme_obj->exists() ? sanitize_text_field( (string) $theme_obj->get( 'Version' ) ) : '';
			if ( '' !== $installed && version_compare( $installed, $new, '>=' ) ) {
				continue;
			}

			$lines[] = sprintf(
				/* translators: 1: theme display name, 2: new version */
				__( '* テーマ %1$s には新しいバージョン %2$s があります。', 'wp-maintenance-audit-reporter' ),
				$name,
				$new
			);
		}

		return $lines;
	}

	/**
	 * Plugin lines: `* プラグイン {Title} には新しいバージョン …` matching shell output.
	 *
	 * @return array<int,string>
	 */
	protected static function collect_pending_plugin_update_lines() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$transient = get_site_transient( 'update_plugins' );
		if ( ! is_object( $transient ) || empty( $transient->response ) || ! is_array( $transient->response ) ) {
			return array();
		}

		$lines = array();
		foreach ( $transient->response as $basename => $data ) {
			if ( ! is_string( $basename ) || '' === $basename ) {
				continue;
			}
			if ( ! is_array( $data ) || empty( $data['new_version'] ) ) {
				continue;
			}
			$new = sanitize_text_field( (string) $data['new_version'] );

			$abs = wp_normalize_path( WP_PLUGIN_DIR . '/' . $basename );
			if ( ! is_readable( $abs ) ) {
				continue;
			}

			$plugin_data = get_plugin_data( $abs, false, false );
			$installed   = isset( $plugin_data['Version'] ) ? sanitize_text_field( (string) $plugin_data['Version'] ) : '';
			if ( '' !== $installed && version_compare( $installed, $new, '>=' ) ) {
				continue;
			}

			$title = isset( $plugin_data['Name'] ) ? sanitize_text_field( (string) $plugin_data['Name'] ) : '';
			if ( '' === $title ) {
				$title = dirname( $basename );
			}

			$lines[] = sprintf(
				/* translators: 1: plugin title, 2: new version */
				__( '* プラグイン %1$s には新しいバージョン %2$s があります。', 'wp-maintenance-audit-reporter' ),
				$title,
				$new
			);
		}

		return $lines;
	}

	/**
	 * `# 【ユーザー情報】` block: privileged publishers, TSV (shell-style).
	 *
	 * @param array<string,mixed> $facts Full gather envelope.
	 * @return string
	 */
	protected static function render_client_publishers_shell_style( array $facts ) {
		$users = isset( $facts['users'] ) && is_array( $facts['users'] ) ? $facts['users'] : array();

		$body  = '# 【ユーザー情報】' . "\n\n";
		$body .= __( '※ ハッキングなどによりユーザーが勝手に追加されていないかのチェック', 'wp-maintenance-audit-reporter' ) . "\n\n";
		$body .= __( '記事を公開できる権限を持つユーザー:', 'wp-maintenance-audit-reporter' ) . "\n\n";

		if ( empty( $users ) ) {
			$body .= __( '（該当ユーザーがいません。）', 'wp-maintenance-audit-reporter' ) . "\n\n";

			return $body;
		}

		foreach ( $users as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$body .= sprintf(
				"%s\t%s\t%s\t%s\t%s\t%s\n",
				sanitize_text_field( (string) ( $row['id'] ?? '' ) ),
				sanitize_text_field( (string) ( $row['login'] ?? '' ) ),
				sanitize_text_field( (string) ( $row['display_name'] ?? '' ) ),
				sanitize_email( (string) ( $row['email'] ?? '' ) ),
				sanitize_text_field( (string) ( $row['roles'] ?? '' ) ),
				sanitize_text_field( (string) ( $row['registered'] ?? '' ) )
			);
		}

		$body .= "\n";

		return $body;
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
			/* translators: %d: number of operational security notice items */
			__( '運用セキュリティ: %d 件の注意点があります。詳細は管理者向けログを参照してください。', 'wp-maintenance-audit-reporter' ),
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
				$lines[] = '* ' . __( 'TLS 証明書: 追加の期限警告なし。', 'wp-maintenance-audit-reporter' );
			} else {
				$lines[] = '* ' . __( 'TLS 証明書: 要確認（詳細は管理者ログ）。', 'wp-maintenance-audit-reporter' );
			}
		}

		if ( ! empty( $sec['php_eol']['status'] ) && in_array( $sec['php_eol']['status'], array( 'warn', 'past_eol', 'unknown' ), true ) ) {
			$lines[] = '* ' . __( 'PHP バージョン/サポート: 要確認。', 'wp-maintenance-audit-reporter' );
		}

		$stack = isset( $sec['recommended_versions'] ) && is_array( $sec['recommended_versions'] ) ? $sec['recommended_versions'] : array();
		foreach ( self::recommended_stack_client_issue_lines( $stack ) as $issue_line ) {
			$lines[] = $issue_line;
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
	 * Client-facing bullets for recommended stack issues (which component, with reason text).
	 *
	 * @param array<string,mixed> $stack {@see WPMAR_Check_Security_Ops::check_recommended_stack()} shape.
	 * @return array<int,string> Lines starting with "* ".
	 */
	protected static function recommended_stack_client_issue_lines( array $stack ) {
		$out       = array();
		$wordpress = isset( $stack['wordpress'] ) && is_array( $stack['wordpress'] ) ? $stack['wordpress'] : array();
		$php       = isset( $stack['php'] ) && is_array( $stack['php'] ) ? $stack['php'] : array();
		$mysql     = isset( $stack['mysql'] ) && is_array( $stack['mysql'] ) ? $stack['mysql'] : array();

		if ( ! empty( $wordpress['update_available'] ) ) {
			$msg = self::first_security_note_text( $wordpress );
			if ( '' === $msg ) {
				$msg = __( 'WordPress コアの更新が利用可能です。', 'wp-maintenance-audit-reporter' );
			}
			$out[] = '* ' . sprintf(
				/* translators: %s: explanation for the WordPress core recommendation */
				__( 'WordPress コア: %s', 'wp-maintenance-audit-reporter' ),
				$msg
			);
		}

		if ( ! empty( $php['below_8_1'] ) ) {
			$msg = self::first_security_note_text( $php );
			if ( '' === $msg ) {
				$msg = __( 'PHP 8.1 以上への更新が推奨です（セキュリティと互換性）。', 'wp-maintenance-audit-reporter' );
			}
			$out[] = '* ' . sprintf(
				/* translators: %s: explanation for the PHP recommendation */
				__( 'PHP: %s', 'wp-maintenance-audit-reporter' ),
				$msg
			);
		}

		if ( ! empty( $mysql['legacy'] ) ) {
			$msg = self::first_security_note_text( $mysql );
			if ( '' === $msg ) {
				$msg = __( 'データベースサーバーのバージョンが古すぎる可能性があります。', 'wp-maintenance-audit-reporter' );
			}
			$out[] = '* ' . sprintf(
				/* translators: %s: explanation for the database recommendation */
				__( 'データベース（MySQL/MariaDB）: %s', 'wp-maintenance-audit-reporter' ),
				$msg
			);
		}

		return $out;
	}

	/**
	 * First human note string from a recommended_versions component row.
	 *
	 * @param array<string,mixed> $component One of `wordpress`, `php`, or `mysql` slices from recommended_versions.
	 * @return string
	 */
	protected static function first_security_note_text( array $component ) {
		if ( empty( $component['notes'] ) || ! is_array( $component['notes'] ) ) {
			return '';
		}
		foreach ( $component['notes'] as $n ) {
			if ( is_string( $n ) && '' !== trim( $n ) ) {
				return sanitize_text_field( $n );
			}
		}

		return '';
	}

	/**
	 * Human-readable checksum bullets for stakeholder mail.
	 *
	 * @param array<string,mixed>  $checksum     Envelope produced by {@see WPMAR_Check_Checksums::collect()}.
	 * @param array<string,string> $plugin_names slug => display-name map for client-facing labels.
	 * @return string
	 */
	protected static function render_checksum_client_section( array $checksum, array $plugin_names = array() ) {
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
			}
		}

		if ( ! empty( $plugins ) ) {
			foreach ( $plugins as $slug => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$slug_safe = sanitize_key( (string) $slug );
				$status    = isset( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : '';
				// Client copy prefers the display name; falls back to slug when the name is unavailable.
				$label = isset( $plugin_names[ $slug_safe ] ) && '' !== $plugin_names[ $slug_safe ] ? $plugin_names[ $slug_safe ] : $slug_safe;

				if ( 'no_checksums' === $status ) {
					$lines[] = '* ' . sprintf(
						/* translators: %s: plugin name */
						__( 'プラグイン %s: 元データと照合できませんでした（公式ディレクトリ外の可能性）', 'wp-maintenance-audit-reporter' ),
						$label
					);

					continue;
				}

				if ( 'error' === $status && ! empty( $row['error'] ) ) {
					$lines[] = '* ' . sprintf(
						/* translators: 1 plugin name, 2 error */
						__( 'プラグイン %1$s: エラー (%2$s)', 'wp-maintenance-audit-reporter' ),
						$label,
						sanitize_text_field( (string) $row['error'] )
					);

					continue;
				}

				$mismatch_n = isset( $row['mismatches'] ) && is_array( $row['mismatches'] ) ? count( $row['mismatches'] ) : 0;
				if ( 'ok' === $status && 0 === $mismatch_n ) {
					$lines[] = '* ' . sprintf(
						/* translators: %s plugin name */
						__( 'プラグイン %s: OK', 'wp-maintenance-audit-reporter' ),
						$label
					);
				} else {
					$lines[] = '* ' . sprintf(
						/* translators: 1 plugin name, 2 count */
						__( 'プラグイン %1$s: 不一致 %2$d 件', 'wp-maintenance-audit-reporter' ),
						$label,
						absint( $mismatch_n )
					);

					if ( $mismatch_n > 0 && ! empty( $row['mismatches'] ) && is_array( $row['mismatches'] ) ) {
						$lines[]       = '  * ' . __( '以下のファイルに変更が見つかりました', 'wp-maintenance-audit-reporter' );
						$mismatch_rows = $row['mismatches'];
						$slice         = array_slice( $mismatch_rows, 0, 40 );
						foreach ( $slice as $m ) {
							if ( ! is_array( $m ) || empty( $m['file'] ) ) {
								continue;
							}
							$lines[] = '  * ' . sanitize_text_field( (string) $m['file'] );
						}
						if ( count( $mismatch_rows ) > 40 ) {
							$lines[] = '  * ' . sprintf(
								/* translators: %d: number of additional mismatched files not listed */
								__( '…他 %d 件', 'wp-maintenance-audit-reporter' ),
								count( $mismatch_rows ) - 40
							);
						}
					}
				}
			}
		}

		if ( empty( $lines ) ) {
			return __( 'チェックサム情報がありません。', 'wp-maintenance-audit-reporter' );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Operator-facing plaintext shaped like `/.maintenance/inc/mainte.sh` `ADMIN_MAIL_BODY` (not a raw JSON dump).
	 *
	 * @param array<string,mixed> $facts          Fresh envelope.
	 * @param string              $changelog      Diff body.
	 * @param bool                $gate           Domain gate acknowledgement.
	 * @param int                 $changelog_size Counter.
	 * @param int                 $duration_sec  Wall time spent in this run (seconds).
	 * @return string
	 */
	public static function render_operator_markup( array $facts, $changelog, $gate, $changelog_size, $duration_sec = 0 ) {
		$duration_sec = max( 0, (int) $duration_sec );

		$chunks             = array();
		$changelog_stripped = trim( wp_strip_all_tags( (string) $changelog ) );

		if ( ! $gate ) {
			$chunks[] = __( '※ ドメインゲートにより、この実行ではスナップショットは更新されていません。', 'wp-maintenance-audit-reporter' );
		}

		$chunks[] = self::render_operator_wordpress_section( $facts );
		$chunks[] = self::render_operator_themes_section( $facts );
		$chunks[] = self::render_operator_plugins_section( $facts );
		$chunks[] = self::render_operator_server_section( $facts );
		// Backup section hidden until backup status reporting is implemented.
		// Re-enable by adding: render_operator_backup_section() to the chunks array.
		$chunks[] = self::render_operator_users_section( $facts );
		$chunks[] = self::render_operator_changelog_section( $changelog_stripped, absint( $changelog_size ) );
		$chunks[] = self::render_operator_security_section_verbose( isset( $facts['security'] ) && is_array( $facts['security'] ) ? $facts['security'] : array() );
		$chunks[] = self::render_operator_performance_section_verbose(
			isset( $facts['performance'] ) && is_array( $facts['performance'] ) ? $facts['performance'] : array()
		);

		$chunks[] = self::filtered_report_sections_markdown(
			array(
				'audience'       => 'operator',
				'facts'          => $facts,
				'changelog'      => $changelog,
				'changelog_size' => $changelog_size,
				'domain_gate_ok' => $gate,
			)
		);

		$chunks[] = self::render_operator_execution_section( $duration_sec );
		$chunks[] = self::render_operator_runtime_section();

		$chunks = array_values(
			array_filter(
				array_map( 'trim', $chunks ),
				static function ( $block ) {
					return '' !== $block;
				}
			)
		);

		return trim( implode( "\n\n", $chunks ) ) . "\n";
	}

	/**
	 * WordPress 本体（更新状況＋コア・チェックサム） — mainte.sh 相当.
	 *
	 * @param array<string,mixed> $facts Dataset.
	 * @return string
	 */
	protected static function render_operator_wordpress_section( array $facts ) {
		$core    = isset( $facts['core'] ) && is_array( $facts['core'] ) ? $facts['core'] : array();
		$version = isset( $core['version'] ) ? sanitize_text_field( (string) $core['version'] ) : '';
		$pending = isset( $core['available_updates'] ) && is_array( $core['available_updates'] ) ? $core['available_updates'] : array();
		$pending = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $pending ) ) ) );

		$checksums = isset( $facts['checksums'] ) && is_array( $facts['checksums'] ) ? $facts['checksums'] : array();
		$core_cs   = isset( $checksums['core'] ) && is_array( $checksums['core'] ) ? $checksums['core'] : array();

		$update_line = '';
		if ( ! empty( $pending ) ) {
			$target      = sanitize_text_field( (string) $pending[0] );
			$update_line = sprintf(
				/* translators: 1: current version, 2: newest offered version */
				__( 'WordPress のコアファイルは最新ではありません。現在のバージョン: %1$s -> 最新バージョン: %2$s', 'wp-maintenance-audit-reporter' ),
				$version,
				$target
			) . "\n　　" . __( 'コアファイルに最新バージョンがリリースされています。可能な限り早くアップデートしてください。', 'wp-maintenance-audit-reporter' );
		} elseif ( '' !== $version ) {
			$update_line = sprintf(
				/* translators: %s: installed WordPress version */
				__( 'WordPress のコアファイルは最新バージョンを利用中です（バージョン: %s）。', 'wp-maintenance-audit-reporter' ),
				$version
			);
		} else {
			$update_line = __( 'WordPress バージョンを取得できませんでした。', 'wp-maintenance-audit-reporter' );
		}

		$checksum_block = self::render_operator_core_checksum_paragraph( $core_cs );

		return '# 【WordPress 本体】' . "\n"
			. __( '※ WordPress のコアファイルが最新版か・改ざんされていないかのチェック', 'wp-maintenance-audit-reporter' ) . "\n\n"
			. sprintf(
				/* translators: %s: current WordPress version string */
				__( '## 現在の WordPressのバージョン: %s', 'wp-maintenance-audit-reporter' ),
				'' !== $version ? $version : __( '不明', 'wp-maintenance-audit-reporter' )
			) . "\n"
			. __( 'アップデート：', 'wp-maintenance-audit-reporter' ) . $update_line . "\n"
			. $checksum_block;
	}

	/**
	 * Narrates core checksum output in prose similar to WP-CLI-style admin mail.
	 *
	 * @param array<string,mixed> $core_cs `checksums.core` slice.
	 * @return string
	 */
	protected static function render_operator_core_checksum_paragraph( array $core_cs ) {
		if ( empty( $core_cs ) ) {
			return __( 'コアのチェックサム検証結果がありません。', 'wp-maintenance-audit-reporter' );
		}

		if ( ! empty( $core_cs['error'] ) ) {
			return sprintf(
				/* translators: %s: error text */
				__( 'コアのチェックサム: エラー（%s）', 'wp-maintenance-audit-reporter' ),
				sanitize_text_field( (string) $core_cs['error'] )
			);
		}

		if ( empty( $core_cs['manifest_ok'] ) ) {
			return __( 'コアのチェックサム一覧を取得できませんでした。', 'wp-maintenance-audit-reporter' );
		}

		$mismatches = isset( $core_cs['mismatches'] ) && is_array( $core_cs['mismatches'] ) ? $core_cs['mismatches'] : array();
		$mismatch_n = count( $mismatches );

		if ( ! empty( $core_cs['ok'] ) && 0 === $mismatch_n ) {
			$skipped = absint( $core_cs['skipped_files'] ?? 0 );
			if ( $skipped > 0 ) {
				return __( 'コアファイルに改変は見つかりませんでした (※readme.html など一部ファイル差分は除外)。', 'wp-maintenance-audit-reporter' );
			}

			return __( 'コアファイルに改変は見つかりませんでした。WordPress のコアファイルは安全です。', 'wp-maintenance-audit-reporter' );
		}

		$lines   = array();
		$lines[] = __( 'WordPress のコアファイルの以下のファイルに変更が見つかりました:', 'wp-maintenance-audit-reporter' );
		$slice   = array_slice( $mismatches, 0, 40 );
		foreach ( $slice as $row ) {
			if ( ! is_array( $row ) || empty( $row['file'] ) ) {
				continue;
			}
			$lines[] = '　　' . sanitize_text_field( (string) $row['file'] );
		}
		if ( count( $mismatches ) > 40 ) {
			$lines[] = '　　' . sprintf(
				/* translators: %d: number of files omitted */
				__( '…他 %d 件', 'wp-maintenance-audit-reporter' ),
				count( $mismatches ) - 40
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * Compares installed semver against WordPress.org directory `version`.
	 *
	 * @param string $installed Local version from theme stylesheet or plugin header.
	 * @param string $latest    Directory API `version`.
	 * @return string One of `update_available`, `current`, `data_error`, or `unknown`.
	 */
	protected static function directory_version_status( $installed, $latest ) {
		$installed = trim( (string) $installed );
		$latest    = trim( (string) $latest );

		if ( '' === $latest || '' === $installed ) {
			return 'unknown';
		}

		if ( version_compare( $installed, $latest, '<' ) ) {
			return 'update_available';
		}

		if ( version_compare( $installed, $latest, '>' ) ) {
			return 'data_error';
		}

		return 'current';
	}

	/**
	 * Reads pending plugin upgrade target from the `update_plugins` site transient.
	 *
	 * @param string $basename Plugin basename (`dir/file.php`).
	 * @return string New semver string, or empty when no pending update.
	 */
	protected static function pending_plugin_new_version( $basename ) {
		$basename = (string) $basename;
		if ( '' === $basename ) {
			return '';
		}
		$transient = get_site_transient( 'update_plugins' );
		if ( ! is_object( $transient ) || empty( $transient->response[ $basename ] ) || ! is_array( $transient->response[ $basename ] ) ) {
			return '';
		}
		$data = $transient->response[ $basename ];
		return ! empty( $data['new_version'] ) ? sanitize_text_field( (string) $data['new_version'] ) : '';
	}

	/**
	 * Reads pending theme upgrade target from the `update_themes` site transient.
	 *
	 * @param string $stylesheet Theme stylesheet slug (directory name).
	 * @return string New semver string, or empty when no pending update.
	 */
	protected static function pending_theme_new_version( $stylesheet ) {
		$stylesheet = sanitize_text_field( (string) $stylesheet );
		if ( '' === $stylesheet ) {
			return '';
		}
		$transient = get_site_transient( 'update_themes' );
		if ( ! is_object( $transient ) || empty( $transient->response[ $stylesheet ] ) || ! is_array( $transient->response[ $stylesheet ] ) ) {
			return '';
		}
		$data = $transient->response[ $stylesheet ];
		return ! empty( $data['new_version'] ) ? sanitize_text_field( (string) $data['new_version'] ) : '';
	}

	/**
	 * # 【テーマファイル】 — active / inactive blocks.
	 *
	 * @param array<string,mixed> $facts Dataset.
	 * @return string
	 */
	protected static function render_operator_themes_section( array $facts ) {
		$bundle = isset( $facts['themes'] ) && is_array( $facts['themes'] ) ? $facts['themes'] : array();
		$rows   = isset( $bundle['inventory'] ) && is_array( $bundle['inventory'] ) ? $bundle['inventory'] : array();
		$org    = isset( $bundle['org'] ) && is_array( $bundle['org'] ) ? $bundle['org'] : array();

		usort(
			$rows,
			static function ( $a, $b ) {
				$na = is_array( $a ) && isset( $a['name'] ) ? (string) $a['name'] : '';
				$nb = is_array( $b ) && isset( $b['name'] ) ? (string) $b['name'] : '';
				return strnatcasecmp( $na, $nb );
			}
		);

		$active_lines   = array();
		$inactive_lines = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$slug = isset( $row['slug'] ) ? sanitize_key( (string) $row['slug'] ) : '';
			if ( '' === $slug ) {
				continue;
			}

			$name    = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : $slug;
			$ver     = isset( $row['version'] ) ? sanitize_text_field( (string) $row['version'] ) : '';
			$is_act  = ! empty( $row['active'] );
			$o       = isset( $org[ $slug ] ) && is_array( $org[ $slug ] ) ? $org[ $slug ] : array();
			$last    = isset( $o['last_updated'] ) ? trim( (string) $o['last_updated'] ) : '';
			$latest  = isset( $o['version'] ) ? trim( (string) $o['version'] ) : '';
			$pending = self::pending_theme_new_version( $slug );

			if ( '' === $last ) {
				$last = __( '（未取得）', 'wp-maintenance-audit-reporter' );
			}

			$unavailable    = ( '' === $latest );
			$version_status = self::directory_version_status( $ver, $latest );
			if ( ! $unavailable && 'update_available' === $version_status ) {
				$version_info = sprintf(
					/* translators: %s: latest theme version from directory API */
					__( '（最新バージョン：%s）', 'wp-maintenance-audit-reporter' ),
					sanitize_text_field( $latest )
				);
				$msg = sprintf(
					/* translators: %s: theme name */
					__( '%s には新しいバージョンがあります。可能な限り早くアップデートしてください。', 'wp-maintenance-audit-reporter' ),
					$name
				);
			} elseif ( ! $unavailable && 'data_error' === $version_status ) {
				$version_info = sprintf(
					/* translators: %s: latest theme version from directory API */
					__( '（最新バージョン：%s）', 'wp-maintenance-audit-reporter' ),
					sanitize_text_field( $latest )
				);
				$msg = __( 'データが正しく取得できませんでした。', 'wp-maintenance-audit-reporter' );
			} elseif ( ! $unavailable && 'current' === $version_status ) {
				$version_info = sprintf(
					/* translators: %s: installed theme version */
					__( '（最新バージョン：%s）', 'wp-maintenance-audit-reporter' ),
					$ver
				);
				$msg = sprintf(
					/* translators: %s: theme name */
					__( '%s は最新のバージョンを利用中です。', 'wp-maintenance-audit-reporter' ),
					$name
				);
			} else {
				$version_info = '';
				$msg          = __( 'このテーマは非公式か既に公開終了している可能性があります。', 'wp-maintenance-audit-reporter' );
			}

			if ( '' !== $version_info ) {
				$version_line = sprintf(
					/* translators: 1: last updated label/time, 2: version parenthetical */
					__( '最終更新日: %1$s %2$s', 'wp-maintenance-audit-reporter' ),
					$last,
					$version_info
				);
			} else {
				$version_line = sprintf(
					/* translators: %s: last updated or unknown */
					__( '最終更新日: %s', 'wp-maintenance-audit-reporter' ),
					$last
				);
			}

			if ( '' !== $pending && ( '' === $ver || version_compare( $ver, $pending, '<' ) ) ) {
				$msg = sprintf(
					/* translators: 1: theme name, 2: pending new version from updates transient */
					__( '%1$s には新しいバージョン %2$s が通知されています。可能な限り早くアップデートしてください。', 'wp-maintenance-audit-reporter' ),
					$name,
					$pending
				);
			}

			$block = sprintf(
				/* translators: 1: theme name, 2: slug, 3: installed version */
				__( '* %1$s（%2$s） (バージョン: %3$s)', 'wp-maintenance-audit-reporter' ),
				$name,
				$slug,
				$ver
			) . "\n";
			$block .= '　' . $version_line . "\n";
			$block .= '　　' . $msg;

			if ( $is_act ) {
				$active_lines[] = $block;
			} else {
				$inactive_lines[] = $block;
			}
		}

		$out  = '# 【テーマファイル】' . "\n";
		$out .= __( '## ※ 有効化されているテーマについての情報一覧', 'wp-maintenance-audit-reporter' ) . "\n\n";
		$out .= implode( "\n\n", $active_lines );
		if ( empty( $active_lines ) ) {
			$out .= __( '（該当テーマがありません。）', 'wp-maintenance-audit-reporter' );
		}
		$out .= "\n\n" . __( '## ※ インストールされているが有効化されていないテーマについての情報一覧', 'wp-maintenance-audit-reporter' ) . "\n\n";
		$out .= implode( "\n\n", $inactive_lines );
		if ( empty( $inactive_lines ) ) {
			$out .= __( '（該当テーマがありません。）', 'wp-maintenance-audit-reporter' );
		}

		return $out;
	}

	/**
	 * # 【プラグインファイル】 — active / inactive blocks with checksum prose.
	 *
	 * @param array<string,mixed> $facts Dataset.
	 * @return string
	 */
	protected static function render_operator_plugins_section( array $facts ) {
		$bundle    = isset( $facts['plugins'] ) && is_array( $facts['plugins'] ) ? $facts['plugins'] : array();
		$rows      = isset( $bundle['inventory'] ) && is_array( $bundle['inventory'] ) ? $bundle['inventory'] : array();
		$org       = isset( $bundle['org'] ) && is_array( $bundle['org'] ) ? $bundle['org'] : array();
		$checksums = isset( $facts['checksums'] ) && is_array( $facts['checksums'] ) ? $facts['checksums'] : array();
		$plugs_cs  = isset( $checksums['plugins'] ) && is_array( $checksums['plugins'] ) ? $checksums['plugins'] : array();

		usort(
			$rows,
			static function ( $a, $b ) {
				$ta = is_array( $a ) && isset( $a['title'] ) ? (string) $a['title'] : '';
				$tb = is_array( $b ) && isset( $b['title'] ) ? (string) $b['title'] : '';
				return strnatcasecmp( $ta, $tb );
			}
		);

		$active_lines   = array();
		$inactive_lines = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$slug = isset( $row['slug'] ) ? sanitize_key( (string) $row['slug'] ) : '';
			$base = isset( $row['basename'] ) ? sanitize_text_field( (string) $row['basename'] ) : '';
			if ( '' === $slug || '' === $base ) {
				continue;
			}

			$title   = isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : $slug;
			$ver     = isset( $row['version'] ) ? sanitize_text_field( (string) $row['version'] ) : '';
			$is_act  = ! empty( $row['active'] );
			$o       = isset( $org[ $slug ] ) && is_array( $org[ $slug ] ) ? $org[ $slug ] : array();
			$last    = isset( $o['last_updated'] ) ? trim( (string) $o['last_updated'] ) : '';
			$latest  = isset( $o['version'] ) ? trim( (string) $o['version'] ) : '';
			$pending = self::pending_plugin_new_version( $base );

			if ( '' === $last ) {
				$last = __( '（未取得）', 'wp-maintenance-audit-reporter' );
			}

			$unavailable    = ( '' === $latest );
			$version_status = self::directory_version_status( $ver, $latest );
			if ( ! $unavailable && 'update_available' === $version_status ) {
				$version_info = sprintf(
					/* translators: %s: latest plugin version from directory API */
					__( '（最新バージョン：%s）', 'wp-maintenance-audit-reporter' ),
					sanitize_text_field( $latest )
				);
				$msg = sprintf(
					/* translators: %s: plugin title */
					__( '%s には新しいバージョンがあります。可能な限り早くアップデートしてください。', 'wp-maintenance-audit-reporter' ),
					$title
				);
			} elseif ( ! $unavailable && 'data_error' === $version_status ) {
				$version_info = sprintf(
					/* translators: %s: latest plugin version from directory API */
					__( '（最新バージョン：%s）', 'wp-maintenance-audit-reporter' ),
					sanitize_text_field( $latest )
				);
				$msg = __( 'データが正しく取得できませんでした。', 'wp-maintenance-audit-reporter' );
			} elseif ( ! $unavailable && 'current' === $version_status ) {
				$version_info = sprintf(
					/* translators: %s: installed plugin version */
					__( '（最新バージョン：%s）', 'wp-maintenance-audit-reporter' ),
					$ver
				);
				$msg = sprintf(
					/* translators: %s: plugin title */
					__( '%s は最新のバージョンを利用中です。', 'wp-maintenance-audit-reporter' ),
					$title
				);
			} else {
				$version_info = '';
				$msg          = sprintf(
					/* translators: %s: plugin title */
					__( '%s は非公式か、既に公開終了している可能性があります。', 'wp-maintenance-audit-reporter' ),
					$title
				);
			}

			if ( '' !== $version_info ) {
				$version_line = sprintf(
					/* translators: 1: last updated, 2: version parenthetical */
					__( '最終更新日: %1$s %2$s', 'wp-maintenance-audit-reporter' ),
					$last,
					$version_info
				);
			} else {
				$version_line = sprintf(
					/* translators: %s: last updated */
					__( '最終更新日: %s', 'wp-maintenance-audit-reporter' ),
					$last
				);
			}

			if ( '' !== $pending && ( '' === $ver || version_compare( $ver, $pending, '<' ) ) ) {
				$msg = sprintf(
					/* translators: 1: plugin title, 2: pending new version */
					__( '%1$s には新しいバージョン %2$s が通知されています。可能な限り早くアップデートしてください。', 'wp-maintenance-audit-reporter' ),
					$title,
					$pending
				);
			}

			$cs_row  = isset( $plugs_cs[ $slug ] ) && is_array( $plugs_cs[ $slug ] ) ? $plugs_cs[ $slug ] : array();
			$cs_text = self::render_operator_plugin_checksum_prose( $title, $cs_row );
			if ( $unavailable && 'no_checksums' === ( isset( $cs_row['status'] ) ? sanitize_key( (string) $cs_row['status'] ) : '' ) ) {
				$cs_text = '';
			}

			$block = sprintf(
				/* translators: 1: plugin title, 2: slug, 3: installed version */
				__( '* %1$s（%2$s） (バージョン: %3$s)', 'wp-maintenance-audit-reporter' ),
				$title,
				$slug,
				$ver
			) . "\n";
			$block .= '　' . $version_line . "\n";
			if ( '' !== $cs_text ) {
				$block .= '　　' . $cs_text . "\n";
			}
			$block .= '　　' . $msg;

			if ( $is_act ) {
				$active_lines[] = $block;
			} else {
				$inactive_lines[] = $block;
			}
		}

		$out  = '# 【プラグインファイル】' . "\n";
		$out .= __( '## ※ 有効化されているプラグインについての情報一覧', 'wp-maintenance-audit-reporter' ) . "\n\n";
		$out .= implode( "\n\n", $active_lines );
		if ( empty( $active_lines ) ) {
			$out .= __( '（該当プラグインがありません。）', 'wp-maintenance-audit-reporter' );
		}
		$out .= "\n\n" . __( '## ※ インストールされているが有効化されていないプラグインについての情報一覧', 'wp-maintenance-audit-reporter' ) . "\n\n";
		$out .= implode( "\n\n", $inactive_lines );
		if ( empty( $inactive_lines ) ) {
			$out .= __( '（該当プラグインがありません。）', 'wp-maintenance-audit-reporter' );
		}

		return $out;
	}

	/**
	 * Single-plugin checksum narrative (aligned with shell wording where possible).
	 *
	 * @param string              $title    Plugin title.
	 * @param array<string,mixed> $cs_row   Plugin checksum row.
	 * @return string
	 */
	protected static function render_operator_plugin_checksum_prose( $title, array $cs_row ) {
		$title = sanitize_text_field( (string) $title );
		if ( '' === $title ) {
			$title = __( '（無題）', 'wp-maintenance-audit-reporter' );
		}
		$status = isset( $cs_row['status'] ) ? sanitize_key( (string) $cs_row['status'] ) : '';

		if ( 'no_checksums' === $status ) {
			return sprintf(
				/* translators: %s: plugin title */
				__( '%s は非公式か、既に公開終了しているプラグインです。', 'wp-maintenance-audit-reporter' ),
				$title
			);
		}

		if ( 'error' === $status && ! empty( $cs_row['error'] ) ) {
			return sprintf(
				/* translators: 1: plugin title, 2: error */
				__( '%1$s のチェックサム検証でエラーが発生しました（%2$s）。', 'wp-maintenance-audit-reporter' ),
				$title,
				sanitize_text_field( (string) $cs_row['error'] )
			);
		}

		$mismatches = isset( $cs_row['mismatches'] ) && is_array( $cs_row['mismatches'] ) ? $cs_row['mismatches'] : array();
		$mismatch_n = count( $mismatches );

		if ( 'mismatch' === $status || $mismatch_n > 0 ) {
			$lines   = array();
			$lines[] = sprintf(
				/* translators: %s: plugin title */
				__( '%s の以下のファイルに変更が見つかりました:', 'wp-maintenance-audit-reporter' ),
				$title
			);
			if ( 0 === $mismatch_n ) {
				$lines[] = '　　　　' . __( '（詳細なし）', 'wp-maintenance-audit-reporter' );
			}
			$slice = array_slice( $mismatches, 0, 30 );
			foreach ( $slice as $m ) {
				if ( ! is_array( $m ) || empty( $m['file'] ) ) {
					continue;
				}
				$lines[] = '　　　　' . sanitize_text_field( (string) $m['file'] );
			}
			if ( count( $mismatches ) > 30 ) {
				$lines[] = '　　　　' . sprintf(
					/* translators: %d: omitted file count */
					__( '…他 %d 件', 'wp-maintenance-audit-reporter' ),
					count( $mismatches ) - 30
				);
			}

			return implode( "\n", $lines );
		}

		if ( 'ok' === $status && 0 === $mismatch_n ) {
			return sprintf(
				/* translators: %s: plugin title */
				__( '%s のファイルに改変は見つかりませんでした。プラグインファイルは安全です。', 'wp-maintenance-audit-reporter' ),
				$title
			);
		}

		return sprintf(
			/* translators: %s: plugin title */
			__( '%s — チェックサム情報が取得できませんでした。', 'wp-maintenance-audit-reporter' ),
			$title
		);
	}

	/**
	 * # 【サーバー関連情報】
	 *
	 * @param array<string,mixed> $facts Dataset.
	 * @return string
	 */
	protected static function render_operator_server_section( array $facts ) {
		$srv = isset( $facts['server'] ) && is_array( $facts['server'] ) ? $facts['server'] : array();

		$lines   = array();
		$lines[] = '# 【サーバー関連情報】';
		if ( ! empty( $srv['php'] ) ) {
			$lines[] = sprintf(
				/* translators: %s: PHP version */
				__( '* PHP バージョン: %s', 'wp-maintenance-audit-reporter' ),
				sanitize_text_field( (string) $srv['php'] )
			);
		}
		if ( ! empty( $srv['mysql'] ) ) {
			$lines[] = sprintf(
				/* translators: %s: MySQL server version string */
				__( '* MySQL サーバー報告バージョン: %s', 'wp-maintenance-audit-reporter' ),
				sanitize_text_field( (string) $srv['mysql'] )
			);
		}
		if ( isset( $srv['wp_memory'] ) && '' !== (string) $srv['wp_memory'] ) {
			$lines[] = sprintf(
				'* WP_MEMORY_LIMIT: %s',
				sanitize_text_field( (string) $srv['wp_memory'] )
			);
		}
		if ( isset( $srv['environment'] ) ) {
			$lines[] = sprintf(
				/* translators: %s: environment type e.g. production */
				__( '* wp_get_environment_type(): %s', 'wp-maintenance-audit-reporter' ),
				sanitize_text_field( (string) $srv['environment'] )
			);
		}
		if ( isset( $srv['wp_debug'] ) ) {
			$lines[] = sprintf(
				'* WP_DEBUG: %s',
				sanitize_text_field( (string) $srv['wp_debug'] )
			);
		}
		if ( isset( $srv['script_debug'] ) ) {
			$lines[] = sprintf(
				'* SCRIPT_DEBUG: %s',
				sanitize_text_field( (string) $srv['script_debug'] )
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * バックアップ状況（管理者向けは常に `gather_backup_providers` を出力）.
	 *
	 * 現バージョンでは取得・表示機能は未実装のため、{@see render_operator_markup()} からは呼び出さない。
	 *
	 * @param array<string,mixed> $facts Dataset.
	 * @return string
	 */
	protected static function render_operator_backup_section( array $facts ) {
		$backup = isset( $facts['backup'] ) && is_array( $facts['backup'] ) ? $facts['backup'] : array();
		$inner  = self::render_backup_client_section( $backup );
		return '# 【バックアップ状況】' . "\n\n" . $inner;
	}

	/**
	 * # 【ユーザー情報】 — TSV block（クライアント向けと同様）.
	 *
	 * @param array<string,mixed> $facts Dataset.
	 * @return string
	 */
	protected static function render_operator_users_section( array $facts ) {
		$users = isset( $facts['users'] ) && is_array( $facts['users'] ) ? $facts['users'] : array();

		$body  = '# 【ユーザー情報】' . "\n";
		$body .= __( '※ ハッキングなどによりユーザーが勝手に追加されていないかのチェック', 'wp-maintenance-audit-reporter' ) . "\n\n";
		$body .= __( '記事を公開できる権限を持つユーザー:', 'wp-maintenance-audit-reporter' ) . "\n\n";

		if ( empty( $users ) ) {
			return $body . __( '（該当ユーザーがいません。）', 'wp-maintenance-audit-reporter' );
		}

		foreach ( $users as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$body .= sprintf(
				"%s\t%s\t%s\t%s\t%s\t%s\n",
				sanitize_text_field( (string) ( $row['id'] ?? '' ) ),
				sanitize_text_field( (string) ( $row['login'] ?? '' ) ),
				sanitize_text_field( (string) ( $row['display_name'] ?? '' ) ),
				sanitize_email( (string) ( $row['email'] ?? '' ) ),
				sanitize_text_field( (string) ( $row['roles'] ?? '' ) ),
				sanitize_text_field( (string) ( $row['registered'] ?? '' ) )
			);
		}

		return rtrim( $body );
	}

	/**
	 * ## 【前回スナップショットからの差分】（プラグイン独自の差分ログ）.
	 *
	 * @param string $changelog_stripped Plain diff body.
	 * @param int    $changelog_size     Count.
	 * @return string
	 */
	protected static function render_operator_changelog_section( $changelog_stripped, $changelog_size ) {
		$changelog_stripped = trim( (string) $changelog_stripped );
		$body               = __( '## 【前回スナップショットからの差分】', 'wp-maintenance-audit-reporter' ) . "\n";
		$body              .= sprintf(
			/* translators: %d: number of changes detected */
			__( '件数: %d', 'wp-maintenance-audit-reporter' ),
			absint( $changelog_size )
		) . "\n";

		if ( '' === $changelog_stripped ) {
			$body .= __( '差分は検出されませんでした。', 'wp-maintenance-audit-reporter' );
		} else {
			$body .= "\n" . $changelog_stripped;
		}

		return $body;
	}

	/**
	 * # 【運用・セキュリティ】 — admin-oriented detail.
	 *
	 * @param array<string,mixed> $sec Security envelope.
	 * @return string
	 */
	protected static function render_operator_security_section_verbose( array $sec ) {
		$body  = '# 【運用・セキュリティ】' . "\n\n";
		$body .= self::render_security_client_section( $sec );

		$codes = isset( $sec['summary_codes'] ) && is_array( $sec['summary_codes'] ) ? $sec['summary_codes'] : array();
		if ( ! empty( $codes ) ) {
			$body .= "\n" . sprintf(
				/* translators: %s: comma-separated warning codes */
				__( '内部コード: %s', 'wp-maintenance-audit-reporter' ),
				sanitize_text_field( implode( ', ', array_map( 'strval', $codes ) ) )
			);
		}

		return $body;
	}

	/**
	 * Optional DB size section for operators.
	 *
	 * @param array<string,mixed> $perf Performance envelope.
	 * @return string
	 */
	protected static function render_operator_performance_section_verbose( array $perf ) {
		if ( empty( $perf ) ) {
			return '';
		}
		$inner = self::render_performance_client_section( $perf );

		return '# 【オプション：データベースサイズ】' . "\n\n" . $inner;
	}

	/**
	 * ## 【実行時間】 footer.
	 *
	 * @param int $duration_sec Duration in seconds.
	 * @return string
	 */
	protected static function render_operator_execution_section( $duration_sec ) {
		$duration_sec = max( 0, (int) $duration_sec );
		$minutes      = intdiv( $duration_sec, 60 );
		$seconds      = $duration_sec % 60;

		return sprintf(
			/* translators: 1: minutes, 2: seconds */
			__( "## 【実行時間】\nこの調査のためのスクリプトの動作に %1\$d 分 %2\$d 秒かかりました。", 'wp-maintenance-audit-reporter' ),
			$minutes,
			$seconds
		);
	}

	/**
	 * ## 【コマンドラインツール】 equivalent (runtime introspection; WP-CLI は Web 実行時は未取得).
	 *
	 * @return string
	 */
	protected static function render_operator_runtime_section() {
		$sapi = function_exists( 'php_sapi_name' ) ? sanitize_text_field( (string) php_sapi_name() ) : '';

		$lines   = array();
		$lines[] = __( '## 【コマンドラインツール】', 'wp-maintenance-audit-reporter' );
		if ( '' !== $sapi ) {
			$lines[] = sprintf(
				/* translators: %s: PHP SAPI name */
				__( 'PHP SAPI: %s', 'wp-maintenance-audit-reporter' ),
				$sapi
			);
		}
		$lines[] = sprintf(
			/* translators: %s: PHP_VERSION */
			__( 'PHP バージョン（このリクエスト）: %s', 'wp-maintenance-audit-reporter' ),
			sanitize_text_field( PHP_VERSION )
		);
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$lines[] = __( 'WP-CLI: 検出されました（CLI 実行中）。', 'wp-maintenance-audit-reporter' );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Markdown snippets added by third-parties via {@see 'wpmar_report_sections'}.
	 *
	 * @param array<string,mixed> $context Audience + harvested facts envelope.
	 * @return string
	 */
	protected static function filtered_report_sections_markdown( array $context ) {
		$extras = apply_filters( 'wpmar_report_sections', array(), $context );
		if ( empty( $extras ) || ! is_array( $extras ) ) {
			return '';
		}

		return WPMAR_Check_Performance::stringify_section_extras( $extras );
	}

	/**
	 * Short bullets explaining hooked backup adapters.
	 *
	 * @param array<string,mixed> $backup Backup bundle keyed by collector output.
	 * @return string
	 */
	protected static function render_backup_client_section( array $backup ) {
		$providers = isset( $backup['providers'] ) && is_array( $backup['providers'] ) ? $backup['providers'] : array();
		if ( empty( $providers ) ) {
			return __( '登録済みのバックアッププロバイダはありません（フィルター `wpmar_backup_providers` で追加できます）。', 'wp-maintenance-audit-reporter' );
		}

		$lines = array();
		foreach ( $providers as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id      = isset( $row['id'] ) ? sanitize_key( (string) $row['id'] ) : '';
			$label   = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : $id;
			$snippet = isset( $row['markdown'] ) && is_string( $row['markdown'] ) ? trim( $row['markdown'] ) : '';

			if ( '' === $snippet ) {
				$lines[] = '* ' . sprintf(
					/* translators: %s provider label */
					__( '%s — （出力なし）', 'wp-maintenance-audit-reporter' ),
					$label
				);

				continue;
			}

			if ( strlen( $snippet ) > 500 ) {
				$snippet = substr( $snippet, 0, 500 ) . '…';
			}

			$lead = '*' . $label;
			if ( '' !== $id ) {
				$lead .= ' `' . $id . '`';
			}
			$lines[] = $lead . "\n  ```text\n  " . str_replace( "\n", "\n  ", $snippet ) . "\n  ```";
		}

		return implode( "\n\n", $lines );
	}

	/**
	 * Summarises optional DB table-size sampling for stakeholder mail bodies.
	 *
	 * @param array<string,mixed> $perf Optional probe results (`db_tables` when the option ran).
	 * @return string
	 */
	protected static function render_performance_client_section( array $perf ) {
		if ( empty( $perf ) || ! isset( $perf['db_tables'] ) || ! is_array( $perf['db_tables'] ) ) {
			return __( 'データベースサイズチェックはオフです（設定でオンにすると上位テーブルを集計します）。', 'wp-maintenance-audit-reporter' );
		}

		$lines = array();
		$db    = $perf['db_tables'];
		if ( empty( $db['ok'] ) ) {
			$lines[] = sprintf(
				/* translators: %s reason */
				__( 'データベース容量: information_schema が利用できません（%s）。', 'wp-maintenance-audit-reporter' ),
				sanitize_text_field( (string) ( $db['error'] ?? '' ) )
			);
		} else {
			if ( isset( $db['total_mb'] ) ) {
				$approx_mb = round( (float) $db['total_mb'], 2 );
			} elseif ( isset( $db['total_bytes'] ) ) {
				// Legacy snapshots stored raw byte totals.
				$approx_mb = round( absint( $db['total_bytes'] ) / 1048576, 2 );
			} else {
				$approx_mb = 0.0;
			}
			$lines[] = sprintf(
				/* translators: 1 total megabytes rounded, 2 sampled table count */
				__( 'データベース容量（上位サンプルの合計目安）: 約 %1$s MiB / %2$d テーブル行', 'wp-maintenance-audit-reporter' ),
				number_format_i18n( $approx_mb, 2 ),
				absint( $db['table_count'] ?? 0 )
			);
		}

		return implode(
			"\n",
			array_map(
				static function ( $line ) {
					return '* ' . $line;
				},
				$lines
			)
		);
	}
}
