<?php
/**
 * Daily retention clean-up.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Maintenance;

use WPActivityLedger\Database\EventRepository;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Loader;
use WPActivityLedger\Recording\Recorder;
use WPActivityLedger\Settings\Settings;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Deleting expired transients of our own prefix.

/**
 * Runs once a day ("wpal_daily_maintenance"): deletes events older than the retention
 * period and beyond the row limit, at most 5,000 per run. When more remain, a follow-up run
 * is scheduled a minute later ("wpal_maintenance_continue"), so big clean-ups never lock the
 * table for long. Also removes expired dashboard caches, and records what it deleted.
 */
final class Pruner {

	/**
	 * Daily hook.
	 */
	public const HOOK = 'wpal_daily_maintenance';

	/**
	 * Follow-up hook (single events while a backlog remains).
	 */
	public const CONTINUE_HOOK = 'wpal_maintenance_continue';

	/**
	 * Events deleted per run.
	 */
	public const BATCH = 5000;

	/**
	 * Creates the pruner.
	 *
	 * @param EventRepository $repository Event storage.
	 * @param Settings        $settings   Settings (retention).
	 * @param Recorder        $recorder   Recorder.
	 */
	public function __construct(
		private readonly EventRepository $repository,
		private readonly Settings $settings,
		private readonly Recorder $recorder
	) {
	}

	/**
	 * Queues the hooks.
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( self::HOOK, array( $this, 'run' ), 10, 0 );
		$loader->add_action( self::CONTINUE_HOOK, array( $this, 'run' ), 10, 0 );
		$loader->add_action( 'init', array( self::class, 'schedule' ), 20, 0 );
	}

	/**
	 * Schedules the daily run if it is missing (on activation, and repaired on init).
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Deletes old events (one batch), schedules a follow-up when more remain, and records it.
	 *
	 * @return array{expired: int, excess: int, more: bool} What was deleted.
	 */
	public function run(): array {
		$expired = $this->delete_expired( self::BATCH );
		$excess  = $this->delete_excess( self::BATCH - $expired );
		$more    = $expired + $excess >= self::BATCH;

		if ( $more && false === wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CONTINUE_HOOK );
		}

		self::delete_expired_stats();

		if ( $expired + $excess > 0 ) {
			$this->record( $expired, $excess, $more );
		}

		return array(
			'expired' => $expired,
			'excess'  => $excess,
			'more'    => $more,
		);
	}

	/**
	 * Deletes events older than the retention period.
	 *
	 * @param int $limit Most events to delete.
	 *
	 * @return int Deleted.
	 */
	private function delete_expired( int $limit ): int {
		$days = (int) $this->settings->get( 'retention_days' );

		if ( $days <= 0 || $limit <= 0 ) {
			return 0;
		}

		return $this->repository->delete_older_than( gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ), $limit );
	}

	/**
	 * Deletes the oldest events beyond the row limit.
	 *
	 * @param int $limit Most events to delete.
	 *
	 * @return int Deleted.
	 */
	private function delete_excess( int $limit ): int {
		$max_rows = (int) $this->settings->get( 'max_rows' );

		return $max_rows <= 0 || $limit <= 0 ? 0 : $this->repository->delete_beyond( $max_rows, $limit );
	}

	/**
	 * Removes expired dashboard caches ("wpal_stats_*" transients).
	 *
	 * @return void
	 */
	private static function delete_expired_stats(): void {
		global $wpdb;

		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_wpal_stats_' ) . '%',
				time()
			)
		);

		foreach ( (array) $names as $name ) {
			delete_transient( substr( (string) $name, strlen( '_transient_timeout_' ) ) );
		}
	}

	/**
	 * Records the run.
	 *
	 * @param int  $expired Deleted for age.
	 * @param int  $excess  Deleted for the row limit.
	 * @param bool $more    Whether a follow-up run is scheduled.
	 *
	 * @return void
	 */
	private function record( int $expired, int $excess, bool $more ): void {
		$this->recorder->record_now(
			new Event(
				'system.pruned',
				object_type: 'logs',
				object_label: 'Activity log',
				context: array(
					'count'          => $expired + $excess,
					'expired'        => $expired,
					'over_limit'     => $excess,
					'retention_days' => (int) $this->settings->get( 'retention_days' ),
					'max_rows'       => (int) $this->settings->get( 'max_rows' ),
					'more'           => $more,
				)
			)
		);
	}
}
