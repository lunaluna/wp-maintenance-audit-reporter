<?php
/**
 * WordPress.org Plugins/Themes REST helpers (respectful pacing).
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads directory metadata via HTTP GET.
 */
class WPMAR_WPOrg_Client {

	/**
	 * Microseconds throttle between outbound calls.
	 *
	 * @var int
	 */
	protected $delay_us;

	/**
	 * Cache hits observed by this instance, for hit-rate visibility in logs.
	 *
	 * @var int
	 */
	protected $cache_hits = 0;

	/**
	 * Cache misses observed by this instance, for hit-rate visibility in logs.
	 *
	 * @var int
	 */
	protected $cache_misses = 0;

	/**
	 * Constructor with optional pacing.
	 *
	 * @param int $delay_microseconds Pause between sequential requests.
	 */
	public function __construct( $delay_microseconds = 200000 ) {
		$this->delay_us = max( 0, (int) $delay_microseconds );
	}

	/**
	 * Sleeps courteously between directory hits.
	 *
	 * @return void
	 */
	protected function pace() {
		if ( $this->delay_us > 0 ) {
			usleep( $this->delay_us );
		}
	}

	/**
	 * Cache hit/miss counters accumulated by this instance.
	 *
	 * @return array{hits:int,misses:int}
	 */
	public function get_cache_stats() {
		return array(
			'hits'   => $this->cache_hits,
			'misses' => $this->cache_misses,
		);
	}

	/**
	 * Site-wide cache key for a directory metadata lookup.
	 *
	 * Uses a `site_transient` (not a per-blog `transient`) so a multisite network's
	 * site-segment jobs share one cache: the first site to ask for a given plugin/theme
	 * slug primes it, and every other site hits cache instead of re-querying wp.org.
	 *
	 * @param string $type Either `plugin` or `theme`.
	 * @param string $slug Plugin or theme slug.
	 * @return string
	 */
	protected function cache_key_for( $type, $slug ) {
		return 'wpmar_wporg_' . $type . '_' . sanitize_key( $slug );
	}

	/**
	 * TTL (seconds) for cached wp.org directory metadata.
	 *
	 * Unmeasured default: wp.org metadata (notably `last_updated`) does not change
	 * within a day in practice, but 12h has not been benchmarked against real update
	 * cadence. Adjust via the filter if cache-hit logs (`gather:inventory-done`) show
	 * this is too short or too long for a given site's audit frequency.
	 *
	 * @return int
	 */
	protected static function cache_ttl() {
		return (int) apply_filters( 'wpmar_wporg_cache_ttl', 12 * HOUR_IN_SECONDS );
	}

	/**
	 * Plugin_information JSON payload (slug).
	 *
	 * @param string $slug Plugin slug.
	 * @return array<string,mixed>|null
	 */
	public function fetch_plugin_information( $slug ) {
		$cache_key = $this->cache_key_for( 'plugin', $slug );
		$cached    = get_site_transient( $cache_key );
		if ( is_array( $cached ) ) {
			++$this->cache_hits;
			return $cached;
		}
		++$this->cache_misses;

		$this->pace();
		$url  = sprintf(
			'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=%s',
			rawurlencode( $slug )
		);
		$resp = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( ! is_array( $body ) ) {
			return null;
		}

		set_site_transient( $cache_key, $body, self::cache_ttl() );

		return $body;
	}

	/**
	 * Theme information JSON payload.
	 *
	 * @param string $slug Theme slug.
	 * @return array<string,mixed>|null
	 */
	public function fetch_theme_information( $slug ) {
		$cache_key = $this->cache_key_for( 'theme', $slug );
		$cached    = get_site_transient( $cache_key );
		if ( is_array( $cached ) ) {
			++$this->cache_hits;
			return $cached;
		}
		++$this->cache_misses;

		$this->pace();
		$url  = sprintf(
			'https://api.wordpress.org/themes/info/1.2/?action=theme_information&request[slug]=%s',
			rawurlencode( $slug )
		);
		$resp = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( ! is_array( $body ) ) {
			return null;
		}

		set_site_transient( $cache_key, $body, self::cache_ttl() );

		return $body;
	}
}
