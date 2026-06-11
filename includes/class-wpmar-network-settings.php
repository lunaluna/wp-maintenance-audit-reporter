<?php
/**
 * Network-wide settings stored in sitemeta (`wpmar_network_settings`).
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists multisite rollup configuration (schedule, mail, site filters).
 */
class WPMAR_Network_Settings {

	const OPTION_NAME = 'wpmar_network_settings';

	/**
	 * Default network settings structure.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'network_audit_enabled' => false,
			'schedule'              => array(
				'day'    => 25,
				'hour'   => 2,
				'minute' => 0,
				'tz'     => 'Asia/Tokyo',
			),
			'mail'                  => array(
				'enabled'      => false,
				'client_to'    => array(),
				'admin_to'     => array(),
				'from_address' => '',
				'from_name'    => '',
			),
			'output'                => array(
				'md_enabled'  => true,
				'pdf_enabled' => true,
			),
			'retention'             => array(
				'months' => 12,
			),
			'sites'                 => array(
				'include_archived' => false,
				'include_spam'     => false,
				'include_deleted'  => false,
				'exclude_blog_ids' => array(),
				'max_sites'        => 100,
			),
			'domain'                => array(
				'allowed_host'        => '',
				'allowed_path_prefix' => '',
			),
		);
	}

	/**
	 * Whether multisite APIs are available and the plugin runs in network context.
	 *
	 * @return bool
	 */
	public static function is_multisite_available() {
		return function_exists( 'is_multisite' ) && is_multisite();
	}

	/**
	 * Whether network rollup audits are enabled in sitemeta.
	 *
	 * @return bool
	 */
	public static function is_network_audit_enabled() {
		if ( ! self::is_multisite_available() ) {
			return false;
		}

		$settings = self::get_all();

		return ! empty( $settings['network_audit_enabled'] );
	}

	/**
	 * Reads merged network settings from sitemeta.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_all() {
		if ( ! self::is_multisite_available() ) {
			return self::defaults();
		}

		$stored = get_site_option( self::OPTION_NAME, array() );

		return self::normalize( wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() ) );
	}

	/**
	 * Writes network settings to sitemeta.
	 *
	 * @param array<string,mixed> $settings Full payload.
	 * @return bool
	 */
	public static function update_all( array $settings ) {
		if ( ! self::is_multisite_available() ) {
			return false;
		}

		return update_site_option(
			self::OPTION_NAME,
			self::normalize( wp_parse_args( $settings, self::defaults() ) )
		);
	}

	/**
	 * Seeds defaults on first network activation.
	 *
	 * @return void
	 */
	public static function maybe_seed_defaults() {
		if ( ! self::is_multisite_available() ) {
			return;
		}

		if ( false !== get_site_option( self::OPTION_NAME, false ) ) {
			return;
		}

		$defaults = self::defaults();

		$parsed = wp_parse_url( network_home_url(), PHP_URL_HOST );
		if ( is_string( $parsed ) && '' !== $parsed ) {
			$defaults['domain']['allowed_host'] = sanitize_text_field( strtolower( $parsed ) );
		}

		add_site_option( self::OPTION_NAME, $defaults );
	}

	/**
	 * Settings merged for mail/output/retention during a network rollup run.
	 *
	 * Per-site checksum/security values remain on each blog via {@see WPMAR_Settings::get_all()}.
	 *
	 * @return array<string,mixed>
	 */
	public static function rollup_delivery_settings() {
		$network = self::get_all();

		return array(
			'schedule'  => isset( $network['schedule'] ) && is_array( $network['schedule'] ) ? $network['schedule'] : self::defaults()['schedule'],
			'domain'    => isset( $network['domain'] ) && is_array( $network['domain'] ) ? $network['domain'] : self::defaults()['domain'],
			'mail'      => isset( $network['mail'] ) && is_array( $network['mail'] ) ? $network['mail'] : self::defaults()['mail'],
			'output'    => isset( $network['output'] ) && is_array( $network['output'] ) ? $network['output'] : self::defaults()['output'],
			'retention' => isset( $network['retention'] ) && is_array( $network['retention'] ) ? $network['retention'] : self::defaults()['retention'],
		);
	}

	/**
	 * Normalises nested keys and types.
	 *
	 * @param array<string,mixed> $settings Raw settings.
	 * @return array<string,mixed>
	 */
	protected static function normalize( array $settings ) {
		$defaults = self::defaults();
		$merged   = wp_parse_args( $settings, $defaults );

		$merged['network_audit_enabled'] = ! empty( $merged['network_audit_enabled'] );

		foreach ( array( 'schedule', 'mail', 'output', 'retention', 'sites', 'domain' ) as $key ) {
			if ( ! isset( $merged[ $key ] ) || ! is_array( $merged[ $key ] ) ) {
				$merged[ $key ] = $defaults[ $key ];
			}
		}

		$tz_slug = sanitize_text_field( (string) ( $merged['schedule']['tz'] ?? '' ) );
		if ( '' === $tz_slug || ! in_array( $tz_slug, timezone_identifiers_list(), true ) ) {
			$tz_slug = 'Asia/Tokyo';
		}
		$merged['schedule']['tz'] = $tz_slug;

		$merged['sites']['include_archived'] = ! empty( $merged['sites']['include_archived'] );
		$merged['sites']['include_spam']     = ! empty( $merged['sites']['include_spam'] );
		$merged['sites']['include_deleted']  = ! empty( $merged['sites']['include_deleted'] );
		$merged['sites']['max_sites']        = WPMAR_Settings::clamp_int( $merged['sites']['max_sites'], 1, 500 );

		$exclude = array();
		if ( ! empty( $merged['sites']['exclude_blog_ids'] ) && is_array( $merged['sites']['exclude_blog_ids'] ) ) {
			foreach ( $merged['sites']['exclude_blog_ids'] as $id ) {
				$exclude[] = absint( $id );
			}
		}
		$merged['sites']['exclude_blog_ids'] = array_values( array_unique( array_filter( $exclude ) ) );

		$merged['mail']['enabled'] = ! empty( $merged['mail']['enabled'] );
		foreach ( array( 'client_to', 'admin_to' ) as $list_key ) {
			if ( ! isset( $merged['mail'][ $list_key ] ) || ! is_array( $merged['mail'][ $list_key ] ) ) {
				$merged['mail'][ $list_key ] = array();
			}
		}

		$merged['output']['md_enabled']  = ! empty( $merged['output']['md_enabled'] );
		$merged['output']['pdf_enabled'] = ! empty( $merged['output']['pdf_enabled'] );

		$allowed_months = array( 0, 12, 24 );
		$months         = absint( $merged['retention']['months'] );
		if ( ! in_array( $months, $allowed_months, true ) ) {
			$months = 12;
		}
		$merged['retention']['months'] = $months;

		$merged['domain']['allowed_host']        = sanitize_text_field( (string) $merged['domain']['allowed_host'] );
		$merged['domain']['allowed_path_prefix'] = sanitize_text_field( (string) ( $merged['domain']['allowed_path_prefix'] ?? '' ) );

		return $merged;
	}

	/**
	 * Merges POSTed network admin form fields.
	 *
	 * @param array<string,string> $post Raw POST slice.
	 * @param array<string,mixed>  $curr Current merged settings.
	 * @return array<string,mixed>
	 */
	public static function merge_form_input( array $post, array $curr ) {
		$curr['network_audit_enabled'] = ! empty( $post['wpmar_network_audit_enabled'] );

		if ( isset( $post['wpmar_schedule_day'] ) ) {
			$tz_value = isset( $post['wpmar_schedule_tz'] ) ? sanitize_text_field( wp_unslash( $post['wpmar_schedule_tz'] ) ) : '';
			if ( '' === $tz_value && isset( $curr['schedule']['tz'] ) ) {
				$tz_value = sanitize_text_field( $curr['schedule']['tz'] );
			}
			if ( '' === $tz_value ) {
				$tz_value = 'Asia/Tokyo';
			}

			$curr['schedule'] = array(
				'day'    => WPMAR_Settings::clamp_int( $post['wpmar_schedule_day'], 1, 31 ),
				'hour'   => WPMAR_Settings::clamp_int( $post['wpmar_schedule_hour'], 0, 23 ),
				'minute' => WPMAR_Settings::clamp_int( $post['wpmar_schedule_minute'], 0, 59 ),
				'tz'     => $tz_value,
			);
		}

		if ( isset( $post['wpmar_allowed_host'] ) ) {
			$curr['domain']['allowed_host'] = sanitize_text_field( wp_unslash( $post['wpmar_allowed_host'] ) );
		}
		if ( isset( $post['wpmar_allowed_path_prefix'] ) ) {
			$curr['domain']['allowed_path_prefix'] = sanitize_text_field( wp_unslash( $post['wpmar_allowed_path_prefix'] ) );
		}
		$curr['mail']['enabled'] = ! empty( $post['wpmar_mail_enabled'] );
		if ( isset( $post['wpmar_client_mail'] ) ) {
			$curr['mail']['client_to'] = WPMAR_Settings::parse_email_list( wp_unslash( $post['wpmar_client_mail'] ) );
		}
		if ( isset( $post['wpmar_admin_mail'] ) ) {
			$curr['mail']['admin_to'] = WPMAR_Settings::parse_email_list( wp_unslash( $post['wpmar_admin_mail'] ) );
		}
		if ( isset( $post['wpmar_from_email'] ) ) {
			$addr                         = sanitize_email( wp_unslash( $post['wpmar_from_email'] ) );
			$curr['mail']['from_address'] = is_email( $addr ) ? $addr : '';
		}
		if ( isset( $post['wpmar_from_name'] ) ) {
			$curr['mail']['from_name'] = sanitize_text_field( wp_unslash( $post['wpmar_from_name'] ) );
		}

		$curr['output']['md_enabled']  = ! empty( $post['wpmar_md_enabled'] );
		$curr['output']['pdf_enabled'] = ! empty( $post['wpmar_pdf_enabled'] );

		if ( isset( $post['wpmar_retention_months'] ) ) {
			$allowed = array( 0, 12, 24 );
			$months  = absint( $post['wpmar_retention_months'] );
			if ( ! in_array( $months, $allowed, true ) ) {
				$months = 12;
			}
			$curr['retention']['months'] = $months;
		}

		$curr['sites']['include_archived'] = ! empty( $post['wpmar_include_archived'] );
		$curr['sites']['include_spam']     = ! empty( $post['wpmar_include_spam'] );
		$curr['sites']['include_deleted']  = ! empty( $post['wpmar_include_deleted'] );

		if ( isset( $post['wpmar_max_sites'] ) ) {
			$curr['sites']['max_sites'] = WPMAR_Settings::clamp_int( $post['wpmar_max_sites'], 1, 500 );
		}

		if ( isset( $post['wpmar_exclude_blog_ids'] ) ) {
			$raw_ids = preg_split( '/[\r\n,;]+/', wp_unslash( (string) $post['wpmar_exclude_blog_ids'] ), -1, PREG_SPLIT_NO_EMPTY );
			$ids     = array();
			if ( is_array( $raw_ids ) ) {
				foreach ( $raw_ids as $raw_id ) {
					$ids[] = absint( $raw_id );
				}
			}
			$curr['sites']['exclude_blog_ids'] = array_values( array_unique( array_filter( $ids ) ) );
		}

		return self::normalize( $curr );
	}
}
