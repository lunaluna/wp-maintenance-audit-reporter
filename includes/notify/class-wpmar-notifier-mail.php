<?php
/**
 * Dual-audience mail: stakeholder summary and operator diagnostic copy.
 *
 * Client copy may be sent as `text/html` (Markdown→HTML via Parsedown) when Composer
 * provides Parsedown; administrators still receive `text/plain`.
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
	 * Plain-text part for multipart mail while sending HTML client body (PHPMailer AltBody).
	 *
	 * @var string|null
	 */
	protected static $client_mail_plain_alt = null;

	/**
	 * Sends two distinct mail batches (client + admin copies).
	 *
	 * @param array<string,mixed> $settings  Full plugin settings envelope.
	 * @param string              $body_client Accessible summary for stakeholders.
	 * @param string              $body_admin  Verbose operator payload.
	 * @param string|string[]     $qa_override When non-empty overrides every outbound recipient list equally.
	 * @param string              $mail_qa_extra Optional single mailbox: receives an additional **client** mail and an additional **admin** mail (two sends when not duplicate of configured lists) without replacing `client_to` / `admin_to`.
	 * @return bool True when something sent successfully.
	 */
	public static function send_pair( array $settings, $body_client, $body_admin, $qa_override = array(), $mail_qa_extra = '' ) {
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
				if ( is_email( $candidate ) ) {
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

		$qa_extra = sanitize_email( trim( (string) $mail_qa_extra ) );
		if ( '' === $qa_extra || ! is_email( $qa_extra ) ) {
			$qa_extra = '';
		}

		if ( empty( $filtered_client ) && empty( $filtered_admin ) && '' === $qa_extra ) {
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
		$headers_admin = array( 'Content-Type: text/plain; charset=UTF-8' );

		$site_label = sanitize_text_field( wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) );
		$date_local = wp_date( 'Y-m-d' );

		/* translators: 1: site title, 2: report date (Y-m-d, site timezone) */
		$client_subject_text = __( '[%1$s]様 WordPress 保守メンテナンス レポート - %2$s', 'wp-maintenance-audit-reporter' );
		$client_subject      = sprintf( $client_subject_text, $site_label, $date_local );

		$html_enabled = (bool) apply_filters( 'wpmar_client_mail_html_enabled', true, $settings, $body_client );
		$html_body    = '';
		if ( $html_enabled ) {
			$html_body = self::build_client_html_email_body( $body_client );
		}

		$client_batches = array();
		if ( ! empty( $filtered_client ) ) {
			$client_batches[] = $filtered_client;
		}
		if ( '' !== $qa_extra && ! in_array( $qa_extra, $filtered_client, true ) ) {
			$client_batches[] = $qa_extra;
		}

		$results = array();
		foreach ( $client_batches as $client_to_batch ) {
			if ( '' !== $html_body ) {
				add_action( 'phpmailer_init', array( __CLASS__, 'phpmailer_set_client_alt_body' ), 10, 1 );
				self::$client_mail_plain_alt = wp_strip_all_tags( (string) $body_client );

				$headers_html = array( 'Content-Type: text/html; charset=UTF-8' );
				$results[]    = wp_mail( $client_to_batch, $client_subject, $html_body, $headers_html );

				remove_action( 'phpmailer_init', array( __CLASS__, 'phpmailer_set_client_alt_body' ), 10 );
				self::$client_mail_plain_alt = null;
			} else {
				$headers_txt = array( 'Content-Type: text/plain; charset=UTF-8' );
				$results[]   = wp_mail( $client_to_batch, $client_subject, wp_strip_all_tags( (string) $body_client ), $headers_txt );
			}
		}

		/* translators: 1: site title, 2: report date (Y-m-d, site timezone) */
		$admin_subject_text = __( '[%1$s] 保守メンテナンス レポート - %2$s', 'wp-maintenance-audit-reporter' );
		$admin_subject      = sprintf( $admin_subject_text, $site_label, $date_local );

		if ( ! empty( $filtered_admin ) ) {
			$results[] = wp_mail( $filtered_admin, $admin_subject, wp_strip_all_tags( $body_admin ), $headers_admin );
		}

		if ( '' !== $qa_extra && ! in_array( $qa_extra, $filtered_admin, true ) ) {
			$results[] = wp_mail( $qa_extra, $admin_subject, wp_strip_all_tags( $body_admin ), $headers_admin );
		}

		remove_filter( 'wp_mail_from', $mail_from_email_callback );
		remove_filter( 'wp_mail_from_name', $mail_from_name_callback );

		// True only when every attempted batch returned truthy from wp_mail().
		return count( array_filter( $results ) ) === count( $results ) && ! empty( $results );
	}

	/**
	 * Adds a plain-text alternative so HTML clients get rich layout and plain clients stay readable.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
	 */
	public static function phpmailer_set_client_alt_body( $phpmailer ) {
		if ( null === self::$client_mail_plain_alt ) {
			return;
		}
		if ( is_object( $phpmailer ) && property_exists( $phpmailer, 'AltBody' ) ) {
			// PHPMailer public API (not WordPress snake_case).
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName -- PHPMailer property.
			$phpmailer->AltBody = self::$client_mail_plain_alt;
		}
	}

	/**
	 * Wraps Parsedown HTML (same source as PDF) for email clients.
	 *
	 * @param string $markdown Client Markdown body.
	 * @return string Full HTML document fragment, or empty to fall back to plain text.
	 */
	protected static function build_client_html_email_body( $markdown ) {
		$fragment = WPMAR_PDF_Writer::markdown_to_html_fragment( (string) $markdown );
		if ( '' === trim( $fragment ) ) {
			return '';
		}

		$inner = wp_kses_post( $fragment );

		return '<div style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Hiragino Sans\',\'Hiragino Kaku Gothic ProN\',Meiryo,sans-serif;font-size:15px;line-height:1.65;color:#1a1a1a;">'
			. '<div style="max-width:640px;">'
			. $inner
			. '</div></div>';
	}
}
