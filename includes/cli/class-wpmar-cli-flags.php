<?php
/**
 * Shared WP-CLI assoc-flag readers.
 *
 * No `WP_CLI` bootstrap guard here (unlike the other `includes/cli/` files) so this
 * class can be `require`d directly from PHPUnit, which never defines `WP_CLI`.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads WP-CLI assoc flags with correct `--no-<flag>` negation semantics.
 *
 * WP-CLI turns `--no-foo` into `$assoc_flags['foo'] = false`, not into the flag's
 * absence. Reading a declared bool flag with `isset( $assoc_flags['foo'] )` therefore
 * reports `true` for both `--foo` and `--no-foo`. `WP_CLI\Utils\get_flag_value()`
 * already gets this right, but is unavailable outside a `WP_CLI` bootstrap; this
 * re-implements its "unset falls back to default" semantics in plain PHP so it works
 * under PHPUnit too.
 */
class WPMAR_CLI_Flags {

	/**
	 * Reads a boolean assoc flag, honouring `--no-<flag>` negation.
	 *
	 * @param array<string,mixed> $assoc_flags   Associative CLI flags.
	 * @param string              $name          Flag name.
	 * @param bool                $default_value Value when the flag is not present.
	 * @return bool
	 */
	public static function bool( array $assoc_flags, $name, $default_value = false ) {
		$value = array_key_exists( $name, $assoc_flags ) ? $assoc_flags[ $name ] : $default_value;

		if ( in_array( $value, array( false, 'false', '0', '', null ), true ) ) {
			return false;
		}

		return (bool) $value;
	}

	/**
	 * Reads an integer assoc flag, honouring `--no-<flag>` negation.
	 *
	 * `--no-<flag>` yields `false` from WP-CLI; casting that straight to `(int)`
	 * silently produces `0`, which callers like a batch size then clamp to `1`
	 * instead of falling back to the declared default.
	 *
	 * @param array<string,mixed> $assoc_flags   Associative CLI flags.
	 * @param string              $name          Flag name.
	 * @param int                 $default_value Value when the flag is not present.
	 * @return int
	 */
	public static function int( array $assoc_flags, $name, $default_value = 0 ) {
		$value = array_key_exists( $name, $assoc_flags ) ? $assoc_flags[ $name ] : $default_value;

		if ( false === $value ) {
			return $default_value;
		}

		return (int) $value;
	}
}
