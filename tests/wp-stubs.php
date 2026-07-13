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
	 * Pass-through apply_filters.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  $value     Value.
	 * @return mixed
	 */
	function apply_filters( $hook_name, $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $hook_name );
		return $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Records filter registrations (callbacks are never invoked by the stubs).
	 *
	 * @param string   $hook_name     Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted args.
	 * @return bool
	 */
	function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $callback, $accepted_args );
		if ( ! isset( $GLOBALS['_wpmar_test_filters'] ) || ! is_array( $GLOBALS['_wpmar_test_filters'] ) ) {
			$GLOBALS['_wpmar_test_filters'] = array();
		}
		$GLOBALS['_wpmar_test_filters'][] = array( 'add', $hook_name, $priority );

		return true;
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
