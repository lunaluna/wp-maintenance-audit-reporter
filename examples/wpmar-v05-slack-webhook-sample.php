<?php
/**
 * Sample — Slack Incoming Webhook for WP Maintenance Audit Reporter (v0.5+).
 *
 * DO NOT drop this folder into plugins as-is. Copy this file to
 * `wp-content/mu-plugins/wpmar-slack-sample.php` (or a small custom plugin) and
 * define WPMAR_SLACK_WEBHOOK_URL in wp-config.php (or below) with your secret URL.
 *
 * Hook used: `wpmar_notification_channels`
 *
 * @package WPMAR_Examples
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WPMAR_SLACK_WEBHOOK_URL' ) ) {
	// Define in wp-config.php instead of hard-coding secrets here.
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
			'id'   => 'wpmar_slack_sample',
			'send' => static function ( array $ctx ) {
				$report_id = isset( $ctx['report_id'] ) ? absint( $ctx['report_id'] ) : 0;
				$home      = isset( $ctx['home_url'] ) ? esc_url_raw( (string) $ctx['home_url'] ) : '';
				$snippet   = isset( $ctx['body_client_md'] ) ? wp_strip_all_tags( (string) $ctx['body_client_md'] ) : '';
				$snippet   = function_exists( 'mb_substr' ) ? mb_substr( $snippet, 0, 800, 'UTF-8' ) : substr( $snippet, 0, 800 );

				$payload = array(
					'text' => sprintf(
						"WPMAR report #%d complete\nSite: %s\n---\n%s",
						$report_id,
						$home,
						$snippet
					),
				);

				wp_remote_post(
					WPMAR_SLACK_WEBHOOK_URL,
					array(
						'timeout'  => 10,
						'headers'  => array( 'Content-Type' => 'application/json; charset=utf-8' ),
						'body'     => wp_json_encode( $payload ),
						'blocking' => true,
					)
				);
			},
		);

		return $channels;
	},
	10,
	2
);
