<?php
/**
 * Minimal WordPress function stubs for PHPUnit without full WP bootstrap.
 *
 * @package WPMAR\Tests
 */

if ( ! function_exists( '__' ) ) {
	/**
	 * Stub translate.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function __( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return (string) $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Stub esc_html translate.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html__( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return (string) $text;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Stub sanitize_text_field.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function sanitize_text_field( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return trim( (string) $text );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Stub esc_url_raw.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url_raw( $url ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return (string) $url;
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Stub absint.
	 *
	 * @param mixed $maybeint Value.
	 * @return int
	 */
	function absint( $maybeint ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return abs( (int) $maybeint );
	}
}
