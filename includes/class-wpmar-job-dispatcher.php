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
	 * Action Scheduler group, for grouping/filtering in the admin queue screen.
	 */
	const GROUP = 'wpmar';

	/**
	 * Registers the queue callback. Runs in every context so Action Scheduler can
	 * dispatch the job regardless of who triggers the queue (web cron, WP-CLI, etc.).
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_audit_job' ), 10, 1 );
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

		$repo = new WPMAR_Jobs_Repository();
		if ( ! $repo->create( $job_id, $args, $scope ) ) {
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

		try {
			if ( 'network' === $scope ) {
				$runner = new WPMAR_Network_Runner();
				$result = $runner->run( $args );
			} else {
				$runner = new WPMAR_Runner();
				$result = $runner->run( $args );
			}

			$repo->mark_done( $job_id, is_array( $result ) ? $result : array() );
		} catch ( Throwable $e ) {
			$repo->mark_failed( $job_id, $e->getMessage() );

			if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- opt-in logging under WP_DEBUG / WP_DEBUG_LOG.
				error_log( 'WPMAR async audit job ' . $job_id . ' failed: ' . $e->getMessage() );
			}
		}
	}
}
