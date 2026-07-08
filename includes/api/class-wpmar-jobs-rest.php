<?php
/**
 * REST endpoint the admin polling UI hits to track an async audit job.
 *
 * Exposes a single read-only route, `GET /wpmar/v1/jobs/<id>`, returning the job's
 * lifecycle state and — once finished — the report id plus nonce-signed download
 * URLs (reusing the same `wpmar_download` routes as the reports screen).
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and serves the job-status REST route.
 */
class WPMAR_Jobs_REST {

	/**
	 * REST namespace.
	 */
	const NAMESPACE_V1 = 'wpmar/v1';

	/**
	 * Hooks route registration onto rest_api_init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers the job-status route.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs/(?P<id>[a-z0-9.]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_job' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => array( 'WPMAR_Jobs_Repository', 'sanitize_id' ),
					),
				),
			)
		);
	}

	/**
	 * Only operators who can trigger audits may poll job state.
	 *
	 * @return bool
	 */
	public static function can_read() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Returns the job status envelope.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_job( WP_REST_Request $request ) {
		$id = WPMAR_Jobs_Repository::sanitize_id( (string) $request['id'] );

		$repo = new WPMAR_Jobs_Repository();

		// Opportunistic backstop: a job killed hard enough that no catch/finally or
		// shutdown handler ever ran (SIGKILL, OOM killer) would otherwise sit as
		// `running` forever. Only costs a query when someone is actively polling.
		$repo->sweep_stale_running();

		$job = $repo->find( $id );

		if ( null === $job ) {
			return new WP_Error(
				'wpmar_job_not_found',
				__( '指定されたジョブは存在しません。', 'wp-maintenance-audit-reporter' ),
				array( 'status' => 404 )
			);
		}

		$status   = isset( $job['status'] ) ? (string) $job['status'] : '';
		$scope    = isset( $job['scope'] ) ? (string) $job['scope'] : 'single';
		$log_path = isset( $job['log_path'] ) ? (string) $job['log_path'] : '';

		$payload = array(
			'id'               => $id,
			'status'           => $status,
			'scope'            => $scope,
			'step'             => isset( $job['step'] ) ? (string) $job['step'] : '',
			'error'            => isset( $job['error'] ) ? (string) $job['error'] : '',
			'updated_at'       => isset( $job['updated_at'] ) ? (string) $job['updated_at'] : '',
			'log_download_url' => '' !== $log_path ? self::log_download_url( $id ) : '',
			'result'           => null,
		);

		if ( WPMAR_Jobs_Repository::STATUS_DONE === $status ) {
			$result            = isset( $job['result_json'] ) ? json_decode( (string) $job['result_json'], true ) : array();
			$payload['result'] = self::build_result_links( is_array( $result ) ? $result : array() );
		}

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * Builds a nonce-signed admin download URL for a job's log file.
	 *
	 * Matches the query-arg contract expected by
	 * {@see WPMAR_Log_Viewer::maybe_stream_log_download()}.
	 *
	 * @param string $job_id Job id.
	 * @return string
	 */
	public static function log_download_url( $job_id ) {
		return add_query_arg(
			array(
				'page'      => WPMAR_REPORTS_PAGE_SLUG,
				'wpmar_log' => $job_id,
				'_wpnonce'  => wp_create_nonce( 'wpmar_dl_log_' . $job_id ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Augments a finished job's result with report URL + download links.
	 *
	 * @param array<string,mixed> $result Decoded runner result.
	 * @return array<string,mixed>
	 */
	protected static function build_result_links( array $result ) {
		// Dry runs carry a compact `dry_brevity` summary plus the full `dry_preview`
		// dataset; only the brevity string is needed by the poller, so drop the bulky
		// preview to keep the REST payload small.
		if ( isset( $result['dry_brevity'] ) ) {
			unset( $result['dry_preview'] );
			return $result;
		}

		$report_id = isset( $result['report_id'] ) ? absint( $result['report_id'] ) : 0;

		if ( $report_id <= 0 ) {
			// Network rollups may not map to a single report row.
			return $result;
		}

		$base = array(
			'page'      => WPMAR_REPORTS_PAGE_SLUG,
			'report_id' => $report_id,
		);

		$result['report_id']  = $report_id;
		$result['report_url'] = add_query_arg( $base, admin_url( 'admin.php' ) );

		$downloads = array(
			'md' => self::download_url( $base, 'md', $report_id ),
		);

		// Mirror the detail screen: prefer client PDF when the library is present,
		// otherwise offer the client Markdown.
		if ( class_exists( 'WPMAR_PDF_Writer' ) && WPMAR_PDF_Writer::is_available() ) {
			$downloads['pdf'] = self::download_url( $base, 'pdf', $report_id );
		} else {
			$downloads['client_md'] = self::download_url( $base, 'client_md', $report_id );
		}

		$result['downloads'] = $downloads;

		return $result;
	}

	/**
	 * Builds a nonce-signed admin download URL matching the reports screen routes.
	 *
	 * Uses add_query_arg + wp_create_nonce (rather than wp_nonce_url, which HTML-encodes
	 * the URL) so the JSON payload carries a raw URL the browser can use directly as an
	 * href or fetch target. The nonce action matches the reports screen verifier.
	 *
	 * @param array<string,mixed> $base      Base query args (page, report_id).
	 * @param string              $type      Download type: md|pdf|client_md.
	 * @param int                 $report_id Report primary key.
	 * @return string
	 */
	protected static function download_url( array $base, $type, $report_id ) {
		$type = sanitize_key( $type );

		return add_query_arg(
			array_merge(
				$base,
				array(
					'wpmar_download' => $type,
					'_wpnonce'       => wp_create_nonce( 'wpmar_dl_' . $type . '_' . $report_id ),
				)
			),
			admin_url( 'admin.php' )
		);
	}
}
