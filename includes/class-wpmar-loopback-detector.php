<?php
/**
 * Detects whether this site can perform loopback HTTP requests.
 *
 * Sites protected by server-level access control (HTTP Basic authentication,
 * IP allow-lists, …) reject the loopback requests WP-Cron and Action Scheduler
 * rely on, so async audit jobs never start. This detector probes the same way
 * core's Site Health does ({@see WP_Site_Health::can_perform_loopback()}) and
 * caches the verdict so callers (job dispatcher, admin notices, polling REST)
 * can branch to an inline fallback without re-probing on every request.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Probes loopback availability and caches the result.
 */
class WPMAR_Loopback_Detector {

	/**
	 * Transient holding the cached probe result.
	 *
	 * A per-site (not network) transient on purpose: on multisite each site can
	 * sit behind different server-level auth, so the verdict must not be shared.
	 */
	const TRANSIENT_KEY = 'wpmar_loopback_status';

	/**
	 * How long a probe verdict stays valid.
	 */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Whether loopback requests reach this site (cached).
	 *
	 * Lazily evaluated: only fires the HTTP probe when no cached verdict exists,
	 * so ordinary page loads never pay for the request.
	 *
	 * @return bool True when loopback works, false when it is blocked.
	 */
	public function is_loopback_available(): bool {
		$cached = get_transient( self::TRANSIENT_KEY );

		if ( is_array( $cached ) && isset( $cached['available'] ) ) {
			return (bool) $cached['available'];
		}

		return $this->run_check();
	}

	/**
	 * Fires the loopback probe and caches the verdict.
	 *
	 * Mirrors core Site Health: POST to admin-ajax.php with local SSL
	 * verification relaxed. Any HTTP answer other than an access-control
	 * rejection (401/403) proves the request reached WordPress, which is all
	 * the queue runner needs.
	 *
	 * @return bool True when loopback works, false when it is blocked.
	 */
	public function run_check(): bool {
		$response = wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 10,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array( 'action' => 'wpmar_loopback_test' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$available   = false;
			$status_code = 0;
		} else {
			$status_code = (int) wp_remote_retrieve_response_code( $response );
			$available   = ! in_array( $status_code, array( 401, 403 ), true );
		}

		set_transient(
			self::TRANSIENT_KEY,
			array(
				'available'   => $available,
				'status_code' => $status_code,
				'checked_at'  => time(),
			),
			self::CACHE_TTL
		);

		return $available;
	}

	/**
	 * Discards the cached verdict so the next query re-probes (re-check button).
	 *
	 * @return void
	 */
	public function flush_cache(): void {
		delete_transient( self::TRANSIENT_KEY );
	}
}
