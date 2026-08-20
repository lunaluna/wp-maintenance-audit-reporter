<?php
/**
 * Minimal WordPress function stubs for PHPUnit without full WP bootstrap.
 *
 * @package WPMAR\Tests
 */

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

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

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Stub wp_unslash.
	 *
	 * @param string|array $value Value to unslash.
	 * @return string|array
	 */
	function wp_unslash( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}
		return stripslashes( (string) $value );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	/**
	 * Stub sanitize_email.
	 *
	 * @param string $email Email to sanitize.
	 * @return string
	 */
	function sanitize_email( $email ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return trim( (string) $email );
	}
}

if ( ! function_exists( 'is_email' ) ) {
	/**
	 * Stub is_email.
	 *
	 * @param string $email Email to validate.
	 * @return bool
	 */
	function is_email( $email ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	/**
	 * Stub sanitize_file_name.
	 *
	 * @param string $filename Filename to sanitize.
	 * @return string
	 */
	function sanitize_file_name( $filename ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$filename = preg_replace( '/[\/\\\\\x00-\x1F]/', '', (string) $filename );
		return trim( (string) $filename, '.-_ ' );
	}
}

if ( ! function_exists( 'nocache_headers' ) ) {
	/**
	 * Stub nocache_headers — records that it was called for assertions.
	 *
	 * @return void
	 */
	function nocache_headers() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$GLOBALS['_wpmar_test_nocache_headers_called'] = true;
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	/**
	 * Stub wp_parse_args.
	 *
	 * @param array<string,mixed>|string $args     Value to parse.
	 * @param array<string,mixed>        $defaults Defaults merged under $args.
	 * @return array<string,mixed>
	 */
	function wp_parse_args( $args, $defaults = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( is_array( $args ) ) {
			return array_merge( $defaults, $args );
		}
		parse_str( (string) $args, $parsed );

		return array_merge( $defaults, $parsed );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Stub sanitize_key.
	 *
	 * @param string $key Key to sanitize.
	 * @return string
	 */
	function sanitize_key( $key ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Stub wp_parse_url.
	 *
	 * @param string $url       URL to parse.
	 * @param int    $component PHP_URL_* constant or -1 for all parts.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return parse_url( (string) $url, $component );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Stub home_url — set $GLOBALS['_wpmar_test_home_url'] to configure per-test.
	 *
	 * @param string $path Optional path to append.
	 * @return string
	 */
	function home_url( $path = '' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$base = isset( $GLOBALS['_wpmar_test_home_url'] ) ? $GLOBALS['_wpmar_test_home_url'] : 'https://test.example.com';
		if ( '' === $path ) {
			return $base;
		}
		return rtrim( $base, '/' ) . '/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * Stub untrailingslashit.
	 *
	 * @param string $string String to process.
	 * @return string
	 */
	function untrailingslashit( $string ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return rtrim( (string) $string, '/\\' );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * Stub trailingslashit.
	 *
	 * @param string $string String to process.
	 * @return string
	 */
	function trailingslashit( $string ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return rtrim( (string) $string, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	/**
	 * Stub wp_normalize_path.
	 *
	 * @param string $path Path to normalise.
	 * @return string
	 */
	function wp_normalize_path( $path ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$path = str_replace( '\\', '/', (string) $path );
		$path = preg_replace( '|(?<=.)/+|', '/', $path );

		if ( ':' === substr( $path, 1, 1 ) ) {
			$path = ucfirst( $path );
		}

		return $path;
	}
}

if ( ! function_exists( 'path_join' ) ) {
	/**
	 * Stub path_join.
	 *
	 * @param string $base Base path.
	 * @param string $path Path to append.
	 * @return string
	 */
	function path_join( $base, $path ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( '' === (string) $base ) {
			return (string) $path;
		}
		return rtrim( (string) $base, '/' ) . '/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	/**
	 * Stub wp_mkdir_p.
	 *
	 * @param string $target Directory to create.
	 * @return bool
	 */
	function wp_mkdir_p( $target ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( is_dir( $target ) ) {
			return true;
		}
		return mkdir( $target, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	/**
	 * Stub wp_upload_dir — set $GLOBALS['_wpmar_test_upload_basedir'] to configure per-test.
	 *
	 * @return array<string,mixed>
	 */
	function wp_upload_dir() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$basedir = isset( $GLOBALS['_wpmar_test_upload_basedir'] )
			? (string) $GLOBALS['_wpmar_test_upload_basedir']
			: rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-test-uploads';

		return array(
			'basedir' => $basedir,
			'error'   => false,
		);
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	/**
	 * Stub is_multisite — set $GLOBALS['_wpmar_test_is_multisite'] to configure per-test.
	 *
	 * @return bool
	 */
	function is_multisite() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return ! empty( $GLOBALS['_wpmar_test_is_multisite'] );
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	/**
	 * Stub get_current_blog_id — set $GLOBALS['_wpmar_test_current_blog_id'] to configure per-test.
	 *
	 * @return int
	 */
	function get_current_blog_id() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return isset( $GLOBALS['_wpmar_test_current_blog_id'] ) ? (int) $GLOBALS['_wpmar_test_current_blog_id'] : 1;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * In-memory option store backed by $GLOBALS['_wpmar_test_options'].
	 *
	 * @param string $name           Option name.
	 * @param mixed  $default_value  Default when absent.
	 * @return mixed
	 */
	function get_option( $name, $default_value = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( ! isset( $GLOBALS['_wpmar_test_options'] ) || ! is_array( $GLOBALS['_wpmar_test_options'] ) ) {
			$GLOBALS['_wpmar_test_options'] = array();
		}
		return array_key_exists( $name, $GLOBALS['_wpmar_test_options'] ) ? $GLOBALS['_wpmar_test_options'][ $name ] : $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Stores an option in the in-memory store.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Value.
	 * @param bool   $autoload Ignored.
	 * @return bool
	 */
	function update_option( $name, $value, $autoload = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $autoload );
		if ( ! isset( $GLOBALS['_wpmar_test_options'] ) || ! is_array( $GLOBALS['_wpmar_test_options'] ) ) {
			$GLOBALS['_wpmar_test_options'] = array();
		}
		$GLOBALS['_wpmar_test_options'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Removes an option from the in-memory store.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	function delete_option( $name ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $GLOBALS['_wpmar_test_options'][ $name ] );

		return true;
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	/**
	 * Stub wp_generate_password (alnum-only; special-char args are ignored).
	 *
	 * @param int  $length              Password length.
	 * @param bool $special_chars       Ignored.
	 * @param bool $extra_special_chars Ignored.
	 * @return string
	 */
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $special_chars, $extra_special_chars );
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$out   = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}
		return $out;
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	/**
	 * Stub wp_delete_file.
	 *
	 * @param string $file Absolute file path.
	 * @return void
	 */
	function wp_delete_file( $file ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( is_string( $file ) && file_exists( $file ) ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
		}
	}
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Stub wp_json_encode.
	 *
	 * @param mixed $data    Data to encode.
	 * @param int   $options JSON options.
	 * @param int   $depth   Max depth.
	 * @return string|false
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stub.
	 */
	class WP_Error { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
		/** @var string */
		private $code;
		/** @var string */
		private $message;
		/** @var mixed */
		private $data;

		/**
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Optional data.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		/** @return string */
		public function get_error_code() {
			return $this->code;
		}

		/** @return string */
		public function get_error_message() {
			return $this->message;
		}

		/** @return mixed */
		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Stub is_wp_error.
	 *
	 * @param mixed $thing Value to test.
	 * @return bool
	 */
	function is_wp_error( $thing ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wpmar_action_scheduler_available' ) ) {
	/**
	 * Test double for the Action Scheduler availability gate.
	 * Toggle via $GLOBALS['_wpmar_test_as_available'].
	 *
	 * @return bool
	 */
	function wpmar_action_scheduler_available() {
		return ! empty( $GLOBALS['_wpmar_test_as_available'] );
	}
}

if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	/**
	 * Records enqueue calls for assertions.
	 *
	 * @param string            $hook  Hook name.
	 * @param array<int,mixed>  $args  Hook args.
	 * @param string            $group Group.
	 * @return int
	 */
	function as_enqueue_async_action( $hook, $args = array(), $group = '' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( ! isset( $GLOBALS['_wpmar_test_as_calls'] ) || ! is_array( $GLOBALS['_wpmar_test_as_calls'] ) ) {
			$GLOBALS['_wpmar_test_as_calls'] = array();
		}
		$GLOBALS['_wpmar_test_as_calls'][] = array( $hook, $args, $group );

		return 1;
	}
}

if ( ! function_exists( 'get_file_data' ) ) {
	/**
	 * Stub get_file_data: parses "Header Label: value" lines from a file's
	 * leading doc comment, the same style WordPress plugin/theme headers use.
	 *
	 * @param  string $file            Path to the file to read.
	 * @param  array  $default_headers Map of return key => header label.
	 * @return array
	 */
	function get_file_data( $file, $default_headers ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$contents = (string) file_get_contents( $file, false, null, 0, 8192 );
		$data     = array();

		foreach ( $default_headers as $field => $label ) {
			$value = '';
			if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':(.*)$/mi', $contents, $matches ) ) {
				$value = trim( $matches[1] );
			}
			$data[ $field ] = $value;
		}

		return $data;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * In-memory transient store backed by $GLOBALS['_wpmar_test_transients'].
	 *
	 * @param string $transient Transient key.
	 * @return mixed False when absent.
	 */
	function get_transient( $transient ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( isset( $GLOBALS['_wpmar_test_transients'][ $transient ] ) ) {
			return $GLOBALS['_wpmar_test_transients'][ $transient ];
		}
		return false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Stores a transient in the in-memory store (expiration ignored).
	 *
	 * @param string $transient  Transient key.
	 * @param mixed  $value      Value.
	 * @param int    $expiration TTL (ignored).
	 * @return bool
	 */
	function set_transient( $transient, $value, $expiration = 0 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $expiration );
		if ( ! isset( $GLOBALS['_wpmar_test_transients'] ) || ! is_array( $GLOBALS['_wpmar_test_transients'] ) ) {
			$GLOBALS['_wpmar_test_transients'] = array();
		}
		$GLOBALS['_wpmar_test_transients'][ $transient ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * Removes a transient from the in-memory store.
	 *
	 * @param string $transient Transient key.
	 * @return bool
	 */
	function delete_transient( $transient ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $GLOBALS['_wpmar_test_transients'][ $transient ] );

		return true;
	}
}

if ( ! function_exists( 'get_site_transient' ) ) {
	/**
	 * In-memory site-transient store backed by $GLOBALS['_wpmar_test_site_transients'].
	 * Kept separate from get_transient()'s store since real WP backs the two
	 * with different tables/semantics (network-wide vs per-blog).
	 *
	 * @param string $transient Transient key.
	 * @return mixed False when absent.
	 */
	function get_site_transient( $transient ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( isset( $GLOBALS['_wpmar_test_site_transients'][ $transient ] ) ) {
			return $GLOBALS['_wpmar_test_site_transients'][ $transient ];
		}
		return false;
	}
}

if ( ! function_exists( 'set_site_transient' ) ) {
	/**
	 * Stores a site transient in the in-memory store (expiration ignored).
	 *
	 * @param string $transient  Transient key.
	 * @param mixed  $value      Value.
	 * @param int    $expiration TTL (ignored).
	 * @return bool
	 */
	function set_site_transient( $transient, $value, $expiration = 0 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $expiration );
		if ( ! isset( $GLOBALS['_wpmar_test_site_transients'] ) || ! is_array( $GLOBALS['_wpmar_test_site_transients'] ) ) {
			$GLOBALS['_wpmar_test_site_transients'] = array();
		}
		$GLOBALS['_wpmar_test_site_transients'][ $transient ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_site_transient' ) ) {
	/**
	 * Removes a site transient from the in-memory store.
	 *
	 * @param string $transient Transient key.
	 * @return bool
	 */
	function delete_site_transient( $transient ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $GLOBALS['_wpmar_test_site_transients'][ $transient ] );

		return true;
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	/**
	 * Stub plugin_basename — set $GLOBALS['_wpmar_test_plugin_basename'] to
	 * configure per-test; otherwise falls back to WP's real behaviour for a
	 * plugin living in its own directory (last two path segments).
	 *
	 * @param string $file Absolute path to a plugin file.
	 * @return string
	 */
	function plugin_basename( $file ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( isset( $GLOBALS['_wpmar_test_plugin_basename'] ) ) {
			return (string) $GLOBALS['_wpmar_test_plugin_basename'];
		}
		$file = str_replace( '\\', '/', (string) $file );
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	/**
	 * Stub _doing_it_wrong — records calls for assertions instead of raising
	 * a PHP notice.
	 *
	 * @param string $function_name Function/method name.
	 * @param string $message       Message.
	 * @param string $version       Version.
	 * @return void
	 */
	function _doing_it_wrong( $function_name, $message, $version ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( ! isset( $GLOBALS['_wpmar_test_doing_it_wrong'] ) || ! is_array( $GLOBALS['_wpmar_test_doing_it_wrong'] ) ) {
			$GLOBALS['_wpmar_test_doing_it_wrong'] = array();
		}
		$GLOBALS['_wpmar_test_doing_it_wrong'][] = array( $function_name, $message, $version );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Stub esc_html.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Stub esc_url.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url( $url ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * Fake HTTP GET: records calls, replies with a canned response.
	 *
	 * Configure via $GLOBALS['_wpmar_test_http_response'] (WP_Error or a
	 * response array); defaults to HTTP 200.
	 *
	 * @param string              $url  Request URL.
	 * @param array<string,mixed> $args Request args.
	 * @return array<string,mixed>|WP_Error
	 */
	function wp_remote_get( $url, $args = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( ! isset( $GLOBALS['_wpmar_test_http_calls'] ) || ! is_array( $GLOBALS['_wpmar_test_http_calls'] ) ) {
			$GLOBALS['_wpmar_test_http_calls'] = array();
		}
		$GLOBALS['_wpmar_test_http_calls'][] = array( $url, $args );

		if ( isset( $GLOBALS['_wpmar_test_http_response'] ) ) {
			return $GLOBALS['_wpmar_test_http_response'];
		}

		return array(
			'response' => array( 'code' => 200 ),
			'body'     => '',
		);
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * Stub wp_remote_retrieve_body.
	 *
	 * @param array<string,mixed>|WP_Error $response HTTP response.
	 * @return string
	 */
	function wp_remote_retrieve_body( $response ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( is_wp_error( $response ) || ! isset( $response['body'] ) ) {
			return '';
		}
		return (string) $response['body'];
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * Stub admin_url.
	 *
	 * @param string $path Path relative to wp-admin.
	 * @return string
	 */
	function admin_url( $path = '' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Pass-through apply_filters by default (matches every existing test's
	 * expectation that registering a filter never makes it fire).
	 *
	 * Set $GLOBALS['_wpmar_test_apply_filters_functional'] = true to opt a
	 * test into actually invoking callbacks recorded by add_filter()/
	 * add_action() instead — needed by tests that must verify a filter hook
	 * really changes a return value. Opt-in (rather than always-functional)
	 * so this stays a no-risk addition for the many tests that don't expect
	 * their registered filters to run.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  $value     Value.
	 * @param mixed  ...$args   Extra args passed through to callbacks when functional.
	 * @return mixed
	 */
	function apply_filters( $hook_name, $value, ...$args ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( empty( $GLOBALS['_wpmar_test_apply_filters_functional'] ) || empty( $GLOBALS['_wpmar_test_filters'] ) ) {
			return $value;
		}
		foreach ( $GLOBALS['_wpmar_test_filters'] as $registration ) {
			if ( 'add' === $registration[0] && $hook_name === $registration[1] ) {
				$value = call_user_func( $registration[3], $value, ...$args );
			}
		}
		return $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Records filter registrations. The callback is kept (4th tuple element)
	 * so tests can pull the registered object back out via Reflection; the
	 * stubs themselves never invoke it (apply_filters() below is a pass-through).
	 *
	 * @param string   $hook_name     Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted args.
	 * @return bool
	 */
	function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $accepted_args );
		if ( ! isset( $GLOBALS['_wpmar_test_filters'] ) || ! is_array( $GLOBALS['_wpmar_test_filters'] ) ) {
			$GLOBALS['_wpmar_test_filters'] = array();
		}
		$GLOBALS['_wpmar_test_filters'][] = array( 'add', $hook_name, $priority, $callback );

		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Stub add_action — WordPress core implements add_action() as add_filter()
	 * under the hood (both share the same hook registry), so this delegates.
	 *
	 * @param string   $hook_name     Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted args.
	 * @return bool
	 */
	function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return add_filter( $hook_name, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	/**
	 * Records filter removals.
	 *
	 * @param string   $hook_name Hook name.
	 * @param callable $callback  Callback.
	 * @param int      $priority  Priority.
	 * @return bool
	 */
	function remove_filter( $hook_name, $callback, $priority = 10 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $callback );
		if ( ! isset( $GLOBALS['_wpmar_test_filters'] ) || ! is_array( $GLOBALS['_wpmar_test_filters'] ) ) {
			$GLOBALS['_wpmar_test_filters'] = array();
		}
		$GLOBALS['_wpmar_test_filters'][] = array( 'remove', $hook_name, $priority );

		return true;
	}
}

if ( ! class_exists( 'ActionScheduler' ) ) {
	/**
	 * Fake ActionScheduler facade: hands out the runner configured by a test.
	 */
	class ActionScheduler { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, Generic.Files.OneObjectStructurePerFile.MultipleFound
		/**
		 * Returns the test-provided queue runner double.
		 *
		 * @return object|null
		 */
		public static function runner() {
			return isset( $GLOBALS['_wpmar_test_as_runner'] ) ? $GLOBALS['_wpmar_test_as_runner'] : null;
		}
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	/**
	 * Fake HTTP POST: records calls, replies with a canned response.
	 *
	 * Configure via $GLOBALS['_wpmar_test_http_response'] (WP_Error or a
	 * response array); defaults to HTTP 200.
	 *
	 * @param string              $url  Request URL.
	 * @param array<string,mixed> $args Request args.
	 * @return array<string,mixed>|WP_Error
	 */
	function wp_remote_post( $url, $args = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( ! isset( $GLOBALS['_wpmar_test_http_calls'] ) || ! is_array( $GLOBALS['_wpmar_test_http_calls'] ) ) {
			$GLOBALS['_wpmar_test_http_calls'] = array();
		}
		$GLOBALS['_wpmar_test_http_calls'][] = array( $url, $args );

		if ( isset( $GLOBALS['_wpmar_test_http_response'] ) ) {
			return $GLOBALS['_wpmar_test_http_response'];
		}

		return array(
			'response' => array( 'code' => 200 ),
		);
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * Stub wp_remote_retrieve_response_code.
	 *
	 * @param array<string,mixed>|WP_Error $response HTTP response.
	 * @return int|string
	 */
	function wp_remote_retrieve_response_code( $response ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( is_wp_error( $response ) || ! isset( $response['response']['code'] ) ) {
			return '';
		}
		return (int) $response['response']['code'];
	}
}

if ( ! class_exists( 'WPMAR_Test_Fake_Wpdb' ) ) {
	/**
	 * Minimal in-memory wpdb double for repository/dispatcher tests.
	 */
	class WPMAR_Test_Fake_Wpdb { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
		/** @var string */
		public $prefix = 'wp_';
		/** @var int */
		public $insert_id = 0;
		/** @var array<string,array<string,mixed>> Keyed by id. */
		public $rows = array();
		/** @var array<int,array<string,mixed>> */
		public $insert_calls = array();
		/** @var array<int,array<int,mixed>> */
		public $update_calls = array();

		/**
		 * @param string $query Query with placeholders.
		 * @param mixed  ...$args Bound args.
		 * @return array{0:string,1:array<int,mixed>}
		 */
		public function prepare( $query, ...$args ) {
			// Flatten a single array arg (wpdb accepts both forms).
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			return array( $query, $args );
		}

		/**
		 * @param array{0:string,1:array<int,mixed>}|string $prepared Prepared tuple.
		 * @param mixed                                      $output   Output type (ignored).
		 * @return array<string,mixed>|null
		 */
		public function get_row( $prepared, $output = null ) {
			unset( $output );
			$args = is_array( $prepared ) && isset( $prepared[1] ) ? $prepared[1] : array();
			$id   = isset( $args[0] ) ? (string) $args[0] : '';
			return isset( $this->rows[ $id ] ) ? $this->rows[ $id ] : null;
		}

		/**
		 * @param string             $table   Table.
		 * @param array<string,mixed> $data    Data.
		 * @param array<int,string>  $formats Formats.
		 * @return int
		 */
		public function insert( $table, $data, $formats = null ) {
			unset( $table, $formats );
			$this->insert_calls[] = $data;
			return 1;
		}

		/**
		 * @param string             $table       Table.
		 * @param array<string,mixed> $data        Data.
		 * @param array<string,mixed> $where       Where.
		 * @param array<int,string>  $data_format  Formats.
		 * @param array<int,string>  $where_format Where formats.
		 * @return int
		 */
		public function update( $table, $data, $where, $data_format = null, $where_format = null ) {
			unset( $table, $data_format, $where_format );
			$this->update_calls[] = array( $data, $where );
			return 1;
		}

		/**
		 * @param string $query Query.
		 * @return int
		 */
		public function get_var( $query ) {
			unset( $query );
			return 0;
		}

		/**
		 * @param string $query Query.
		 * @return int
		 */
		public function query( $query ) {
			unset( $query );
			return 0;
		}
	}
}
