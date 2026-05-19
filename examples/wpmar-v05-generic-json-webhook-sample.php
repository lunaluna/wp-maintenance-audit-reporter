<?php
/**
 * Sample — generic JSON POST webhook for WP Maintenance Audit Reporter (v0.5+).
 *
 * Copy to `wp-content/mu-plugins/` and set WPMAR_GENERIC_WEBHOOK_URL (https endpoint).
 * Optional: WPMAR_GENERIC_WEBHOOK_SECRET sent as `X-WPMAR-Secret` header.
 *
 * Hook used: `wpmar_notification_channels`
 *
 * @package WPMAR_Examples
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WPMAR_GENERIC_WEBHOOK_URL' ) ) {
	return;
}

add_filter(
	'wpmar_notification_channels',
	static function ( $channels, $_context ) {
		unset( $_context );
		if ( ! is_array( $channels ) ) {
			$channels = array();
		}

		$channels[] = array(
			'id'   => 'wpmar_generic_webhook_sample',
			'send' => static function ( array $ctx ) {
				$body = array(
					'source'       => 'wpmar',
					'report_id'    => isset( $ctx['report_id'] ) ? absint( $ctx['report_id'] ) : 0,
					'home_url'     => isset( $ctx['home_url'] ) ? esc_url_raw( (string) $ctx['home_url'] ) : '',
					'mail_sent'    => ! empty( $ctx['mail_sent'] ),
					'triggered_by' => isset( $ctx['triggered_by'] ) ? sanitize_key( (string) $ctx['triggered_by'] ) : '',
					'client_copy'  => isset( $ctx['body_client_md'] ) ? (string) $ctx['body_client_md'] : '',
				);

				$headers = array( 'Content-Type' => 'application/json; charset=utf-8' );
				if ( defined( 'WPMAR_GENERIC_WEBHOOK_SECRET' ) && is_string( WPMAR_GENERIC_WEBHOOK_SECRET ) && '' !== WPMAR_GENERIC_WEBHOOK_SECRET ) {
					$headers['X-WPMAR-Secret'] = WPMAR_GENERIC_WEBHOOK_SECRET;
				}

				wp_remote_post(
					WPMAR_GENERIC_WEBHOOK_URL,
					array(
						'timeout' => 10,
						'headers' => $headers,
						'body'    => wp_json_encode( $body ),
					)
				);
			},
		);

		return $channels;
	},
	10,
	2
);
