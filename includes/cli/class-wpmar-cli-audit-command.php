<?php
/**
 * Registers `wp wpmar audit *` subcommands.
 *
 * Provides a synchronous execution path (`--sync`) intended as a CloudFront-bypassing
 * fallback for production debugging and manual operation. The async job queue path
 * (omitting `--sync`) is wired in a later step once Action Scheduler is integrated.
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
 * Thin adapter from argv to {@see WPMAR_Runner} / {@see WPMAR_Network_Runner}.
 */
class WPMAR_CLI_Audit_Command extends WP_CLI_Command {

	/**
	 * Executes an audit run.
	 *
	 * ## OPTIONS
	 *
	 * [--sync]
	 * : Execute synchronously in this process. Required until the async job queue is wired.
	 *   Use as a CloudFront-bypassing fallback for production debugging / manual operation.
	 *
	 * [--dry-run]
	 * : Harvest data without persisting snapshots or sending mail.
	 *
	 * [--network]
	 * : Run a multisite rollup audit (requires network audit enabled in network settings).
	 *
	 * [--no-snapshot]
	 * : Skip snapshot persistence. The report is generated but the snapshot baseline is not updated.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpmar audit run --sync
	 *     wp wpmar audit run --sync --dry-run
	 *     wp wpmar audit run --sync --network
	 *     wp wpmar audit run --sync --no-snapshot
	 *     wp wpmar audit run --sync --network --no-snapshot
	 *
	 * @param array<int,string>             $positional  Positional arguments (unused).
	 * @param array<string,string|bool|int> $assoc_flags Associative CLI flags.
	 * @return void
	 */
	public function run( $positional, $assoc_flags ) {
		unset( $positional );

		$sync        = isset( $assoc_flags['sync'] );
		$dry         = isset( $assoc_flags['dry-run'] );
		$network     = isset( $assoc_flags['network'] );
		$no_snapshot = isset( $assoc_flags['no-snapshot'] );

		// Default is async; until the queue is wired, require an explicit --sync so the
		// eventual async-by-default behaviour is not silently inverted before it exists.
		if ( ! $sync ) {
			WP_CLI::error( 'Async job queue is not yet implemented. Pass --sync to run synchronously.' );
		}

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
}

// Register umbrella command handled by WP-CLI's dispatcher.
WP_CLI::add_command( 'wpmar audit', 'WPMAR_CLI_Audit_Command' );
