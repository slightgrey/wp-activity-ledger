<?php
/**
 * Background queue for alert deliveries.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Alerts;

/**
 * Schedules "wpal_deliver_alert" jobs with Action Scheduler when it is available (it ships
 * with WooCommerce), otherwise as single WP-Cron events.
 */
final class Queue {

	/**
	 * Job hook.
	 */
	public const HOOK = 'wpal_deliver_alert';

	/**
	 * Action Scheduler group.
	 */
	public const GROUP = 'wpal';

	/**
	 * Schedules a job.
	 *
	 * @param array<string, mixed> $job   Job: channel, message, attempt.
	 * @param int                  $delay Seconds from now (0 to run as soon as possible).
	 *
	 * @return void
	 */
	public static function push( array $job, int $delay = 0 ): void {
		// A unique ID keeps identical jobs apart (WP-Cron drops duplicates within 10 minutes).
		$job['job'] = $job['job'] ?? wp_generate_uuid4();

		if ( function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_schedule_single_action' ) ) {
			0 === $delay
				? as_enqueue_async_action( self::HOOK, array( $job ), self::GROUP )
				: as_schedule_single_action( time() + $delay, self::HOOK, array( $job ), self::GROUP );

			return;
		}

		wp_schedule_single_event( time() + $delay, self::HOOK, array( $job ) );
	}
}
