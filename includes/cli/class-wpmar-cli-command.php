<?php
/**
 * Registers `wp maintenance-audit *` routes; file is omitted on plain web bootstrap.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Bail early when invoked through the browser - avoids loading WP_CLI_Command stubs.
 */
if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

/**
 * Thin adapter from argv to {@see WPMAR_Runner} and repositories.
 */
class WPMAR_CLI_Command extends WP_CLI_Command {

	/**
	 * Executes a concrete audit synchronously.
	 *
	 * ## OPTIONS
	 *
	 * [--dry]
	 * : Harvest data without persisting or mailing.
	 *
	 * [--network]
	 * : Run a multisite rollup audit (requires network audit enabled in network settings).
	 *
	 * [--no-snapshot]
	 * : Skip snapshot persistence. Report is generated but the snapshot baseline is not updated.
	 *
	 * ## EXAMPLES
	 *
	 * wp maintenance-audit run
	 * wp maintenance-audit run --dry
	 * wp maintenance-audit run --network
	 * wp maintenance-audit run --no-snapshot
	 * wp maintenance-audit run --network --no-snapshot
	 *
	 * @param array<int,string>             $positional  Positional arguments.
	 * @param array<string,string|bool|int> $assoc_flags Associative CLI flags.
	 * @return void
	 */
	public function run( $positional, $assoc_flags ) {
		unset( $positional );

		$dry         = isset( $assoc_flags['dry'] );
		$network     = isset( $assoc_flags['network'] );
		$no_snapshot = isset( $assoc_flags['no-snapshot'] );

		if ( $network ) {
			if ( ! WPMAR_Network_Settings::is_multisite_available() ) {
				WP_CLI::error( 'Multisite is not enabled on this installation.' );
			}
			if ( ! WPMAR_Network_Settings::is_network_audit_enabled() ) {
				WP_CLI::error( 'Network rollup audit is disabled (enable it under Network Admin → Maintenance Audit).' );
			}

			$runner = new WPMAR_Network_Runner();
			$result = $runner->run(
				array(
					'dry'               => ! empty( $dry ),
					'triggered_by'      => 'cli_network',
					'persist_snapshots' => ! $no_snapshot,
				)
			);
		} else {
			$runner = new WPMAR_Runner();
			$result = $runner->run(
				array(
					'dry'               => ! empty( $dry ),
					'triggered_by'      => 'cli',
					'capture_cli'       => true,
					'mail_override'     => '',
					'persist_snapshots' => ! $no_snapshot,
				)
			);
		}

		// Echo structured JSON because operators often pipe CLI output downstream.
		WP_CLI::success( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Runs collector dry mode (no DB writes besides CLI probe transient).
	 *
	 * @param array<int,string>             $positional  Positional arguments.
	 * @param array<string,string|bool|int> $assoc_flags Flags.
	 * @return void
	 */
	public function test( $positional, $assoc_flags ) {
		unset( $positional, $assoc_flags );

		$runner = new WPMAR_Runner();
		$runner->run(
			array(
				'dry'          => true,
				'triggered_by' => 'cli',
				'capture_cli'  => true,
			)
		);

		WP_CLI::success( 'Dry instrumentation completed.' );
	}

	/**
	 * Prints recent persisted reports as a compact table.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Rows to retrieve (default 20).
	 *
	 * @param array<int,string>             $positional  Positional arguments.
	 * @param array<string,string|bool|int> $assoc_flags Flags.
	 * @return void
	 */
	public function reports( $positional, $assoc_flags ) {
		unset( $positional );

		$limit = isset( $assoc_flags['limit'] ) ? absint( $assoc_flags['limit'] ) : 20;
		if ( $limit <= 0 ) {
			$limit = 20;
		}

		$repo = new WPMAR_Report_Repository();
		$rows = $repo->list_recent( $limit );

		if ( empty( $rows ) ) {
			WP_CLI::warning( 'No report rows matched the query.' );

			return;
		}

		// Column order derived from whichever row surfaced first - schema is homogeneous.
		WP_CLI\Utils\format_items(
			'table',
			$rows,
			array_keys( reset( $rows ) )
		);
	}

	/**
	 * Deletes a stored report permanently.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Numeric report identifier.
	 *
	 * [--yes]
	 * : Bypass confirmation prompts.
	 *
	 * @param array<int,string>             $positional  Positional arguments.
	 * @param array<string,string|bool|int> $assoc_flags Flags.
	 * @return void
	 */
	public function delete( $positional, $assoc_flags ) {
		$id = isset( $positional[0] ) ? absint( $positional[0] ) : 0;
		if ( $id <= 0 ) {
			WP_CLI::error( 'A positive report id is required.' );
		}

		WP_CLI::confirm(
			'Delete report #' . $id . '?',
			$assoc_flags
		);

		$repository = new WPMAR_Report_Repository();
		$deleted    = $repository->delete_row( $id );
		if ( ! $deleted ) {
			WP_CLI::warning( 'No rows deleted (missing id).' );

			return;
		}

		WP_CLI::success( 'Report deleted.' );
	}

	/**
	 * Streams Markdown/JSON artefacts for piping.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Report primary key.
	 *
	 * [--format=<fmt>]
	 * : markdown|json|pdf (defaults to markdown bodies).
	 *
	 * [--file=<path>]
	 * : Write output to this path instead of STDOUT (recommended for PDF when other plugins emit PHP notices during bootstrap).
	 *
	 * @param array<int,string>             $positional  Positional arguments.
	 * @param array<string,string|bool|int> $assoc_flags Flags.
	 * @return void
	 */
	public function export( $positional, $assoc_flags ) {
		$id = isset( $positional[0] ) ? absint( $positional[0] ) : 0;

		if ( $id <= 0 ) {
			WP_CLI::error( 'A positive report id is required.' );
		}

		$format = isset( $assoc_flags['format'] ) ? sanitize_key( (string) $assoc_flags['format'] ) : 'markdown';
		if ( 'md' === $format ) {
			$format = 'markdown';
		}

		$file_out = isset( $assoc_flags['file'] ) ? trim( (string) $assoc_flags['file'] ) : '';
		if ( '' !== $file_out ) {
			$file_out = wp_normalize_path( $file_out );
			$parent   = dirname( $file_out );
			if ( ! is_dir( $parent ) ) {
				WP_CLI::error( sprintf( 'Directory does not exist: %s', $parent ) );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP-CLI export writes to arbitrary operator paths outside wp-admin filesystem UI.
			if ( ! is_writable( $parent ) ) {
				WP_CLI::error( sprintf( 'Directory is not writable: %s', $parent ) );
			}
		}

		$repository = new WPMAR_Report_Repository();
		$row        = $repository->find( $id );
		if ( null === $row ) {
			WP_CLI::error( 'Unable to locate that report.' );
		}

		// markdown streams `body_md` only; json dumps entire SQL row (inc. summaries).
		if ( 'markdown' === $format ) {
			$body = (string) ( $row['body_md'] ?? '' );
			if ( '' !== $file_out ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP-CLI binary/text export to operator-chosen path.
				if ( false === file_put_contents( $file_out, $body ) ) {
					WP_CLI::error( 'Failed to write Markdown file.' );
				}
				WP_CLI::success( sprintf( 'Wrote Markdown to %s', $file_out ) );

				return;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct STDOUT piping for CLI export.
			fwrite( STDOUT, $body );

			return;
		}

		if ( 'json' === $format ) {
			$encoded = wp_json_encode( $row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			$payload = false === $encoded ? '{}' : $encoded;

			if ( '' !== $file_out ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP-CLI binary/text export to operator-chosen path.
				if ( false === file_put_contents( $file_out, $payload ) ) {
					WP_CLI::error( 'Failed to write JSON file.' );
				}
				WP_CLI::success( sprintf( 'Wrote JSON to %s', $file_out ) );

				return;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct STDOUT piping for CLI export.
			fwrite( STDOUT, $payload );

			return;
		}

		if ( 'pdf' === $format ) {
			if ( ! WPMAR_PDF_Writer::is_available() ) {
				WP_CLI::error( 'PDF export requires Composer dependencies (mPDF + Parsedown).' );
			}

			$rel = isset( $row['pdf_file_path'] ) ? (string) $row['pdf_file_path'] : '';
			if ( '' === $rel ) {
				$pdf_md = WPMAR_PDF_Writer::markdown_body_for_client_pdf( $row );
				if ( '' === $pdf_md ) {
					WP_CLI::error( 'No client-facing Markdown stored for this report (upgrade plugin and run an audit), or PDF deps missing.' );
				}
				$written = WPMAR_PDF_Writer::write_pdf_from_markdown(
					$pdf_md,
					'wpmar-report-' . $id
				);
				if ( is_wp_error( $written ) ) {
					WP_CLI::error( $written->get_error_message() );
				}
				if ( is_string( $written ) && '' !== $written ) {
					$repository->update_pdf_file_path( $id, $written );
					$rel = $written;
				}
			}

			$abs = WPMAR_MD_Writer::absolute_path_from_upload_relative( $rel );
			if ( '' === $rel || '' === $abs || ! is_readable( $abs ) ) {
				WP_CLI::error( 'Unable to read PDF artefact for this report.' );
			}

			if ( '' !== $file_out ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- WP-CLI streams PDF artefact to operator-chosen path.
				if ( ! copy( $abs, $file_out ) ) {
					WP_CLI::error( 'Failed to write PDF file.' );
				}
				WP_CLI::success( sprintf( 'Wrote PDF to %s', $file_out ) );

				return;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming binary to STDOUT for operators.
			readfile( $abs );

			return;
		}

		WP_CLI::error( 'Unsupported format. Use markdown|json|pdf.' );
	}
}

// Register umbrella command handled by WP-CLI's dispatcher.
WP_CLI::add_command( 'maintenance-audit', 'WPMAR_CLI_Command' );
