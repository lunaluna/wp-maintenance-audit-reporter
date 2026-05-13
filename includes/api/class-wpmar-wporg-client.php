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
	 * Plugin_information JSON payload (slug).
	 *
	 * @param string $slug Plugin slug.
	 * @return array<string,mixed>|null
	 */
	public function fetch_plugin_information( $slug ) {
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

		return is_array( $body ) ? $body : null;
	}

	/**
	 * Theme information JSON payload.
	 *
	 * @param string $slug Theme slug.
	 * @return array<string,mixed>|null
	 */
	public function fetch_theme_information( $slug ) {
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

		return is_array( $body ) ? $body : null;
	}
}
