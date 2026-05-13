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
	 * ## EXAMPLES
	 *
	 * wp maintenance-audit run
	 * wp maintenance-audit run --dry
	 *
	 * @param array<int,string>             $positional  Positional arguments.
	 * @param array<string,string|bool|int> $assoc_flags Associative CLI flags.
	 * @return void
	 */
	public function run( $positional, $assoc_flags ) {
		unset( $positional );

		$dry = isset( $assoc_flags['dry'] );

		$runner = new WPMAR_Runner();
		$result = $runner->run(
			array(
				'dry'           => ! empty( $dry ),
				'triggered_by'  => 'cli',
				'capture_cli'   => true,
				'mail_override' => '',
			)
		);

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
	 * : markdown|json (defaults to markdown bodies).
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

		$repository = new WPMAR_Report_Repository();
		$row        = $repository->find( $id );
		if ( null === $row ) {
			WP_CLI::error( 'Unable to locate that report.' );
		}

		// markdown streams `body_md` only; json dumps entire SQL row (inc. summaries).
		if ( 'markdown' === $format ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct STDOUT piping for CLI export.
			fwrite( STDOUT, (string) ( $row['body_md'] ?? '' ) );

			return;
		}

		if ( 'json' === $format ) {
			$encoded = wp_json_encode( $row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct STDOUT piping for CLI export.
			fwrite( STDOUT, false === $encoded ? '{}' : $encoded );

			return;
		}

		WP_CLI::error( 'Unsupported format. Use markdown|json.' );
	}
}

// Register umbrella command handled by WP-CLI's dispatcher.
WP_CLI::add_command( 'maintenance-audit', 'WPMAR_CLI_Command' );
