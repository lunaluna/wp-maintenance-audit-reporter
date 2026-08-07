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
	 * Action Scheduler hook that waits for a network rollup's segments and finalizes the report.
	 */
	const HOOK_NETWORK_AGGREGATE = 'wpmar/run_network_aggregate';

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
		add_action( self::HOOK_NETWORK_AGGREGATE, array( __CLASS__, 'run_network_aggregate' ), 10, 1 );
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
	 * A real (non-dry) network-scope job no longer runs the rollup itself here - it
	 * dispatches to {@see self::dispatch_network_rollup()}, which queues one independent
	 * action per site plus one aggregate action, and returns immediately. The job stays
	 * `running` until {@see self::run_network_aggregate()} eventually marks it `done`.
	 * A dry network run (preview only, no persistence/mail) still runs synchronously
	 * here, same as before - it has no memory concern to split up and no report to wait
	 * for. Single-scope jobs are entirely unaffected.
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
			if ( 'network' === $scope && empty( $args['dry'] ) ) {
				self::dispatch_network_rollup( $job_id, $args );
			} else {
				$runner = ( 'network' === $scope ) ? new WPMAR_Network_Runner() : new WPMAR_Runner();
				$result = $runner->run( $args );

				$repo->mark_done( $job_id, is_array( $result ) ? $result : array() );
				WPMAR_Logger::log( WPMAR_Logger::LEVEL_INFO, 'job succeeded' );
			}
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
	 * Dispatches a real network rollup as independent per-site jobs + one aggregate job,
	 * instead of running every site inline in this one action.
	 *
	 * Deliberately lightweight (DB writes and `as_enqueue_async_action()` calls only, no
	 * site audits run here) - the whole point of the split is that this dispatch step
	 * itself is not where an OOM could plausibly happen, unlike the old single-loop design.
	 *
	 * @param string              $job_id Parent job id (doubles as the segments table's `run_id`).
	 * @param array<string,mixed> $args   Decoded job args (same shape {@see WPMAR_Network_Runner::run()} expects).
	 * @return void
	 */
	protected static function dispatch_network_rollup( $job_id, array $args ) {
		WPMAR_Network::on_main_site(
			function () use ( $job_id, $args ) {
				self::dispatch_network_rollup_on_main_site( $job_id, $args );
			}
		);
	}

	/**
	 * Body of {@see self::dispatch_network_rollup()}, guaranteed to already be running on
	 * the main site - `wpmar_network_segments` only has real data there, same rationale
	 * as {@see self::run_network_site_segment()}. `LOCK_TRANSIENT` itself is a *site*
	 * transient (network-wide, not per-blog), so the busy-check alone would be safe from
	 * any blog context, but everything past it is not.
	 *
	 * @param string              $job_id Parent job id / segments `run_id`.
	 * @param array<string,mixed> $args   Decoded job args.
	 * @return void
	 */
	protected static function dispatch_network_rollup_on_main_site( $job_id, array $args ) {
		if ( false !== get_site_transient( WPMAR_Network_Runner::LOCK_TRANSIENT ) ) {
			WPMAR_Logger::log( WPMAR_Logger::LEVEL_INFO, 'network dispatch skipped: another network run is already in progress' );

			$repo = new WPMAR_Jobs_Repository();
			$repo->mark_done(
				$job_id,
				array(
					'skipped' => true,
					'reason'  => 'busy',
				)
			);
			return;
		}
		set_site_transient( WPMAR_Network_Runner::LOCK_TRANSIENT, 1, 20 * MINUTE_IN_SECONDS );

		$network_settings  = WPMAR_Network_Settings::get_all();
		$blog_ids          = WPMAR_Network_Runner::resolve_blog_ids( $args, $network_settings );
		$persist_snapshots = WPMAR_Network_Runner::should_persist_snapshots( $args );

		$segments_repo = new WPMAR_Network_Segments_Repository();
		$created       = $segments_repo->create_queued_batch( $job_id, $blog_ids );

		if ( count( $blog_ids ) !== $created ) {
			WPMAR_Logger::log(
				WPMAR_Logger::LEVEL_WARN,
				sprintf( 'network dispatch: only %d of %d segment rows were created', $created, count( $blog_ids ) )
			);
		}

		foreach ( $blog_ids as $blog_id ) {
			as_enqueue_async_action(
				self::HOOK_NETWORK_SITE_SEGMENT,
				array( $job_id, $blog_id, $persist_snapshots ),
				self::GROUP
			);
		}

		// Enqueued even when $blog_ids is empty, so the run still finalizes (empty report,
		// lock released) instead of sitting `running` forever with nothing left to wait on.
		as_enqueue_async_action( self::HOOK_NETWORK_AGGREGATE, array( $job_id ), self::GROUP );

		WPMAR_Logger::log(
			WPMAR_Logger::LEVEL_INFO,
			sprintf( 'network dispatch: queued %d site segment(s) + 1 aggregate action', count( $blog_ids ) )
		);
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
	 * `wpmar_network_segments` only has real data in the main site's copy of the table
	 * (see {@see WPMAR_Activator::maybe_create_tables()}), but Action Scheduler does not
	 * guarantee which blog is "current" when it fires an action - this mirrors the same
	 * explicit `on_main_site()` wrap {@see WPMAR_Network_Runner::run()} already uses for
	 * exactly this reason, rather than assuming the queue happened to fire on main site.
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

		WPMAR_Network::on_main_site(
			function () use ( $run_id, $blog_id, $persist_snapshots ) {
				self::run_network_site_segment_on_main_site( $run_id, $blog_id, $persist_snapshots );
			}
		);
	}

	/**
	 * Body of {@see self::run_network_site_segment()}, guaranteed to already be running
	 * on the main site.
	 *
	 * @param string $run_id            Parent job id.
	 * @param int    $blog_id           Target blog id.
	 * @param bool   $persist_snapshots Whether to persist snapshots for this site.
	 * @return void
	 */
	protected static function run_network_site_segment_on_main_site( $run_id, $blog_id, $persist_snapshots ) {
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

			// Switches away from the main site (established by the caller) to the target
			// blog for the actual audit, then back - the same nesting run_on_main_site()'s
			// synchronous loop already relies on.
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

	/**
	 * Waits for every site segment of a network run to reach `done`/`failed`, then
	 * finalizes the report exactly as the old single-action synchronous loop did.
	 *
	 * Invoked repeatedly by Action Scheduler (self-reschedules while segments are still
	 * `queued`/`running`) rather than blocking - this action's own memory footprint stays
	 * flat regardless of how many sites are still mid-flight, since it only ever reads
	 * status counts and small per-site rows, never re-runs anything.
	 *
	 * `wpmar_network_segments`/`wpmar_jobs`/`wpmar_reports` only have real data in the
	 * main site's copy of their tables - see the same rationale in
	 * {@see self::run_network_site_segment()}.
	 *
	 * @param string $run_id Parent job id.
	 * @return void
	 */
	public static function run_network_aggregate( $run_id ) {
		$run_id = WPMAR_Jobs_Repository::sanitize_id( $run_id );
		if ( '' === $run_id ) {
			return;
		}

		WPMAR_Network::on_main_site(
			function () use ( $run_id ) {
				self::run_network_aggregate_on_main_site( $run_id );
			}
		);
	}

	/**
	 * Body of {@see self::run_network_aggregate()}, guaranteed to already be running on
	 * the main site.
	 *
	 * @param string $run_id Parent job id.
	 * @return void
	 */
	protected static function run_network_aggregate_on_main_site( $run_id ) {
		$jobs_repo = new WPMAR_Jobs_Repository();
		$job       = $jobs_repo->find( $run_id );

		if ( null === $job ) {
			return; // Unknown id (purged or never created) — nothing to do.
		}

		$status = isset( $job['status'] ) ? (string) $job['status'] : '';
		if ( ! in_array( $status, array( WPMAR_Jobs_Repository::STATUS_QUEUED, WPMAR_Jobs_Repository::STATUS_RUNNING ), true ) ) {
			return; // Already finalized (or unknown state) - a duplicate/late tick is a no-op.
		}

		if ( WPMAR_Jobs_Repository::STATUS_QUEUED === $status ) {
			$jobs_repo->mark_running( $run_id );
		}

		$segments_repo = new WPMAR_Network_Segments_Repository();

		/**
		 * Filters how long (minutes) a segment may sit `running` with no heartbeat before
		 * this aggregate step force-fails it as abandoned (OOM kill, timeout).
		 *
		 * Unmeasured default: 20 minutes is a guess at "longer than one site's audit
		 * should ever take", not a benchmarked value against real per-site durations.
		 *
		 * @since 1.4.0
		 *
		 * @param int $minutes Default 20.
		 */
		$stale_minutes = (int) apply_filters( 'wpmar_network_segment_stale_minutes', 20 );
		$segments_repo->sweep_stale_running( $run_id, $stale_minutes );

		$rows = $segments_repo->find_by_run( $run_id );

		$incomplete = array_filter(
			$rows,
			static function ( $row ) {
				$row_status = isset( $row['status'] ) ? (string) $row['status'] : '';
				return WPMAR_Network_Segments_Repository::STATUS_QUEUED === $row_status
					|| WPMAR_Network_Segments_Repository::STATUS_RUNNING === $row_status;
			}
		);

		if ( ! empty( $incomplete ) ) {
			$created_ts = isset( $job['created_at'] ) ? strtotime( (string) $job['created_at'] . ' UTC' ) : false;
			$elapsed    = ( false !== $created_ts ) ? ( time() - $created_ts ) : 0;

			/**
			 * Filters the overall wall-clock budget (seconds) a network rollup gets before
			 * this aggregate step stops waiting on stragglers and finalizes with whatever
			 * finished. Unmeasured default: 90 minutes is a guess, not benchmarked against
			 * a real network's per-site audit durations at scale.
			 *
			 * @since 1.4.0
			 *
			 * @param int $seconds Default 90 * MINUTE_IN_SECONDS.
			 */
			$max_wait_sec = (int) apply_filters( 'wpmar_network_aggregate_max_wait', 90 * MINUTE_IN_SECONDS );

			if ( $elapsed < $max_wait_sec ) {
				/**
				 * Filters the delay (seconds) before this aggregate step re-checks segment
				 * status while sites are still `queued`/`running`. Unmeasured default: 3
				 * minutes is a guess at a check-in cadence, not a benchmarked value.
				 *
				 * @since 1.4.0
				 *
				 * @param int $seconds Default 3 * MINUTE_IN_SECONDS.
				 */
				$recheck_delay = (int) apply_filters( 'wpmar_network_aggregate_recheck_delay', 3 * MINUTE_IN_SECONDS );

				if ( wpmar_action_scheduler_available() ) {
					as_schedule_single_action( time() + $recheck_delay, self::HOOK_NETWORK_AGGREGATE, array( $run_id ), self::GROUP );
				}

				return; // Still within the overall wait budget - try again shortly.
			}

			// Global timeout: whatever hasn't finished by now is forced to `failed` so
			// sites that DID finish still get a report instead of waiting forever on
			// stragglers (Action Scheduler wedged, a site that never even started, etc.).
			foreach ( $incomplete as $row ) {
				$segments_repo->mark_failed(
					$run_id,
					absint( $row['blog_id'] ),
					__( 'ネットワーク全体のタイムアウトに達したため、このサイトの処理を打ち切りました。', 'wp-maintenance-audit-reporter' )
				);
			}

			$rows = $segments_repo->find_by_run( $run_id );
		}

		$exec = isset( $job['args_json'] ) ? json_decode( (string) $job['args_json'], true ) : array();
		if ( ! is_array( $exec ) ) {
			$exec = array();
		}

		$delivery     = WPMAR_Network_Settings::rollup_delivery_settings();
		$blog_ids     = array_values(
			array_unique(
				array_map(
					static function ( $row ) {
						return absint( $row['blog_id'] );
					},
					$rows
				)
			)
		);
		$created_ts   = isset( $job['created_at'] ) ? strtotime( (string) $job['created_at'] . ' UTC' ) : false;
		$duration_sec = ( false !== $created_ts ) ? max( 0, time() - $created_ts ) : 0;

		$result = WPMAR_Network_Runner::finalize_rollup( $rows, $exec, $delivery, $blog_ids, $duration_sec );

		$jobs_repo->mark_done( $run_id, $result );
		$segments_repo->delete_by_run( $run_id );
		delete_site_transient( WPMAR_Network_Runner::LOCK_TRANSIENT );
	}
}
