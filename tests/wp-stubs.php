<?php
/**
 * Minimal WordPress function stubs for PHPUnit without full WP bootstrap.
 *
 * @package WPMAR\Tests
 */

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
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
		// Opt-in override for tests exercising an encode-failure fallback path — real
		// json_encode() with JSON_PARTIAL_OUTPUT_ON_ERROR substitutes invalid values
		// rather than returning false, so genuinely malformed input can't reach that
		// path; this lets a test force the false/'' result the production code guards against.
		if ( array_key_exists( '_wpmar_test_json_encode_return', $GLOBALS ) ) {
			return $GLOBALS['_wpmar_test_json_encode_return'];
		}
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
	 * Records filter removals (keeps the callback too, so has_filter() below can
	 * tell whether a specific callback is still registered on a hook).
	 *
	 * @param string   $hook_name Hook name.
	 * @param callable $callback  Callback.
	 * @param int      $priority  Priority.
	 * @return bool
	 */
	function remove_filter( $hook_name, $callback, $priority = 10 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( ! isset( $GLOBALS['_wpmar_test_filters'] ) || ! is_array( $GLOBALS['_wpmar_test_filters'] ) ) {
			$GLOBALS['_wpmar_test_filters'] = array();
		}
		$GLOBALS['_wpmar_test_filters'][] = array( 'remove', $hook_name, $priority, $callback );

		return true;
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	/**
	 * Stub remove_action — delegates to remove_filter() (real WordPress shares
	 * the same hook registry for actions and filters).
	 *
	 * @param string   $hook_name Hook name.
	 * @param callable $callback  Callback.
	 * @param int      $priority  Priority.
	 * @return bool
	 */
	function remove_action( $hook_name, $callback, $priority = 10 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return remove_filter( $hook_name, $callback, $priority );
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	/**
	 * Replays the add/remove log for a hook (optionally narrowed to one
	 * callback) and returns whatever the chronologically-last matching
	 * registration left behind: the priority when still added, false when
	 * removed or never registered.
	 *
	 * @param string         $hook_name Hook name.
	 * @param callable|false $callback  Specific callback to check, or false for "any".
	 * @return bool|int
	 */
	function has_filter( $hook_name, $callback = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( ! isset( $GLOBALS['_wpmar_test_filters'] ) || ! is_array( $GLOBALS['_wpmar_test_filters'] ) ) {
			return false;
		}

		$state = false;
		foreach ( $GLOBALS['_wpmar_test_filters'] as $registration ) {
			if ( $registration[1] !== $hook_name ) {
				continue;
			}
			if ( false !== $callback && ( ! isset( $registration[3] ) || $registration[3] !== $callback ) ) {
				continue;
			}
			$state = ( 'add' === $registration[0] ) ? ( isset( $registration[2] ) ? $registration[2] : true ) : false;
		}

		return $state;
	}
}

if ( ! function_exists( 'has_action' ) ) {
	/**
	 * Stub has_action — delegates to has_filter() (same shared registry).
	 *
	 * @param string         $hook_name Hook name.
	 * @param callable|false $callback  Specific callback to check, or false for "any".
	 * @return bool|int
	 */
	function has_action( $hook_name, $callback = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return has_filter( $hook_name, $callback );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Records fired hooks in $GLOBALS['_wpmar_test_actions_fired']. Like
	 * apply_filters() above, callbacks only actually run when a test opts in via
	 * $GLOBALS['_wpmar_test_apply_filters_functional'] — most tests only need to
	 * assert that a hook fired, not that its side effects ran.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  ...$args   Args passed through to callbacks when functional.
	 * @return void
	 */
	function do_action( $hook_name, ...$args ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( ! isset( $GLOBALS['_wpmar_test_actions_fired'] ) || ! is_array( $GLOBALS['_wpmar_test_actions_fired'] ) ) {
			$GLOBALS['_wpmar_test_actions_fired'] = array();
		}
		$GLOBALS['_wpmar_test_actions_fired'][] = array( $hook_name, $args );

		if ( empty( $GLOBALS['_wpmar_test_apply_filters_functional'] ) || empty( $GLOBALS['_wpmar_test_filters'] ) ) {
			return;
		}
		foreach ( $GLOBALS['_wpmar_test_filters'] as $registration ) {
			if ( 'add' === $registration[0] && $hook_name === $registration[1] ) {
				call_user_func_array( $registration[3], $args );
			}
		}
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

if ( ! function_exists( 'wpmar_test_next_seq' ) ) {
	/**
	 * Shared monotonic counter so tests can assert cross-store ordering (e.g.
	 * "mail was sent before the DB row was inserted") without the two events
	 * living in the same array. wp_mail() and WPMAR_Test_Fake_Wpdb::insert()
	 * both stamp their records with this.
	 *
	 * @return int
	 */
	function wpmar_test_next_seq() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( ! isset( $GLOBALS['_wpmar_test_seq'] ) ) {
			$GLOBALS['_wpmar_test_seq'] = 0;
		}
		return ++$GLOBALS['_wpmar_test_seq'];
	}
}

if ( ! function_exists( 'wp_mail' ) ) {
	/**
	 * Records every call in $GLOBALS['_wpmar_test_mail_calls'] (args + order),
	 * so mail tests can assert both content and send sequence. Return value and
	 * failure mode are test-controllable:
	 * - $GLOBALS['_wpmar_test_mail_results']: queue of return values, shifted in
	 *   call order (defaults to true once drained).
	 * - $GLOBALS['_wpmar_test_mail_throw']: when truthy, throws instead of
	 *   returning — used to simulate a hard PHPMailer failure mid-send.
	 *
	 * @param string|string[]      $to          Recipient(s).
	 * @param string               $subject     Subject.
	 * @param string               $message     Body.
	 * @param string|string[]      $headers     Headers.
	 * @param string|string[]      $attachments Attachments (ignored).
	 * @return bool
	 */
	function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $attachments );
		if ( ! isset( $GLOBALS['_wpmar_test_mail_calls'] ) || ! is_array( $GLOBALS['_wpmar_test_mail_calls'] ) ) {
			$GLOBALS['_wpmar_test_mail_calls'] = array();
		}
		$GLOBALS['_wpmar_test_mail_calls'][] = array(
			'to'      => $to,
			'subject' => $subject,
			'message' => $message,
			'headers' => $headers,
			'seq'     => wpmar_test_next_seq(),
		);

		if ( ! empty( $GLOBALS['_wpmar_test_mail_throw'] ) ) {
			throw new RuntimeException( 'wpmar test: forced wp_mail() failure' );
		}

		if ( isset( $GLOBALS['_wpmar_test_mail_results'] ) && is_array( $GLOBALS['_wpmar_test_mail_results'] ) && ! empty( $GLOBALS['_wpmar_test_mail_results'] ) ) {
			return (bool) array_shift( $GLOBALS['_wpmar_test_mail_results'] );
		}

		return true;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Stub wp_strip_all_tags.
	 *
	 * @param string $text          Text.
	 * @param bool   $remove_breaks Collapse whitespace/newlines when true.
	 * @return string
	 */
	function wp_strip_all_tags( $text, $remove_breaks = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
		$text = strip_tags( $text );
		if ( $remove_breaks ) {
			$text = (string) preg_replace( '/[\r\n\t ]+/', ' ', $text );
		}
		return trim( $text );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	/**
	 * Stub wp_kses_post — pass-through (no allowlist filtering; tests control
	 * the HTML they feed in, so there is nothing to strip).
	 *
	 * @param string $data HTML fragment.
	 * @return string
	 */
	function wp_kses_post( $data ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return (string) $data;
	}
}

if ( ! function_exists( 'wp_specialchars_decode' ) ) {
	/**
	 * Stub wp_specialchars_decode.
	 *
	 * @param string $text        Text.
	 * @param mixed  $quote_style ENT_* quote style.
	 * @return string
	 */
	function wp_specialchars_decode( $text, $quote_style = ENT_NOQUOTES ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return htmlspecialchars_decode( (string) $text, $quote_style );
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	/**
	 * Stub get_bloginfo — configure per-field via $GLOBALS['_wpmar_test_bloginfo'][$show];
	 * 'name' falls back to the get_option('blogname') store so tests only need
	 * to set one or the other.
	 *
	 * @param string $show Field name.
	 * @return string
	 */
	function get_bloginfo( $show = '' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$map = isset( $GLOBALS['_wpmar_test_bloginfo'] ) && is_array( $GLOBALS['_wpmar_test_bloginfo'] ) ? $GLOBALS['_wpmar_test_bloginfo'] : array();
		if ( isset( $map[ $show ] ) ) {
			return (string) $map[ $show ];
		}
		if ( 'name' === $show ) {
			return (string) get_option( 'blogname', '' );
		}
		if ( 'version' === $show ) {
			return '0.0.0';
		}
		return '';
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	/**
	 * Stub get_locale — set $GLOBALS['_wpmar_test_locale'] to configure per-test.
	 *
	 * @return string
	 */
	function get_locale() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return isset( $GLOBALS['_wpmar_test_locale'] ) ? (string) $GLOBALS['_wpmar_test_locale'] : 'en_US';
	}
}

if ( ! function_exists( 'wp_timezone' ) ) {
	/**
	 * Stub wp_timezone — set $GLOBALS['_wpmar_test_timezone'] (a DateTimeZone
	 * name) to configure per-test; defaults to 'UTC' (real WP's un-configured default).
	 *
	 * @return DateTimeZone
	 */
	function wp_timezone() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$slug = isset( $GLOBALS['_wpmar_test_timezone'] ) ? (string) $GLOBALS['_wpmar_test_timezone'] : 'UTC';
		try {
			return new DateTimeZone( $slug );
		} catch ( Exception $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- explicit fallback below.
			unset( $ignored );
			return new DateTimeZone( 'UTC' );
		}
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	/**
	 * Stub wp_date.
	 *
	 * @param string          $format    date() format string.
	 * @param int|null        $timestamp Unix timestamp; defaults to now.
	 * @param DateTimeZone|null $timezone  Timezone; defaults to wp_timezone().
	 * @return string
	 */
	function wp_date( $format, $timestamp = null, $timezone = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( null === $timestamp ) {
			$timestamp = time();
		}
		$tz       = $timezone instanceof DateTimeZone ? $timezone : wp_timezone();
		$datetime = new DateTime( '@' . $timestamp );
		$datetime->setTimezone( $tz );
		return $datetime->format( $format );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * Stub current_time.
	 *
	 * @param string $type Either 'timestamp', 'mysql', or a date() format string.
	 * @param bool   $gmt  Use UTC instead of wp_timezone() when true.
	 * @return int|string
	 */
	function current_time( $type, $gmt = 0 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$tz  = $gmt ? new DateTimeZone( 'UTC' ) : wp_timezone();
		$now = new DateTime( 'now', $tz );
		if ( 'timestamp' === $type ) {
			return $now->getTimestamp();
		}
		if ( 'mysql' === $type ) {
			return $now->format( 'Y-m-d H:i:s' );
		}
		return $now->format( (string) $type );
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * In-memory cron store backed by $GLOBALS['_wpmar_test_cron'][$hook] (list of
	 * {timestamp, args} entries), shared with wp_schedule_single_event() /
	 * wp_unschedule_event() / wp_clear_scheduled_hook() below.
	 *
	 * @param string             $hook Hook name.
	 * @param array<int,mixed>   $args Args the event was scheduled with.
	 * @return int|false
	 */
	function wp_next_scheduled( $hook, $args = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( empty( $GLOBALS['_wpmar_test_cron'][ $hook ] ) ) {
			return false;
		}
		$matches = array();
		foreach ( $GLOBALS['_wpmar_test_cron'][ $hook ] as $event ) {
			if ( $event['args'] === $args ) {
				$matches[] = $event['timestamp'];
			}
		}
		if ( empty( $matches ) ) {
			return false;
		}
		sort( $matches );
		return $matches[0];
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	/**
	 * Stub wp_schedule_single_event — appends to the in-memory cron store.
	 *
	 * @param int              $timestamp Unix time to fire at.
	 * @param string           $hook      Hook name.
	 * @param array<int,mixed> $args      Args.
	 * @return bool
	 */
	function wp_schedule_single_event( $timestamp, $hook, $args = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( ! isset( $GLOBALS['_wpmar_test_cron'][ $hook ] ) || ! is_array( $GLOBALS['_wpmar_test_cron'][ $hook ] ) ) {
			$GLOBALS['_wpmar_test_cron'][ $hook ] = array();
		}
		$GLOBALS['_wpmar_test_cron'][ $hook ][] = array(
			'timestamp' => (int) $timestamp,
			'args'      => $args,
		);
		return true;
	}
}

if ( ! function_exists( 'wp_unschedule_event' ) ) {
	/**
	 * Stub wp_unschedule_event — removes one matching entry from the in-memory
	 * cron store.
	 *
	 * @param int              $timestamp Unix time originally scheduled.
	 * @param string           $hook      Hook name.
	 * @param array<int,mixed> $args      Args.
	 * @return bool
	 */
	function wp_unschedule_event( $timestamp, $hook, $args = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( empty( $GLOBALS['_wpmar_test_cron'][ $hook ] ) ) {
			return false;
		}
		foreach ( $GLOBALS['_wpmar_test_cron'][ $hook ] as $i => $event ) {
			if ( $event['timestamp'] === (int) $timestamp && $event['args'] === $args ) {
				unset( $GLOBALS['_wpmar_test_cron'][ $hook ][ $i ] );
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	/**
	 * Stub wp_clear_scheduled_hook — drops every entry for a hook from the
	 * in-memory cron store.
	 *
	 * @param string           $hook Hook name.
	 * @param array<int,mixed> $args Ignored — clears all args variants like real WP.
	 * @return int|false
	 */
	function wp_clear_scheduled_hook( $hook, $args = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $args );
		$count = isset( $GLOBALS['_wpmar_test_cron'][ $hook ] ) ? count( $GLOBALS['_wpmar_test_cron'][ $hook ] ) : 0;
		unset( $GLOBALS['_wpmar_test_cron'][ $hook ] );
		return $count;
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Stub add_query_arg — supports both call shapes: (key, value, url) and
	 * (array $params, url).
	 *
	 * @param mixed ...$args Either ($key,$value,$url) or ($params,$url).
	 * @return string
	 */
	function add_query_arg( ...$args ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( 2 === count( $args ) && is_array( $args[0] ) ) {
			list( $params, $url ) = $args;
		} elseif ( 3 === count( $args ) ) {
			list( $key, $value, $url ) = $args;
			$params = array( $key => $value );
		} else {
			return isset( $args[0] ) ? (string) $args[0] : '';
		}

		$parts = wp_parse_url( (string) $url );
		$parts = is_array( $parts ) ? $parts : array();
		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}
		foreach ( $params as $k => $v ) {
			$query[ $k ] = $v;
		}

		$base = '';
		if ( ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
			$base = $parts['scheme'] . '://' . $parts['host'];
		}
		$base .= isset( $parts['path'] ) ? $parts['path'] : '';

		$query_string = http_build_query( $query );
		return '' !== $query_string ? $base . '?' . $query_string : $base;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	/**
	 * Stub wp_create_nonce — deterministic per action string, not a real nonce.
	 *
	 * @param string|int $action Nonce action.
	 * @return string
	 */
	function wp_create_nonce( $action = -1 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return 'test-nonce-' . md5( (string) $action );
	}
}

if ( ! function_exists( 'wp_nonce_url' ) ) {
	/**
	 * Stub wp_nonce_url.
	 *
	 * @param string     $actionurl Base URL.
	 * @param string|int $action    Nonce action.
	 * @param string     $name      Query arg name for the nonce.
	 * @return string
	 */
	function wp_nonce_url( $actionurl, $action = -1, $name = '_wpnonce' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return add_query_arg( $name, wp_create_nonce( $action ), $actionurl );
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * Stub rest_url.
	 *
	 * @param string $path Path relative to the REST root.
	 * @return string
	 */
	function rest_url( $path = '' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return trailingslashit( home_url( '/wp-json' ) ) . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Stub esc_attr.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_attr( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	/**
	 * Stub esc_attr__.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain (ignored).
	 * @return string
	 */
	function esc_attr__( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $domain );
		return esc_attr( $text );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	/**
	 * Stub esc_html_e — echoes rather than returning, matching real WordPress.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain (ignored).
	 * @return void
	 */
	function esc_html_e( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $domain );
		echo esc_html( $text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html() above already escaped it.
	}
}

if ( ! function_exists( 'size_format' ) ) {
	/**
	 * Stub size_format.
	 *
	 * @param int|float $bytes    Byte count.
	 * @param int       $decimals Decimal places.
	 * @return string|false
	 */
	function size_format( $bytes, $decimals = 0 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$bytes      = (float) $bytes;
		$units      = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$unit_count = count( $units );
		$i          = 0;
		while ( $bytes >= 1024 && $i < $unit_count - 1 ) {
			$bytes /= 1024;
			++$i;
		}
		return number_format( $bytes, $decimals ) . ' ' . $units[ $i ];
	}
}

if ( ! function_exists( 'wp_convert_hr_to_bytes' ) ) {
	/**
	 * Stub wp_convert_hr_to_bytes — converts a php.ini shorthand size
	 * ("128M", "1G", "512K") to a byte count, mirroring core's implementation.
	 *
	 * @param string $value Shorthand size string.
	 * @return int|float
	 */
	function wp_convert_hr_to_bytes( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$value = strtolower( trim( (string) $value ) );
		$bytes = (float) $value;

		if ( false !== strpos( $value, 'g' ) ) {
			$bytes *= 1024 * 1024 * 1024;
		} elseif ( false !== strpos( $value, 'm' ) ) {
			$bytes *= 1024 * 1024;
		} elseif ( false !== strpos( $value, 'k' ) ) {
			$bytes *= 1024;
		}

		return min( $bytes, PHP_INT_MAX );
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	/**
	 * Stub number_format_i18n.
	 *
	 * @param float $number   Number.
	 * @param int   $decimals Decimal places.
	 * @return string
	 */
	function number_format_i18n( $number, $decimals = 0 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return number_format( (float) $number, $decimals );
	}
}

if ( ! function_exists( 'get_site_option' ) ) {
	/**
	 * In-memory site-option store backed by $GLOBALS['_wpmar_test_site_options'].
	 * Kept separate from get_option()'s store since real WP backs the two with
	 * different tables (network-wide vs per-blog).
	 *
	 * @param string $name          Option name.
	 * @param mixed  $default_value Default when absent.
	 * @return mixed
	 */
	function get_site_option( $name, $default_value = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( ! isset( $GLOBALS['_wpmar_test_site_options'] ) || ! is_array( $GLOBALS['_wpmar_test_site_options'] ) ) {
			$GLOBALS['_wpmar_test_site_options'] = array();
		}
		return array_key_exists( $name, $GLOBALS['_wpmar_test_site_options'] ) ? $GLOBALS['_wpmar_test_site_options'][ $name ] : $default_value;
	}
}

if ( ! function_exists( 'update_site_option' ) ) {
	/**
	 * Stores a site option in the in-memory store.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	function update_site_option( $name, $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( ! isset( $GLOBALS['_wpmar_test_site_options'] ) || ! is_array( $GLOBALS['_wpmar_test_site_options'] ) ) {
			$GLOBALS['_wpmar_test_site_options'] = array();
		}
		$GLOBALS['_wpmar_test_site_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_rand' ) ) {
	/**
	 * Stub wp_rand.
	 *
	 * @param int $min Minimum inclusive.
	 * @param int $max Maximum inclusive (0 means "use PHP's getrandmax()").
	 * @return int
	 */
	function wp_rand( $min = 0, $max = 0 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( 0 === $max ) {
			$max = getrandmax();
		}
		return random_int( $min, $max );
	}
}

if ( ! function_exists( 'wp_version_check' ) ) {
	/**
	 * Stub wp_version_check — no-op (the real function's side effect is an HTTP
	 * call to api.wordpress.org, which tests never want).
	 *
	 * @param array<string,mixed> $extra_stats Ignored.
	 * @param bool                $force_check Ignored.
	 * @return void
	 */
	function wp_version_check( $extra_stats = array(), $force_check = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $extra_stats, $force_check );
	}
}

if ( ! function_exists( 'wp_update_plugins' ) ) {
	/**
	 * Stub wp_update_plugins — no-op.
	 *
	 * @param array<int,string>|null $plugin_data Ignored.
	 * @return void
	 */
	function wp_update_plugins( $plugin_data = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $plugin_data );
	}
}

if ( ! function_exists( 'wp_update_themes' ) ) {
	/**
	 * Stub wp_update_themes — no-op.
	 *
	 * @return void
	 */
	function wp_update_themes() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	}
}

if ( ! function_exists( 'get_plugins' ) ) {
	/**
	 * Stub get_plugins — its only purpose here is to satisfy the
	 * `function_exists( 'get_plugins' )` guards that would otherwise
	 * `require_once ABSPATH . 'wp-admin/includes/plugin.php'` (a real WP core
	 * file that doesn't exist under the fake ABSPATH tests use). Configure via
	 * $GLOBALS['_wpmar_test_plugins'] when a test actually needs entries.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	function get_plugins() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return isset( $GLOBALS['_wpmar_test_plugins'] ) && is_array( $GLOBALS['_wpmar_test_plugins'] ) ? $GLOBALS['_wpmar_test_plugins'] : array();
	}
}

if ( ! function_exists( 'is_plugin_active' ) ) {
	/**
	 * Stub is_plugin_active. Configure via $GLOBALS['_wpmar_test_active_plugins']
	 * (list of basenames).
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return bool
	 */
	function is_plugin_active( $plugin_file ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$active = isset( $GLOBALS['_wpmar_test_active_plugins'] ) && is_array( $GLOBALS['_wpmar_test_active_plugins'] ) ? $GLOBALS['_wpmar_test_active_plugins'] : array();
		return in_array( $plugin_file, $active, true );
	}
}

if ( ! function_exists( 'get_core_updates' ) ) {
	/**
	 * Stub get_core_updates. Configure via $GLOBALS['_wpmar_test_core_updates'].
	 *
	 * @param array<string,mixed> $options Ignored.
	 * @return array<int,object>|false
	 */
	function get_core_updates( $options = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $options );
		return isset( $GLOBALS['_wpmar_test_core_updates'] ) ? $GLOBALS['_wpmar_test_core_updates'] : array();
	}
}

if ( ! function_exists( 'wp_get_themes' ) ) {
	/**
	 * Stub wp_get_themes. Configure via $GLOBALS['_wpmar_test_themes'] (slug => object).
	 *
	 * @return array<string,object>
	 */
	function wp_get_themes() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return isset( $GLOBALS['_wpmar_test_themes'] ) && is_array( $GLOBALS['_wpmar_test_themes'] ) ? $GLOBALS['_wpmar_test_themes'] : array();
	}
}

if ( ! function_exists( 'get_stylesheet' ) ) {
	/**
	 * Stub get_stylesheet. Configure via $GLOBALS['_wpmar_test_stylesheet'].
	 *
	 * @return string
	 */
	function get_stylesheet() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return isset( $GLOBALS['_wpmar_test_stylesheet'] ) ? (string) $GLOBALS['_wpmar_test_stylesheet'] : '';
	}
}

if ( ! function_exists( 'get_users' ) ) {
	/**
	 * Stub get_users. Configure via $GLOBALS['_wpmar_test_users'] (list of WP_User-like objects).
	 *
	 * @param array<string,mixed> $args Ignored.
	 * @return array<int,object>
	 */
	function get_users( $args = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $args );
		return isset( $GLOBALS['_wpmar_test_users'] ) && is_array( $GLOBALS['_wpmar_test_users'] ) ? $GLOBALS['_wpmar_test_users'] : array();
	}
}

if ( ! function_exists( 'user_can' ) ) {
	/**
	 * Stub user_can — reads a WP_User-like object's `roles` array/property against
	 * a simplistic capability map (administrator/editor/author only, matching what
	 * this plugin actually gates on: manage_options / edit_others_posts / publish_posts).
	 *
	 * @param object $user       User-like object with a `roles` property.
	 * @param string $capability Capability to check.
	 * @return bool
	 */
	function user_can( $user, $capability ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$roles = is_object( $user ) && isset( $user->roles ) ? (array) $user->roles : array();
		$map   = array(
			'manage_options'    => array( 'administrator' ),
			'edit_others_posts' => array( 'administrator', 'editor' ),
			'publish_posts'     => array( 'administrator', 'editor', 'author' ),
		);
		$allowed_roles = isset( $map[ $capability ] ) ? $map[ $capability ] : array();
		return (bool) array_intersect( $roles, $allowed_roles );
	}
}

if ( ! class_exists( 'WPMAR_Test_Fake_Wpdb' ) ) {
	/**
	 * Minimal in-memory wpdb double for repository/dispatcher tests.
	 *
	 * Two lookup paths coexist: the original `rows` map (keyed directly by an
	 * arbitrary string id, e.g. job ids like "wpmar.done1") stays untouched for
	 * back-compat; `tables` is a newer table-name-aware store that `insert()`
	 * populates and `get_row()`/`get_results()`/`get_col()`/`delete()` can read
	 * from by parsing the table name out of the SQL text. Both can be used in
	 * the same test.
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
		/** @var array<int,array{0:string,1:array<string,mixed>}> */
		public $delete_calls = array();
		/** @var array<int,int> wpmar_test_next_seq() stamp for each insert_calls entry, same index. */
		public $insert_seqs = array();
		/** @var array<string,array<string,array<string,mixed>>> Rows keyed by table then by (string) id. */
		public $tables = array();
		/** @var array<int,mixed> Scriptable get_var() return queue; falls back to 0 once drained. */
		public $var_returns = array();

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
		 * Splits a prepare()d tuple (or a raw query string) into [sql, args].
		 *
		 * @param array{0:string,1:array<int,mixed>}|string $prepared Prepared tuple or raw SQL.
		 * @return array{0:string,1:array<int,mixed>}
		 */
		protected function normalize_prepared( $prepared ) {
			if ( is_array( $prepared ) && isset( $prepared[0] ) ) {
				return array( (string) $prepared[0], isset( $prepared[1] ) && is_array( $prepared[1] ) ? $prepared[1] : array() );
			}
			return array( (string) $prepared, array() );
		}

		/**
		 * Best-effort `FROM \`table\`` extraction from a SQL string.
		 *
		 * @param string $sql Query text.
		 * @return string Table name, or '' when not found.
		 */
		protected function extract_table_from_sql( $sql ) {
			if ( preg_match( '/FROM\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $matches ) ) {
				return $matches[1];
			}
			return '';
		}

		/**
		 * @param array{0:string,1:array<int,mixed>}|string $prepared Prepared tuple.
		 * @param mixed                                      $output   Output type (ignored).
		 * @return array<string,mixed>|null
		 */
		public function get_row( $prepared, $output = null ) {
			unset( $output );
			list( $sql, $args ) = $this->normalize_prepared( $prepared );
			$id                 = isset( $args[0] ) ? (string) $args[0] : '';

			if ( isset( $this->rows[ $id ] ) ) {
				return $this->rows[ $id ];
			}

			$table = $this->extract_table_from_sql( $sql );
			if ( '' !== $table && isset( $this->tables[ $table ][ $id ] ) ) {
				return $this->tables[ $table ][ $id ];
			}

			return null;
		}

		/**
		 * Table-aware row listing: newest-id-first, optionally LIMIT/OFFSET-sliced
		 * using the trailing prepare() args (matches every ORDER BY id DESC usage
		 * in this plugin's repositories).
		 *
		 * @param array{0:string,1:array<int,mixed>}|string $prepared Prepared tuple.
		 * @param mixed                                      $output   Output type (ignored).
		 * @return array<int,array<string,mixed>>
		 */
		public function get_results( $prepared, $output = null ) {
			unset( $output );
			list( $sql, $args ) = $this->normalize_prepared( $prepared );
			$table               = $this->extract_table_from_sql( $sql );
			$rows                = ( '' !== $table && isset( $this->tables[ $table ] ) ) ? $this->tables[ $table ] : array();

			$items = array_values( $rows );
			usort(
				$items,
				static function ( $a, $b ) {
					return ( $b['id'] ?? 0 ) <=> ( $a['id'] ?? 0 );
				}
			);

			if ( false !== stripos( $sql, 'OFFSET' ) && count( $args ) >= 2 ) {
				$items = array_slice( $items, (int) $args[ count( $args ) - 1 ], (int) $args[ count( $args ) - 2 ] );
			} elseif ( false !== stripos( $sql, 'LIMIT' ) && count( $args ) >= 1 ) {
				$items = array_slice( $items, 0, (int) $args[ count( $args ) - 1 ] );
			}

			return $items;
		}

		/**
		 * Table-aware single-column listing. Recognises `SELECT id` (returns each
		 * row's id); anything else falls back to $var_returns-style scripting via
		 * a dedicated queue so callers can still control the result explicitly.
		 *
		 * @param array{0:string,1:array<int,mixed>}|string $prepared Prepared tuple.
		 * @return array<int,mixed>
		 */
		public function get_col( $prepared ) {
			list( $sql, $args ) = $this->normalize_prepared( $prepared );
			unset( $args );

			$table = $this->extract_table_from_sql( $sql );
			$rows  = ( '' !== $table && isset( $this->tables[ $table ] ) ) ? $this->tables[ $table ] : array();

			if ( false !== stripos( $sql, 'SELECT id' ) ) {
				return array_values(
					array_map(
						static function ( $row ) {
							return $row['id'] ?? null;
						},
						$rows
					)
				);
			}

			return array();
		}

		/**
		 * @param string             $table   Table.
		 * @param array<string,mixed> $data    Data.
		 * @param array<int,string>  $formats Formats.
		 * @return int
		 */
		public function insert( $table, $data, $formats = null ) {
			unset( $formats );
			$this->insert_calls[] = $data;
			$this->insert_seqs[]  = function_exists( 'wpmar_test_next_seq' ) ? wpmar_test_next_seq() : 0;
			++$this->insert_id;

			if ( ! isset( $this->tables[ $table ] ) || ! is_array( $this->tables[ $table ] ) ) {
				$this->tables[ $table ] = array();
			}
			$this->tables[ $table ][ (string) $this->insert_id ] = array_merge( array( 'id' => $this->insert_id ), $data );

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
			unset( $data_format, $where_format );
			$this->update_calls[] = array( $data, $where );

			if ( isset( $this->tables[ $table ] ) && isset( $where['id'] ) ) {
				$id = (string) $where['id'];
				if ( isset( $this->tables[ $table ][ $id ] ) ) {
					$this->tables[ $table ][ $id ] = array_merge( $this->tables[ $table ][ $id ], $data );
				}
			}

			return 1;
		}

		/**
		 * @param string             $table        Table.
		 * @param array<string,mixed> $where        Where.
		 * @param array<int,string>  $where_format Where formats (ignored).
		 * @return int Rows affected (1 when a matching row was removed, 0 otherwise).
		 */
		public function delete( $table, $where, $where_format = null ) {
			unset( $where_format );
			$this->delete_calls[] = array( $table, $where );

			if ( isset( $this->tables[ $table ] ) && isset( $where['id'] ) ) {
				$id = (string) $where['id'];
				if ( isset( $this->tables[ $table ][ $id ] ) ) {
					unset( $this->tables[ $table ][ $id ] );
					return 1;
				}
			}

			return 0;
		}

		/**
		 * Scriptable via $var_returns (shifted in call order); defaults to 0 once
		 * the queue is empty, matching the original always-0 stub behaviour.
		 *
		 * @param string $query Query.
		 * @return mixed
		 */
		public function get_var( $query ) {
			unset( $query );
			if ( ! empty( $this->var_returns ) ) {
				return array_shift( $this->var_returns );
			}
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

if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', true );
}

if ( ! class_exists( 'WP_CLI_Command' ) ) {
	/**
	 * Minimal WP_CLI_Command stub — just enough for `class X extends WP_CLI_Command`
	 * to load outside a real WP-CLI process. No command methods are exercised via
	 * this base class by any test; they're called directly on the subclass.
	 */
	class WP_CLI_Command { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, Generic.Files.OneObjectStructurePerFile.MultipleFound
	}
}

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Minimal WP_CLI facade stub. `add_command()` is a no-op so that requiring a
	 * `includes/cli/class-wpmar-cli-*-command.php` file (which registers itself at
	 * the bottom) doesn't fatal; `confirm()` is only stubbed to avoid a fatal if a
	 * future test calls a command method that reaches it — no test currently does.
	 */
	class WP_CLI { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, Generic.Files.OneObjectStructurePerFile.MultipleFound
		/**
		 * @param string $name     Command name.
		 * @param mixed  $callable Command class/callable.
		 * @return void
		 */
		public static function add_command( $name, $callable ) {
			unset( $name, $callable );
		}

		/**
		 * @param string                        $message     Prompt message.
		 * @param array<string,string|bool|int> $assoc_flags Flags (checked for --yes in real WP-CLI).
		 * @return void
		 */
		public static function confirm( $message, $assoc_flags = array() ) {
			unset( $message, $assoc_flags );
		}
	}
}
