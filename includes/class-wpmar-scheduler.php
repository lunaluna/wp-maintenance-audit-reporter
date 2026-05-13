<?php
/**
 * Monthly single-event chaining for WP‑Cron using a fixed timezone bucket.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules recurring monthly audits via `wp_schedule_single_event` chaining.
 */
class WPMAR_Scheduler {

	/**
	 * Registers the cron callback.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( WPMAR_HOOK_SCHEDULED, array( __CLASS__, 'handle_event' ) );
	}

	/**
	 * Clears any pending hook.
	 *
	 * @return void
	 */
	public static function clear() {
		wp_clear_scheduled_hook( WPMAR_HOOK_SCHEDULED );
	}

	/**
	 * Clears and schedules the next run from settings.
	 *
	 * @return void
	 */
	public static function reschedule() {
		self::clear();
		$settings = WPMAR_Settings::get_all();
		$next     = self::next_timestamp_after(
			new DateTimeImmutable( 'now', self::timezone_object( $settings ) ),
			$settings
		);
		wp_schedule_single_event( $next, WPMAR_HOOK_SCHEDULED );
	}

	/**
	 * Dispatches the runner for WP‑Cron.
	 *
	 * @return void
	 */
	public static function handle_event() {
		update_option( 'wpmar_wp_cron_last_fired_at', gmdate( 'c' ), false );
		$runner = new WPMAR_Runner();

		try {
			$runner->run(
				array(
					'dry'          => false,
					'triggered_by' => 'cron',
				)
			);
		} catch ( Exception $exception ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- opt-in noisy logging under WP_DEBUG.
				error_log( 'WPMAR cron error: ' . $exception->getMessage() );
			}
		}
	}

	/**
	 * Next Unix timestamp strictly after `$reference`.
	 *
	 * @param DateTimeImmutable   $reference Moment in TZ.
	 * @param array<string,mixed> $settings Contains schedule day/hour/minute/tz.
	 * @return int
	 */
	public static function next_timestamp_after( DateTimeImmutable $reference, array $settings ) {
		$day    = isset( $settings['schedule']['day'] ) ? absint( $settings['schedule']['day'] ) : 25;
		$hour   = isset( $settings['schedule']['hour'] ) ? absint( $settings['schedule']['hour'] ) : 2;
		$minute = isset( $settings['schedule']['minute'] ) ? absint( $settings['schedule']['minute'] ) : 0;
		$tz     = self::timezone_object( $settings );

		$localized = $reference->setTimezone( $tz );

		for ( $offset = 0; $offset < 36; ++$offset ) {
			try {
				$month_anchor = $localized->modify( 'first day of this month' )->modify( '+' . $offset . ' month' );
				$y            = (int) $month_anchor->format( 'Y' );
				$mon_num      = (int) $month_anchor->format( 'n' );
				// phpcs:disable WordPress.DateTime.RestrictedFunctions.cal_days_in_month -- Gregorian envelope for scheduled day clamps.
				$dim = (int) cal_days_in_month( CAL_GREGORIAN, $mon_num, $y );
				// phpcs:enable WordPress.DateTime.RestrictedFunctions.cal_days_in_month
				$dom = min( $day, max( $dim, 1 ) );

				$candidate_local = new DateTimeImmutable(
					sprintf(
						'%04d-%02d-%02d %02d:%02d:%02d',
						$y,
						$mon_num,
						$dom,
						$hour,
						$minute,
						0
					),
					$tz
				);
			} catch ( Exception $e ) {
				continue;
			}

			if ( $candidate_local > $localized ) {
				return $candidate_local->getTimestamp();
			}
		}

		return time() + HOUR_IN_SECONDS;
	}

	/**
	 * Validated TZ object fallback Asia/Tokyo.
	 *
	 * @param array<string,mixed> $settings Settings array with optional schedule.tz string.
	 * @return DateTimeZone
	 */
	protected static function timezone_object( array $settings ) {
		$slug = isset( $settings['schedule']['tz'] ) ? sanitize_text_field( $settings['schedule']['tz'] ) : '';
		try {
			if ( '' !== $slug ) {
				return new DateTimeZone( $slug );
			}
		} catch ( Exception $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Explicit fallback TZ.
			// Fallback below.
			unset( $ignored );
		}

		return new DateTimeZone( 'Asia/Tokyo' );
	}
}
