<?php
/**
 * Deletes logged events on request.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Maintenance;

use WPActivityLedger\Database\EventRepository;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Recording\Recorder;

/**
 * Purges events (all, older than a local day, or matching filters) and records the purge
 * right after deleting, so that record always survives. Used by POST /purge and WP-CLI.
 */
final class Purger {

	/**
	 * Creates the purger.
	 *
	 * @param EventRepository $repository Event storage.
	 * @param Recorder        $recorder   Recorder.
	 */
	public function __construct(
		private readonly EventRepository $repository,
		private readonly Recorder $recorder
	) {
	}

	/**
	 * Returns the filter for "events before this local day".
	 *
	 * @param string $day Day, "Y-m-d" in the site's timezone.
	 *
	 * @return array{to: string} Filter (UTC end, inclusive).
	 */
	public static function before( string $day ): array {
		$midnight = new \DateTimeImmutable( $day . ' 00:00:00', wp_timezone() );

		return array( 'to' => $midnight->setTimezone( new \DateTimeZone( 'UTC' ) )->modify( '-1 second' )->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Deletes the events matching the filters ([] for all) and records it.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param string               $mode    "all", "before" or "filter" (for the record).
	 *
	 * @return int Events deleted.
	 */
	public function purge( array $filters, string $mode ): int {
		$deleted = $this->repository->delete_where( $filters );

		$this->recorder->record_now(
			new Event(
				'system.logs_purged',
				object_type: 'logs',
				object_label: 'Activity log',
				context: array(
					'count'   => $deleted,
					'mode'    => $mode,
					'filters' => $filters,
				)
			)
		);

		return $deleted;
	}
}
