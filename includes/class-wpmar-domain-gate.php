<?php
/**
 * Hostname gate (production vs staging).
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares configured host with site's home URL host.
 */
class WPMAR_Domain_Gate {

	/**
	 * Current home_url host lowercased, or empty when unknown.
	 *
	 * @return string
	 */
	public static function current_host() {
		$parsed = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $parsed ) ? strtolower( $parsed ) : '';
	}

	/**
	 * Whether this site may run audits (allowed host equals current host).
	 *
	 * @param array<string,mixed> $settings Merged plugin settings with `domain.allowed_host`.
	 * @return bool
	 */
	public static function is_allowed( array $settings ) {
		$allowed = isset( $settings['domain']['allowed_host'] ) ? strtolower( sanitize_text_field( $settings['domain']['allowed_host'] ) ) : '';
		$curr    = self::current_host();

		if ( '' === $allowed ) {
			// Undefined gate: permissive fallback (explicit host recommended).
			return true;
		}

		return ( $curr === $allowed );
	}

	/**
	 * Context for the settings screen callout (host-only, matches {@see self::is_allowed()}).
	 *
	 * @param array<string,mixed> $settings Merged plugin settings.
	 * @return array<string,string> Keys: detected, saved_display, saved_norm, state (empty|match|mismatch).
	 */
	public static function admin_gate_callout_context( array $settings ) {
		$saved_raw  = isset( $settings['domain']['allowed_host'] ) ? trim( (string) $settings['domain']['allowed_host'] ) : '';
		$saved_norm = '' === $saved_raw ? '' : strtolower( sanitize_text_field( $saved_raw ) );
		$detected   = self::current_host();

		if ( '' === $saved_norm ) {
			$state = 'empty';
		} elseif ( $detected === $saved_norm ) {
			$state = 'match';
		} else {
			$state = 'mismatch';
		}

		return array(
			'detected'       => $detected,
			'saved_display'    => $saved_raw,
			'saved_norm'       => $saved_norm,
			'state'            => $state,
		);
	}
}
