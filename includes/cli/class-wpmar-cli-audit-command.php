<?php
/**
 * Registers `wp wpmar audit *` subcommands.
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
 * Runs audits synchronously or via the Action Scheduler queue, and probes collector wiring.
 */
class WPMAR_CLI_Audit_Command extends WP_CLI_Command {

	/**
	 * Executes an audit run.
	 *
	 * ## OPTIONS
	 *
	 * [--sync]
	 * : Execute synchronously in this process. This is the default; the flag is kept
	 *   only for backward compatibility with earlier scripts and is a no-op.
	 *
	 * [--async]
	 * : Enqueue the run on the Action Scheduler queue instead of running it in this
	 *   process. Returns immediately with a job id; the audit itself only progresses
	 *   once the queue is worked (`wp action-scheduler run` or the next cron tick).
	 *
	 * [--dry-run]
	 * : Harvest data without persisting snapshots or sending mail.
	 *
	 * [--network]
	 * : Run a multisite rollup audit (requires network audit enabled in network settings).
	 *
	 * [--skip-snapshot]
	 * : Skip snapshot persistence. The report is generated but the snapshot baseline is not updated.
	 *
	 * [--same-setting]
	 * : (Requires --network) Collect data from the main site only instead of all target sites.
	 *   Useful when all sites share identical plugins, themes, and configuration.
	 *
	 * [--id=<blog_id>]
	 * : (Requires --network) Collect data from this specific blog ID only.
	 *   Takes precedence over --same-setting when both are given.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpmar audit run
	 *     wp wpmar audit run --dry-run
	 *     wp wpmar audit run --network
	 *     wp wpmar audit run --skip-snapshot
	 *     wp wpmar audit run --network --skip-snapshot
	 *     wp wpmar audit run --network --same-setting
	 *     wp wpmar audit run --network --id=2
	 *     wp wpmar audit run --async
	 *
	 * @param array<int,string>             $positional  Positional arguments (unused).
	 * @param array<string,string|bool|int> $assoc_flags Associative CLI flags.
	 * @return void
	 */
	public function run( $positional, $assoc_flags ) {
		unset( $positional );

		$sync          = WPMAR_CLI_Flags::bool( $assoc_flags, 'sync' );
		$async         = WPMAR_CLI_Flags::bool( $assoc_flags, 'async' );
		$dry           = WPMAR_CLI_Flags::bool( $assoc_flags, 'dry-run' );
		$network       = WPMAR_CLI_Flags::bool( $assoc_flags, 'network' );
		$skip_snapshot = WPMAR_CLI_Flags::bool( $assoc_flags, 'skip-snapshot' );
		$same_setting  = WPMAR_CLI_Flags::bool( $assoc_flags, 'same-setting' );
		$target_id     = array_key_exists( 'id', $assoc_flags ) ? absint( $assoc_flags['id'] ) : 0;

		if ( $sync && $async ) {
			WP_CLI::error( '--sync と --async は同時に指定できません。' );
		}

		if ( $network ) {
			if ( ! WPMAR_Network_Settings::is_multisite_available() ) {
				WP_CLI::error( 'Multisite is not enabled on this installation.' );
			}
			if ( ! WPMAR_Network_Settings::is_network_audit_enabled() ) {
				WP_CLI::error( 'Network rollup audit is disabled (enable it under Network Admin → Maintenance Audit).' );
			}
			if ( $target_id > 0 && ! get_blog_details( $target_id ) ) {
				WP_CLI::error( sprintf( 'Blog ID %d does not exist on this network.', $target_id ) );
			}
		}

		if ( $network ) {
			$args = array(
				'dry'               => $dry,
				'triggered_by'      => 'cli_network',
				'persist_snapshots' => ! $skip_snapshot,
				'same_setting'      => $same_setting,
				'target_blog_id'    => $target_id,
			);
		} else {
			$args = array(
				'dry'               => $dry,
				'triggered_by'      => 'cli',
				'capture_cli'       => true,
				'mail_override'     => '',
				'persist_snapshots' => ! $skip_snapshot,
			);
		}

		if ( $async ) {
			$job_id = WPMAR_Job_Dispatcher::enqueue_audit_job( $args, $network ? 'network' : 'single' );

			if ( is_wp_error( $job_id ) ) {
				WP_CLI::error( $job_id->get_error_message() . ' --async を外して同期実行してください。' );
			}

			WP_CLI::success( sprintf( "Enqueued job %s. This process does not run it — progress via 'wp action-scheduler run' or the next cron tick.", $job_id ) );

			return;
		}

		WPMAR_Logger::begin_job( 'cli-' . uniqid() );

		try {
			$runner = $network ? new WPMAR_Network_Runner() : new WPMAR_Runner();
			$result = $runner->run( $args );
		} finally {
			WPMAR_Logger::end_job();
		}

		// Echo structured JSON because operators often pipe CLI output downstream.
		WP_CLI::success( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Runs collector dry mode (no DB writes besides CLI probe transient).
	 *
	 * @param array<int,string>             $positional  Positional arguments (unused).
	 * @param array<string,string|bool|int> $assoc_flags Associative CLI flags (unused).
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
}

// Register umbrella command handled by WP-CLI's dispatcher.
WP_CLI::add_command( 'wpmar audit', 'WPMAR_CLI_Audit_Command' );
