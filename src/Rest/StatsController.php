<?php
/**
 * Stats REST controller.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Rest;

use WPActivityLedger\Database\ActiveSessions;
use WPActivityLedger\Database\EventStats;
use WPActivityLedger\Security\Capabilities;

/**
 * GET /stats: dashboard aggregates, cached for five minutes per range.
 *
 * Either a preset `range` (24h, grouped by hour; 7d or 30d, grouped by day, all ending now)
 * or `from`/`to` days in the site's timezone (default: the last seven days).
 */
final class StatsController extends BaseController {

	/**
	 * Transient key prefix.
	 */
	public const CACHE_PREFIX = 'wpal_stats_';

	/**
	 * Preset ranges: key => hours back from now.
	 */
	public const RANGES = array(
		'24h' => 24,
		'7d'  => 168,
		'30d' => 720,
	);

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
					'range' => array(
						'type' => 'string',
						'enum' => array_keys( self::RANGES ),
					),
					'from'  => $day + array( 'description' => __( 'First day, site timezone (Y-m-d).', 'wp-activity-ledger' ) ),
					'to'    => $day + array( 'description' => __( 'Last day, site timezone (Y-m-d).', 'wp-activity-ledger' ) ),
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
		$range = self::range( $request );

		if ( $range['from'] > $range['to'] ) {
			return self::error( 'wpal_invalid_range', __( 'The start date is after the end date.', 'wp-activity-ledger' ), 400 );
		}

		$key    = self::cache_key( $range['key'] );
		$cached = get_transient( $key );
		$hit    = is_array( $cached );

		if ( ! $hit ) {
			$cached = $this->compute( $range );
			set_transient( $key, $cached, self::CACHE_TTL );
		}

		$response = rest_ensure_response( $cached );
		$response->header( 'X-WPAL-Cache', $hit ? 'hit' : 'miss' );

		return $response;
	}

	/**
	 * Returns the cache key of a range.
	 *
	 * @param string $range_key Preset key, or "from|to" days.
	 *
	 * @return string Transient key.
	 */
	public static function cache_key( string $range_key ): string {
		return self::CACHE_PREFIX . md5( $range_key . '|' . wp_timezone_string() );
	}

	/**
	 * Runs the aggregate queries.
	 *
	 * @param array{key: string, from: string, to: string, bucket: string} $range Range (UTC).
	 *
	 * @return array<string, mixed> Aggregates with the range.
	 */
	private function compute( array $range ): array {
		$offset  = wp_timezone()->getOffset( new \DateTimeImmutable( $range['to'], new \DateTimeZone( 'UTC' ) ) );
		$summary = $this->stats->summary( $range['from'], $range['to'], $offset, $range['bucket'] );

		$summary['totals']['active_sessions'] = ActiveSessions::count();

		return array_merge( array( 'range' => $range ), $summary );
	}

	/**
	 * Returns the requested range in UTC.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return array{key: string, from: string, to: string, bucket: string} Range.
	 */
	private static function range( \WP_REST_Request $request ): array {
		$preset = is_string( $request['range'] ) ? $request['range'] : '';

		if ( isset( self::RANGES[ $preset ] ) ) {
			$now = time();

			return array(
				'key'    => $preset,
				'from'   => gmdate( 'Y-m-d H:i:s', $now - self::RANGES[ $preset ] * HOUR_IN_SECONDS ),
				'to'     => gmdate( 'Y-m-d H:i:s', $now ),
				'bucket' => '24h' === $preset ? 'hour' : 'day',
			);
		}

		return self::day_range( $request );
	}

	/**
	 * Returns a range of local days in UTC.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return array{key: string, from: string, to: string, bucket: string} Range.
	 */
	private static function day_range( \WP_REST_Request $request ): array {
		$zone  = wp_timezone();
		$utc   = new \DateTimeZone( 'UTC' );
		$today = new \DateTimeImmutable( 'now', $zone );
		$to    = is_string( $request['to'] ) ? $request['to'] : $today->format( 'Y-m-d' );
		$from  = is_string( $request['from'] ) ? $request['from'] : ( new \DateTimeImmutable( $to, $zone ) )->modify( '-' . ( self::DEFAULT_DAYS - 1 ) . ' days' )->format( 'Y-m-d' );

		return array(
			'key'    => $from . '|' . $to,
			'from'   => ( new \DateTimeImmutable( $from . ' 00:00:00', $zone ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'to'     => ( new \DateTimeImmutable( $to . ' 23:59:59', $zone ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'bucket' => 'day',
		);
	}
}
