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
 * Compares configured host with site's home URL host (and optional path prefix for subdirectory multisite).
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
	 * Path component of home_url (without leading slash), lowercased.
	 *
	 * @return string
	 */
	public static function current_path() {
		$parsed = wp_parse_url( home_url(), PHP_URL_PATH );
		if ( ! is_string( $parsed ) ) {
			return '';
		}

		return trim( strtolower( untrailingslashit( $parsed ) ), '/' );
	}

	/**
	 * Whether this site may run audits (allowed host equals current host).
	 *
	 * @param array<string,mixed> $settings Merged plugin settings with `domain.allowed_host`.
	 * @return bool
	 */
	public static function is_allowed( array $settings ) {
		$domain  = isset( $settings['domain'] ) && is_array( $settings['domain'] ) ? $settings['domain'] : array();
		$allowed = isset( $domain['allowed_host'] ) ? strtolower( sanitize_text_field( $domain['allowed_host'] ) ) : '';
		$curr    = self::current_host();

		if ( '' === $allowed ) {
			// Undefined gate: permissive fallback (explicit host recommended).
			return true;
		}

		return $curr === $allowed;
	}

	/**
	 * Domain gate settings for a blog during network rollup (site settings + network domain fallback).
	 *
	 * @param array<string,mixed> $site_settings     Blog-level {@see WPMAR_Settings::get_all()}.
	 * @param array<string,mixed> $network_settings  Network settings envelope.
	 * @return array<string,mixed> Settings array suitable for {@see self::is_allowed()}.
	 */
	public static function merge_network_gate_settings( array $site_settings, array $network_settings ) {
		$merged = $site_settings;

		if ( ! isset( $merged['domain'] ) || ! is_array( $merged['domain'] ) ) {
			$merged['domain'] = array(
				'allowed_host' => '',
			);
		}

		$network_domain = isset( $network_settings['domain'] ) && is_array( $network_settings['domain'] )
			? $network_settings['domain']
			: array();

		if ( '' === trim( (string) $merged['domain']['allowed_host'] ) && ! empty( $network_domain['allowed_host'] ) ) {
			$merged['domain']['allowed_host'] = sanitize_text_field( (string) $network_domain['allowed_host'] );
		}

		return $merged;
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
		$path       = self::current_path();

		if ( '' === $saved_norm ) {
			$state = 'empty';
		} elseif ( ! self::is_allowed( $settings ) ) {
			$state = 'mismatch';
		} else {
			$state = 'match';
		}

		return array(
			'detected'      => $detected,
			'detected_path' => $path,
			'saved_display' => $saved_raw,
			'saved_norm'    => $saved_norm,
			'state'         => $state,
		);
	}
}
