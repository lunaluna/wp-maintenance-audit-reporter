<?php
/**
 * Dual-audience mail: stakeholder summary and operator diagnostic copy.
 *
 * All bodies are treated as plain text; wp_mail From can be overridden via settings.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight helper that mirrors shell script twin-mail behaviour.
 */
class WPMAR_Notifier_Mail {

	/**
	 * Sends two distinct mail batches (client + admin copies).
	 *
	 * @param array<string,mixed> $settings  Full plugin settings envelope.
	 * @param string              $body_client Accessible summary for stakeholders.
	 * @param string              $body_admin  Verbose operator payload.
	 * @param string|string[]     $qa_override When non-empty overrides every outbound recipient list equally.
	 * @return bool True when something sent successfully.
	 */
	public static function send_pair( array $settings, $body_client, $body_admin, $qa_override = array() ) {
		$mail = isset( $settings['mail'] ) && is_array( $settings['mail'] ) ? $settings['mail'] : array();

		// Short-circuit when mail is disabled - runner still records the audit row.
		if ( empty( $mail['enabled'] ) ) {
			return false;
		}

		$client_to = isset( $mail['client_to'] ) && is_array( $mail['client_to'] ) ? $mail['client_to'] : array();
		$admin_to  = isset( $mail['admin_to'] ) && is_array( $mail['admin_to'] ) ? $mail['admin_to'] : array();

		// QA / test runs may force both channels to the same mailbox list.
		if ( '' !== trim( implode( '', array_map( 'strval', (array) $qa_override ) ) ) ) {
			$replacement = array();
			if ( is_string( $qa_override ) ) {
				$candidate = sanitize_email( trim( $qa_override ) );
				if ( '' !== $candidate ) {
					$replacement[] = $candidate;
				}
			} else {
				foreach ( (array) $qa_override as $entry ) {
					$candidate = sanitize_email( trim( (string) $entry ) );
					if ( is_email( $candidate ) ) {
						$replacement[] = $candidate;
					}
				}
			}
			if ( ! empty( $replacement ) ) {
				$client_to = $replacement;
				$admin_to  = $replacement;
			}
		}

		// Normalise and drop invalid mailboxes so wp_mail never receives garbage.
		$filtered_client = array_values(
			array_unique(
				array_filter(
					array_map(
						'sanitize_email',
						array_map(
							static function ( $addr ) {
								return is_string( $addr ) ? $addr : '';
							},
							$client_to
						)
					),
					'is_email'
				)
			)
		);
		$filtered_admin  = array_values(
			array_unique(
				array_filter(
					array_map(
						'sanitize_email',
						array_map(
							static function ( $addr ) {
								return is_string( $addr ) ? $addr : '';
							},
							$admin_to
						)
					),
					'is_email'
				)
			)
		);

		if ( empty( $filtered_client ) && empty( $filtered_admin ) ) {
			return false;
		}

		// From header: fall back to site admin email / blogname when fields are empty.
		$from_email_raw = isset( $mail['from_address'] ) ? sanitize_email( $mail['from_address'] ) : '';
		$from_email     = '' !== $from_email_raw ? $from_email_raw : sanitize_email( get_bloginfo( 'admin_email' ) );
		$from_name_raw  = isset( $mail['from_name'] ) ? sanitize_text_field( $mail['from_name'] ) : '';
		$mail_from_name = '' !== $from_name_raw ? $from_name_raw : sanitize_text_field( wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) );

		$mail_from_email_callback = static function () use ( $from_email ) {
			return $from_email;
		};
		$mail_from_name_callback  = static function () use ( $mail_from_name ) {
			return $mail_from_name;
		};

		add_filter( 'wp_mail_from', $mail_from_email_callback );
		add_filter( 'wp_mail_from_name', $mail_from_name_callback );

		// Two separate sends keeps client copy lightweight while operators get the full blob.
		$headers_client = array( 'Content-Type: text/plain; charset=UTF-8' );
		$headers_admin  = array( 'Content-Type: text/plain; charset=UTF-8' );

		$site_label = sanitize_text_field( wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) );
		$date_local = wp_date( 'Y-m-d' );

		$results = array();
		if ( ! empty( $filtered_client ) ) {
			/* translators: 1: site title, 2: report date (Y-m-d, site timezone) */
			$subject_text = __( '[%1$s]様 WordPress 保守メンテナンス レポート - %2$s', 'wp-maintenance-audit-reporter' );
			$subject      = sprintf( $subject_text, $site_label, $date_local );
			$results[]    = wp_mail( $filtered_client, $subject, wp_strip_all_tags( $body_client ), $headers_client );
		}

		if ( ! empty( $filtered_admin ) ) {
			/* translators: 1: site title, 2: report date (Y-m-d, site timezone) */
			$subject_text = __( '[%1$s] 保守メンテナンス レポート - %2$s', 'wp-maintenance-audit-reporter' );
			$subject      = sprintf( $subject_text, $site_label, $date_local );
			$results[]    = wp_mail( $filtered_admin, $subject, wp_strip_all_tags( $body_admin ), $headers_admin );
		}

		remove_filter( 'wp_mail_from', $mail_from_email_callback );
		remove_filter( 'wp_mail_from_name', $mail_from_name_callback );

		// True only when every attempted batch returned truthy from wp_mail().
		return count( array_filter( $results ) ) === count( $results ) && ! empty( $results );
	}
}
