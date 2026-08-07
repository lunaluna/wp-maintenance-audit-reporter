<?php
/**
 * Bridges the admin/UI trigger to a background audit run via Action Scheduler.
 *
 * Splits the formerly synchronous "click → audit → report" path into two halves:
 *   - {@see self::enqueue_audit_job()} records a queued job and returns immediately
 *     (a few hundred ms), so the request finishes well within the CloudFront origin
 *     timeout that previously produced 504s.
 *   - {@see self::run_audit_job()} is invoked later by Action Scheduler's queue and
 *     performs the real work, updating the job row so the admin polling UI can react.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues and executes asynchronous audit jobs.
 */
class WPMAR_Job_Dispatcher {

	/**
	 * Action Scheduler hook that carries the job id.
	 */
	const HOOK = 'wpmar/run_audit';

	/**
	 * Action Scheduler hook for one site's independent segment of a network rollup.
	 */
	const HOOK_NETWORK_SITE_SEGMENT = 'wpmar/run_network_site_segment';

	/**
	 * Action Scheduler group, for grouping/filtering in the admin queue screen.
	 */
	const GROUP = 'wpmar';

	/**
	 * Registers the queue callbacks. Runs in every context so Action Scheduler can
	 * dispatch jobs regardless of who triggers the queue (web cron, WP-CLI, etc.).
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_audit_job' ), 10, 1 );
		add_action( self::HOOK_NETWORK_SITE_SEGMENT, array( __CLASS__, 'run_network_site_segment' ), 10, 3 );
	}

	/**
	 * Records a queued job and schedules it for asynchronous execution.
	 *
	 * @param array<string,mixed> $args  Runner options (dry, triggered_by, persist_snapshots, …).
	 * @param string              $scope `single` or `network`.
	 * @return string|WP_Error Job id on success, WP_Error when the queue is unavailable.
	 */
	public static function enqueue_audit_job( array $args, $scope = 'single' ) {
		if ( ! wpmar_action_scheduler_available() ) {
			return new WP_Error(
				'wpmar_as_unavailable',
				__( '非同期ジョブ基盤（Action Scheduler）が利用できません。', 'wp-maintenance-audit-reporter' )
			);
		}

		$scope  = WPMAR_Jobs_Repository::sanitize_scope( $scope );
		$job_id = WPMAR_Jobs_Repository::sanitize_id( uniqid( 'wpmar', true ) );

		if ( '' === $job_id ) {
			return new WP_Error(
				'wpmar_job_id_failed',
				__( 'ジョブ ID を生成できませんでした。', 'wp-maintenance-audit-reporter' )
			);
		}

		// Record whether loopback works right now so the polling REST endpoint can
		// decide to run the queue inline (Basic auth環境のフォールバック) without
		// re-probing per poll. Cached 12h by the detector, so this is usually free.
		$detector         = new WPMAR_Loopback_Detector();
		$loopback_blocked = ! $detector->is_loopback_available();

		$repo = new WPMAR_Jobs_Repository();
		if ( ! $repo->create( $job_id, $args, $scope, $loopback_blocked ) ) {
			return new WP_Error(
				'wpmar_job_create_failed',
				__( 'ジョブの登録に失敗しました。', 'wp-maintenance-audit-reporter' )
			);
		}

		// Hand the job id to Action Scheduler; the queue invokes self::run_audit_job().
		as_enqueue_async_action( self::HOOK, array( $job_id ), self::GROUP );

		return $job_id;
	}

	/**
	 * Executes a queued audit job. Invoked by Action Scheduler.
	 *
	 * @param string $job_id Job id recorded by {@see self::enqueue_audit_job()}.
	 * @return void
	 */
	public static function run_audit_job( $job_id ) {
		$repo = new WPMAR_Jobs_Repository();
		$job  = $repo->find( $job_id );

		if ( null === $job ) {
			return; // Unknown id (purged or never created) — nothing to do.
		}

		// Idempotency guard: only a queued job should start, so a duplicate dispatch
		// (Action Scheduler retry, overlapping queues) cannot run the audit twice.
		$status = isset( $job['status'] ) ? (string) $job['status'] : '';
		if ( WPMAR_Jobs_Repository::STATUS_QUEUED !== $status ) {
			return;
		}

		$repo->mark_running( $job_id );

		$args = isset( $job['args_json'] ) ? json_decode( (string) $job['args_json'], true ) : array();
		if ( ! is_array( $args ) ) {
			$args = array();
		}
		$scope = isset( $job['scope'] ) ? (string) $job['scope'] : 'single';

		$log_relative = WPMAR_Logger::begin_job( $job_id );
		if ( '' !== $log_relative ) {
			$repo->set_log_path( $job_id, $log_relative );
		}
		WPMAR_Logger::log( WPMAR_Logger::LEVEL_INFO, 'scope: ' . $scope );

		try {
			if ( 'network' === $scope ) {
				$runner = new WPMAR_Network_Runner();
				$result = $runner->run( $args );
			} else {
				$runner = new WPMAR_Runner();
				$result = $runner->run( $args );
			}

			$repo->mark_done( $job_id, is_array( $result ) ? $result : array() );
			WPMAR_Logger::log( WPMAR_Logger::LEVEL_INFO, 'job succeeded' );
		} catch ( Throwable $e ) {
			$repo->mark_failed( $job_id, $e->getMessage() );
			WPMAR_Logger::log( WPMAR_Logger::LEVEL_ERROR, 'job failed: ' . $e->getMessage() );

			if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- opt-in logging under WP_DEBUG / WP_DEBUG_LOG.
				error_log( 'WPMAR async audit job ' . $job_id . ' failed: ' . $e->getMessage() );
			}
		} finally {
			WPMAR_Logger::end_job();
		}
	}

	/**
	 * Executes one site's independent segment of a network rollup. Invoked by Action
	 * Scheduler once per target blog, as its own action (own process, own memory space).
	 *
	 * This is the fix for the structural memory ceiling of the old design: looping every
	 * site inside one Action Scheduler action meant peak memory grew with site count and
	 * one site's OOM took the whole run down. Splitting each site into its own action
	 * caps peak memory at "one site's worth" regardless of network size, and an OOM here
	 * only fails this one segment - {@see WPMAR_Job_Dispatcher::HOOK_NETWORK_SITE_SEGMENT}'s
	 * sibling actions and already-`done` segments are unaffected.
	 *
	 * Only the rendered bodies and display fields are ever written to the segments table -
	 * the raw per-site dataset from {@see WPMAR_Runner::run_site_segment()} is discarded
	 * once this function returns, exactly as it would be at the end of any PHP request.
	 *
	 * @param string $run_id            Parent job id (see {@see WPMAR_Jobs_Repository}) this segment belongs to.
	 * @param int    $blog_id           Target blog id.
	 * @param bool   $persist_snapshots Whether to persist snapshots for this site (see {@see WPMAR_Runner::run_site_segment()}).
	 * @return void
	 */
	public static function run_network_site_segment( $run_id, $blog_id, $persist_snapshots = true ) {
		$run_id  = WPMAR_Network_Segments_Repository::sanitize_run_id( $run_id );
		$blog_id = absint( $blog_id );
		if ( '' === $run_id || 0 === $blog_id ) {
			return;
		}

		$segments_repo = new WPMAR_Network_Segments_Repository();
		$row           = $segments_repo->find_one( $run_id, $blog_id );

		if ( null === $row ) {
			return; // Unknown (purged or never created) — nothing to do.
		}

		// Idempotency guard: only a queued segment should start, so a duplicate dispatch
		// (Action Scheduler retry, overlapping queues) cannot run this site's audit twice.
		$status = isset( $row['status'] ) ? (string) $row['status'] : '';
		if ( WPMAR_Network_Segments_Repository::STATUS_QUEUED !== $status ) {
			return;
		}

		$segments_repo->mark_running( $run_id, $blog_id );

		try {
			$network_settings = WPMAR_Network_Settings::get_all();
			$runner           = new WPMAR_Runner();

			$segment = WPMAR_Network::on_blog(
				$blog_id,
				function () use ( $runner, $network_settings, $persist_snapshots ) {
					$gate_settings = WPMAR_Domain_Gate::merge_network_gate_settings(
						WPMAR_Settings::get_all(),
						$network_settings
					);

					return $runner->run_site_segment(
						array(
							'persist_snapshots' => $persist_snapshots,
							'gate_settings'     => $gate_settings,
						)
					);
				}
			);

			if ( ! is_array( $segment ) ) {
				$segments_repo->mark_failed(
					$run_id,
					$blog_id,
					__( 'サイト監査の実行結果を取得できませんでした。', 'wp-maintenance-audit-reporter' )
				);
				return;
			}

			$segments_repo->mark_done( $run_id, $blog_id, $segment );
		} catch ( Throwable $e ) {
			$segments_repo->mark_failed( $run_id, $blog_id, $e->getMessage() );

			if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- opt-in logging under WP_DEBUG / WP_DEBUG_LOG.
				error_log( "WPMAR network site segment run={$run_id} blog={$blog_id} failed: " . $e->getMessage() );
			}
		}
	}
}
