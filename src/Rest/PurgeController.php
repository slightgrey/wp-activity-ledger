<?php
/**
 * Purge REST controller.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Rest;

use WPActivityLedger\Admin\ExportHandler;
use WPActivityLedger\Database\EventRepository;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Recording\Recorder;
use WPActivityLedger\Security\Capabilities;

/**
 * POST /purge: deletes all events, events older than a day (site timezone), or the events
 * matching a filter. Needs `wpal_delete_logs` and the typed confirmation "PURGE".
 *
 * The purge itself is recorded after deleting (record_now), so that row always survives.
 */
final class PurgeController extends BaseController {

	/**
	 * Confirmation word.
	 */
	public const CONFIRMATION = 'PURGE';

	/**
	 * Creates the controller.
	 *
	 * @param EventRepository $repository Event storage.
	 * @param Recorder        $recorder   Recorder.
	 */
	public function __construct(
		private readonly EventRepository $repository,
		private readonly Recorder $recorder
	) {
		$this->rest_base = 'purge';
	}

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/purge',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'purge' ),
				'permission_callback' => self::permission( Capabilities::DELETE ),
				'args'                => array(
					'mode'    => array(
						'type'     => 'string',
						'enum'     => array( 'all', 'before', 'filter' ),
						'required' => true,
					),
					'before'  => array(
						'type'    => 'string',
						'pattern' => '^\d{4}-\d{2}-\d{2}$',
					),
					'filters' => array(
						'type'    => 'object',
						'default' => array(),
					),
					'confirm' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Deletes the chosen events and records the purge.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error Result ({ deleted }), or 400.
	 */
	public function purge( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( self::CONFIRMATION !== $request['confirm'] ) {
			return self::error( 'wpal_purge_unconfirmed', __( 'Type PURGE to confirm.', 'wp-activity-ledger' ), 400, array( 'errors' => array( 'confirm' => __( 'Type PURGE to confirm.', 'wp-activity-ledger' ) ) ) );
		}

		$filters = self::filters( $request );

		if ( $filters instanceof \WP_Error ) {
			return self::error( 'wpal_purge_invalid', $filters->get_error_message(), 400 );
		}

		$deleted = $this->repository->delete_where( $filters );
		$this->record( (string) $request['mode'], $filters, $deleted );

		return rest_ensure_response( array( 'deleted' => $deleted ) );
	}

	/**
	 * Returns the query filters of the chosen purge mode.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return array<string, mixed>|\WP_Error Filters ([] for all), or an error.
	 */
	private static function filters( \WP_REST_Request $request ): array|\WP_Error {
		switch ( $request['mode'] ) {
			case 'before':
				if ( ! is_string( $request['before'] ) ) {
					return new \WP_Error( 'wpal_purge_date', __( 'Choose a date.', 'wp-activity-ledger' ) );
				}

				$midnight = new \DateTimeImmutable( $request['before'] . ' 00:00:00', wp_timezone() );

				return array( 'to' => $midnight->setTimezone( new \DateTimeZone( 'UTC' ) )->modify( '-1 second' )->format( 'Y-m-d H:i:s' ) );

			case 'filter':
				$filters = ExportHandler::filters( (array) $request['filters'] );

				if ( is_array( $filters ) && array() === $filters ) {
					return new \WP_Error( 'wpal_purge_filter', __( 'Set at least one filter, or choose "all".', 'wp-activity-ledger' ) );
				}

				return $filters;

			default:
				return array();
		}
	}

	/**
	 * Records the purge (stored right away, after the deletion).
	 *
	 * @param string               $mode    Mode.
	 * @param array<string, mixed> $filters Filters used.
	 * @param int                  $deleted Events deleted.
	 *
	 * @return void
	 */
	private function record( string $mode, array $filters, int $deleted ): void {
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
	}
}
