<?php
/**
 * Stats REST controller.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Rest;

use WPActivityLedger\Database\EventStats;
use WPActivityLedger\Security\Capabilities;

/**
 * GET /stats: dashboard aggregates for a date range in the site's timezone, cached for five
 * minutes per range.
 */
final class StatsController extends BaseController {

	/**
	 * Transient key prefix.
	 */
	public const CACHE_PREFIX = 'wpal_stats_';

	/**
	 * Cache lifetime in seconds.
	 */
	private const CACHE_TTL = 300;

	/**
	 * Default range length in days (today and the six days before).
	 */
	private const DEFAULT_DAYS = 7;

	/**
	 * Creates the controller.
	 *
	 * @param EventStats $stats Aggregate queries.
	 */
	public function __construct( private readonly EventStats $stats ) {
		$this->rest_base = 'stats';
	}

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$day = array(
			'type'    => 'string',
			'pattern' => '^\d{4}-\d{2}-\d{2}$',
		);

		register_rest_route(
			$this->namespace,
			'/stats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => self::permission( Capabilities::VIEW ),
				'args'                => array(
					'from' => $day + array( 'description' => __( 'First day, site timezone (Y-m-d).', 'wp-activity-ledger' ) ),
					'to'   => $day + array( 'description' => __( 'Last day, site timezone (Y-m-d).', 'wp-activity-ledger' ) ),
				),
			)
		);
	}

	/**
	 * Returns the aggregates for the requested range.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error Response, or 400 for an inverted range.
	 */
	public function get_stats( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		[ $from, $to ] = self::range( $request );

		if ( $from > $to ) {
			return self::error( 'wpal_invalid_range', __( 'The start date is after the end date.', 'wp-activity-ledger' ), 400 );
		}

		$key    = self::CACHE_PREFIX . md5( $from . '|' . $to . '|' . wp_timezone_string() );
		$cached = get_transient( $key );
		$hit    = is_array( $cached );

		if ( ! $hit ) {
			$cached = $this->compute( $from, $to );
			set_transient( $key, $cached, self::CACHE_TTL );
		}

		$response = rest_ensure_response( $cached );
		$response->header( 'X-WPAL-Cache', $hit ? 'hit' : 'miss' );

		return $response;
	}

	/**
	 * Runs the aggregate queries for a range of local days.
	 *
	 * @param string $from First day (Y-m-d, site timezone).
	 * @param string $to   Last day (Y-m-d, site timezone).
	 *
	 * @return array<string, mixed> Aggregates with the range.
	 */
	private function compute( string $from, string $to ): array {
		$zone  = wp_timezone();
		$utc   = new \DateTimeZone( 'UTC' );
		$start = ( new \DateTimeImmutable( $from . ' 00:00:00', $zone ) )->setTimezone( $utc );
		$end   = ( new \DateTimeImmutable( $to . ' 23:59:59', $zone ) )->setTimezone( $utc );

		return array_merge(
			array(
				'range' => array(
					'from' => $from,
					'to'   => $to,
				),
			),
			$this->stats->summary( $start->format( 'Y-m-d H:i:s' ), $end->format( 'Y-m-d H:i:s' ), $zone->getOffset( $end ) )
		);
	}

	/**
	 * Returns the requested range, defaulting to the last seven local days.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return array{0: string, 1: string} First and last day (Y-m-d).
	 */
	private static function range( \WP_REST_Request $request ): array {
		$today = new \DateTimeImmutable( 'now', wp_timezone() );
		$to    = is_string( $request['to'] ) ? $request['to'] : $today->format( 'Y-m-d' );
		$from  = is_string( $request['from'] ) ? $request['from'] : ( new \DateTimeImmutable( $to, wp_timezone() ) )->modify( '-' . ( self::DEFAULT_DAYS - 1 ) . ' days' )->format( 'Y-m-d' );

		return array( $from, $to );
	}
}
