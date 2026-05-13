<?php
/**
 * Option storage and sanitization helpers.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles persisted plugin settings (`wpmar_settings`).
 */
class WPMAR_Settings {

	const OPTION_NAME = 'wpmar_settings';

	/**
	 * Default structure for new installs.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'schedule'  => array(
				'day'    => 25,
				'hour'   => 2,
				'minute' => 0,
				'tz'     => 'Asia/Tokyo',
			),
			'domain'    => array(
				'allowed_host' => '',
			),
			'mail'      => array(
				'enabled'      => false,
				'client_to'    => array(),
				'admin_to'     => array(),
				'from_address' => '',
				'from_name'    => '',
			),
			'output'    => array(
				'md_enabled' => true,
			),
			'checksums' => array(
				'core_exclude_paths'   => array(),
				'plugin_exclude_rules' => array(),
			),
			'retention' => array(
				'months' => 12,
			),
		);
	}

	/**
	 * Retrieves merged settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_all() {
		$stored = get_option( self::OPTION_NAME, array() );

		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * Persists merged settings array.
	 *
	 * @param array<string,mixed> $settings Full settings payload.
	 * @return bool
	 */
	public static function update_all( array $settings ) {
		return update_option(
			self::OPTION_NAME,
			wp_parse_args( $settings, self::defaults() ),
			true
		);
	}

	/**
	 * Sanitizes schedule integers.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $min   Minimum inclusive.
	 * @param int   $max   Maximum inclusive.
	 * @return int
	 */
	public static function clamp_int( $value, $min, $max ) {
		$value = absint( $value );
		if ( $value < $min ) {
			$value = $min;
		}
		if ( $value > $max ) {
			$value = $max;
		}

		return $value;
	}

	/**
	 * Parses textarea of emails into unique list.
	 *
	 * @param string $raw Raw multiline/string.
	 * @return string[]
	 */
	public static function parse_email_list( $raw ) {
		$parts = preg_split( '/[\r\n,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $parts ) ) {
			return array();
		}
		$list = array();
		foreach ( $parts as $p ) {
			$clean = sanitize_email( trim( $p ) );
			if ( is_email( $clean ) ) {
				$list[] = $clean;
			}
		}

		return array_values( array_unique( $list ) );
	}

	/**
	 * Merges POSTed admin form fields into saved settings (partial update).
	 *
	 * @param array<string,string> $post Super-global slice for our fields.
	 * @param array<string,mixed>  $curr Current merged settings.
	 * @return array<string,mixed>
	 */
	public static function merge_form_input( array $post, array $curr ) {
		if ( isset( $post['wpmar_schedule_day'] ) ) {
			$tz_value = isset( $post['wpmar_schedule_tz'] ) ? sanitize_text_field( wp_unslash( $post['wpmar_schedule_tz'] ) ) : '';
			if ( '' === $tz_value && isset( $curr['schedule']['tz'] ) ) {
				$tz_value = sanitize_text_field( $curr['schedule']['tz'] );
			}
			if ( '' === $tz_value ) {
				$tz_value = 'Asia/Tokyo';
			}

			$curr['schedule'] = array(
				'day'    => self::clamp_int( $post['wpmar_schedule_day'], 1, 31 ),
				'hour'   => self::clamp_int( $post['wpmar_schedule_hour'], 0, 23 ),
				'minute' => self::clamp_int( $post['wpmar_schedule_minute'], 0, 59 ),
				'tz'     => $tz_value,
			);
		} elseif ( isset( $post['wpmar_schedule_tz'] ) ) {
			if ( ! isset( $curr['schedule'] ) || ! is_array( $curr['schedule'] ) ) {
				$curr['schedule'] = self::defaults()['schedule'];
			}
			$curr['schedule']['tz'] = sanitize_text_field( wp_unslash( $post['wpmar_schedule_tz'] ) );
		}

		if ( isset( $post['wpmar_allowed_host'] ) ) {
			$curr['domain']['allowed_host'] = sanitize_text_field( wp_unslash( $post['wpmar_allowed_host'] ) );
		}

		$curr['mail']['enabled'] = ! empty( $post['wpmar_mail_enabled'] );

		if ( isset( $post['wpmar_client_mail'] ) ) {
			$curr['mail']['client_to'] = self::parse_email_list( wp_unslash( $post['wpmar_client_mail'] ) );
		}
		if ( isset( $post['wpmar_admin_mail'] ) ) {
			$curr['mail']['admin_to'] = self::parse_email_list( wp_unslash( $post['wpmar_admin_mail'] ) );
		}
		if ( isset( $post['wpmar_from_email'] ) ) {
			$addr                         = sanitize_email( wp_unslash( $post['wpmar_from_email'] ) );
			$curr['mail']['from_address'] = is_email( $addr ) ? $addr : '';
		}
		if ( isset( $post['wpmar_from_name'] ) ) {
			$curr['mail']['from_name'] = sanitize_text_field( wp_unslash( $post['wpmar_from_name'] ) );
		}

		if ( isset( $post['wpmar_md_enabled'] ) ) {
			$curr['output']['md_enabled'] = ! empty( $post['wpmar_md_enabled'] );
		}

		if ( isset( $post['wpmar_retention_months'] ) ) {
			$allowed = array( 0, 12, 24 );
			$months  = absint( $post['wpmar_retention_months'] );
			if ( ! in_array( $months, $allowed, true ) ) {
				$months = 12;
			}
			if ( ! isset( $curr['retention'] ) || ! is_array( $curr['retention'] ) ) {
				$curr['retention'] = self::defaults()['retention'];
			}
			$curr['retention']['months'] = $months;
		}

		if ( isset( $post['wpmar_core_checksum_excludes'] ) ) {
			if ( ! isset( $curr['checksums'] ) || ! is_array( $curr['checksums'] ) ) {
				$curr['checksums'] = self::defaults()['checksums'];
			}
			$curr['checksums']['core_exclude_paths'] = self::parse_line_paths( wp_unslash( (string) $post['wpmar_core_checksum_excludes'] ) );
		}

		if ( isset( $post['wpmar_plugin_checksum_excludes'] ) ) {
			if ( ! isset( $curr['checksums'] ) || ! is_array( $curr['checksums'] ) ) {
				$curr['checksums'] = self::defaults()['checksums'];
			}
			$curr['checksums']['plugin_exclude_rules'] = self::parse_plugin_exclude_map( wp_unslash( (string) $post['wpmar_plugin_checksum_excludes'] ) );
		}

		return $curr;
	}

	/**
	 * Splits textarea lines into trimmed relative paths.
	 *
	 * @param string $raw Multiline user input.
	 * @return string[]
	 */
	public static function parse_line_paths( $raw ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );

		if ( ! is_array( $lines ) ) {
			return array();
		}

		$out = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || ( strlen( $line ) > 0 && '#' === $line[0] ) ) {
				continue;
			}
			$out[] = $line;
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Parses `slug:relative/path` rows into a slug keyed map of fragments.
	 *
	 * @param string $raw Multiline user input.
	 * @return array<string,array<int,string>>
	 */
	public static function parse_plugin_exclude_map( $raw ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );

		if ( ! is_array( $lines ) ) {
			return array();
		}

		$out = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || ( strlen( $line ) > 0 && '#' === $line[0] ) ) {
				continue;
			}

			$pos = strpos( $line, ':' );
			if ( false === $pos || $pos < 1 ) {
				continue;
			}

			$slug = sanitize_key( substr( $line, 0, $pos ) );
			$rel  = ltrim( substr( $line, $pos + 1 ) );

			if ( '' === $slug || '' === $rel ) {
				continue;
			}

			if ( ! isset( $out[ $slug ] ) ) {
				$out[ $slug ] = array();
			}

			$out[ $slug ][] = $rel;
		}

		foreach ( $out as $slug => $paths ) {
			$out[ $slug ] = array_values( array_unique( array_map( 'strval', $paths ) ) );
		}

		return $out;
	}
}
