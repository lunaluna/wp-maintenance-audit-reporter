<?php
/**
 * Lightweight probe of WP-CLI runtime (no shell/exec).
 *
 * Used by the runner on CLI requests and surfaced on the settings screen.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists CLI metadata in a single autoloaded option array.
 */
class WPMAR_CLI_Environment {

	/** Option key holding the associative payload. */
	const OPTION_NAME = 'wpmar_cli_environment';

	/**
	 * Captures runtime details when PHP is booted under WP‑CLI.
	 *
	 * @return void
	 */
	public static function maybe_capture() {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		// Snapshot is intentionally shallow - enough to prove CLI reachability during audits.
		$payload = array(
			'is_available'   => true,
			'wp_cli_version' => defined( 'WP_CLI_VERSION' ) ? (string) WP_CLI_VERSION : '',
			'php_version'    => PHP_VERSION,
			'php_binary'     => defined( 'PHP_BINARY' ) ? (string) PHP_BINARY : '',
			'last_seen_at'   => gmdate( 'c' ),
		);

		update_option(
			self::OPTION_NAME,
			$payload,
			true
		);
	}

	/**
	 * Returns stored payload merged with defaults when missing.
	 *
	 * @return array<string,mixed>
	 */
	public static function snapshot() {
		$stored = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		// Guarantees predictable keys even before the first CLI capture.
		return wp_parse_args(
			$stored,
			array(
				'is_available'   => false,
				'wp_cli_version' => '',
				'php_version'    => '',
				'php_binary'     => '',
				'last_seen_at'   => '',
			)
		);
	}
}
