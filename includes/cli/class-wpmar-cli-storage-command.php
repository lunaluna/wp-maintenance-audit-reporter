<?php
/**
 * Registers `wp wpmar storage *` subcommands.
 *
 * @see WPMAR_Storage_Migrator
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
 * Migrates report/PDF/log storage between the legacy uploads layout and private storage.
 */
class WPMAR_CLI_Storage_Command extends WP_CLI_Command {

	/**
	 * Migrates report/PDF/log storage between the legacy uploads layout and the
	 * protected private-storage directory (or back, with `--revert`).
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report eligible-row counts and post-migration paths without moving any file
	 *   or touching the database.
	 *
	 * [--network]
	 * : Repeat on every site in the network (`switch_to_blog()` per site). Without
	 *   this flag, only the current site is processed.
	 *
	 * [--batch=<size>]
	 * : Rows processed per internal batch. Progress is saved after every batch, so
	 *   an interrupted run resumes from where it left off.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--revert]
	 * : Reverse direction: move files back to the legacy `uploads/wpmar/` layout and
	 *   strip the `private:` prefix from stored paths (filenames, tokens included,
	 *   are kept as-is). Intended for downgrading back to a pre-1.3.1 release; not
	 *   exposed in the admin UI since a downgrade is a deliberate operator action.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpmar storage migrate --dry-run
	 *     wp wpmar storage migrate
	 *     wp wpmar storage migrate --network
	 *     wp wpmar storage migrate --batch=50
	 *     wp wpmar storage migrate --revert
	 *
	 * @param array<int,string>             $positional  Positional arguments (unused).
	 * @param array<string,string|bool|int> $assoc_flags Associative CLI flags.
	 * @return void
	 */
	public function migrate( $positional, $assoc_flags ) {
		unset( $positional );

		$dry_run   = WPMAR_CLI_Flags::bool( $assoc_flags, 'dry-run' );
		$revert    = WPMAR_CLI_Flags::bool( $assoc_flags, 'revert' );
		$network   = WPMAR_CLI_Flags::bool( $assoc_flags, 'network' );
		$batch     = max( 1, WPMAR_CLI_Flags::int( $assoc_flags, 'batch', WPMAR_Storage_Migrator::DEFAULT_BATCH_SIZE ) );
		$direction = $revert ? 'revert' : 'migrate';

		if ( ! $network ) {
			self::run_one_site( $direction, $batch, $dry_run );

			return;
		}

		if ( ! WPMAR_Network_Settings::is_multisite_available() ) {
			WP_CLI::error( 'Multisite is not enabled on this installation.' );
		}

		$sites = get_sites( array( 'number' => 0 ) );
		foreach ( $sites as $site ) {
			$blog_id = absint( $site->blog_id );
			if ( $blog_id <= 0 ) {
				continue;
			}

			switch_to_blog( $blog_id );
			WP_CLI::log( sprintf( '--- Site #%d (%s) ---', $blog_id, home_url() ) );
			self::run_one_site( $direction, $batch, $dry_run );
			restore_current_blog();
		}
	}

	/**
	 * Runs (or dry-runs) a full migration/revert pass on the current site.
	 *
	 * @param string $direction 'migrate' or 'revert'.
	 * @param int    $batch     Rows per batch.
	 * @param bool   $dry_run   Whether to only preview.
	 * @return void
	 */
	protected static function run_one_site( $direction, $batch, $dry_run ) {
		if ( $dry_run ) {
			$summary = WPMAR_Storage_Migrator::dry_run_summary( $direction );
			WP_CLI::log( wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

			return;
		}

		$state = WPMAR_Storage_Migrator::run_all( $direction, $batch );

		WP_CLI::log( wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		if ( 'failed' === $state['state'] ) {
			WP_CLI::error( 'Migration failed — see the notes above. Fix the underlying issue, then re-run this command (progress is preserved).' );
		}

		$terminal_state = 'revert' === $direction ? 'reverted' : 'done';
		if ( $terminal_state === $state['state'] ) {
			WP_CLI::success( sprintf( 'Storage %s complete.', $direction ) );

			return;
		}

		WP_CLI::warning( 'Did not reach a terminal state within this run; re-run the command to continue.' );
	}
}

WP_CLI::add_command( 'wpmar storage', 'WPMAR_CLI_Storage_Command' );
