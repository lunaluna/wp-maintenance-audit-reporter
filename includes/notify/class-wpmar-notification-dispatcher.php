<?php
/**
 * Dispatches supplementary notification channels filtered in by extensions (Slack/Webhook, etc.).
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Invokes callable channels registered via {@see 'wpmar_notification_channels'}.
 */
class WPMAR_Notification_Dispatcher {

	/**
	 * Runs every registered notifier after the core mail pair attempt.
	 *
	 * Expected channel shape: `[ 'send' => callable( array $context ): void|string|bool ]`
	 *
	 * @param array<string,mixed> $settings Full plugin settings.
	 * @param array<string,mixed> $context  Shared payload (report_id, Markdown bodies, etc.).
	 * @return void
	 */
	public static function dispatch( array $settings, array $context ) {
		unset( $settings );

		$channels = apply_filters( 'wpmar_notification_channels', array(), $context );

		if ( empty( $channels ) || ! is_array( $channels ) ) {
			return;
		}

		foreach ( $channels as $channel ) {
			if ( ! is_array( $channel ) ) {
				continue;
			}
			if ( empty( $channel['send'] ) || ! is_callable( $channel['send'] ) ) {
				continue;
			}

			try {
				call_user_func( $channel['send'], $context );
			} catch ( Exception $ignored ) {
				unset( $ignored );
			}
		}
	}
}
