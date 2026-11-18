<?php
/**
 * Users REST controller.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Rest;

use WPActivityLedger\Database\UserActivity;
use WPActivityLedger\Security\Capabilities;

/**
 * GET /users: a paged user list with activity in a range (last activity, last login, event
 * count, failed logins, roles). Also used for the user filter's autocomplete ("search").
 *
 * Sorted on the server by login, last activity or event count (`orderby`, `order`), so the
 * order holds across pages.
 */
final class UsersController extends BaseController {

	/**
	 * Default range length in days.
	 */
	private const DEFAULT_DAYS = 30;

	/**
	 * Sort columns.
	 */
	public const ORDERBY = array( 'login', 'last_activity', 'events' );

	/**
	 * Creates the controller.
	 *
	 * @param UserActivity $activity Activity aggregates.
	 */
	public function __construct( private readonly UserActivity $activity ) {
		$this->rest_base = 'users';
	}

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/users',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn( \WP_REST_Request $request ) => self::in_site( $request, fn() => $this->get_items( $request ) ),
				'permission_callback' => self::permission( Capabilities::VIEW ),
				'args'                => array(
					'site_id'  => self::site_arg(),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 25,
					),
					'search'   => array(
						'type'      => 'string',
						'maxLength' => 60,
					),
					'role'     => array(
						'type'    => 'string',
						'pattern' => '^[a-z0-9_-]{1,60}$',
					),
					'include'  => array(
						'type'  => 'array',
						'items' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'from'     => self::date_arg( __( 'Range start, UTC (default: 30 days ago).', 'wp-activity-ledger' ) ),
					'to'       => self::date_arg( __( 'Range end, UTC (default: now).', 'wp-activity-ledger' ) ),
					'orderby'  => array(
						'type'        => 'string',
						'enum'        => self::ORDERBY,
						'default'     => 'login',
						'description' => __( 'Sort by login, last activity (all time) or events in the range. Users without activity come last.', 'wp-activity-ledger' ),
					),
					'order'    => array(
						'type'        => 'string',
						'enum'        => array( 'asc', 'desc' ),
						'description' => __( 'Direction (default: asc for login, desc otherwise).', 'wp-activity-ledger' ),
					),
				),
			)
		);
	}

	/**
	 * Returns a page of users with their activity.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response Response with total headers.
	 */
	public function get_items( $request ): \WP_REST_Response {
		[ $from, $to ] = self::range( $request );
		$orderby       = in_array( $request['orderby'], self::ORDERBY, true ) ? (string) $request['orderby'] : 'login';
		$order         = is_string( $request['order'] ) ? $request['order'] : ( 'login' === $orderby ? 'asc' : 'desc' );
		$query         = $this->user_query( self::user_query_args( $request, $order ), $orderby, $order, $from, $to );
		$users         = array_filter( (array) $query->get_results(), static fn( $user ): bool => $user instanceof \WP_User );

		$activity = $this->activity->for_users( array_map( static fn( \WP_User $user ): int => $user->ID, $users ), $from, $to );
		$items    = array_map( static fn( \WP_User $user ): array => self::item( $user, $activity[ $user->ID ] ?? array() ), array_values( $users ) );

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (string) $query->get_total() );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $query->get_total() / max( 1, (int) $request['per_page'] ) ) );

		return $response;
	}

	/**
	 * Runs the user query, joined to the activity aggregates when sorting by activity.
	 *
	 * @param array<string, mixed> $args    WP_User_Query arguments.
	 * @param string               $orderby Sort column.
	 * @param string               $order   "asc" or "desc".
	 * @param string               $from    Range start (UTC).
	 * @param string               $to      Range end (UTC).
	 *
	 * @return \WP_User_Query Executed query.
	 */
	private function user_query( array $args, string $orderby, string $order, string $from, string $to ): \WP_User_Query {
		if ( 'login' === $orderby ) {
			return new \WP_User_Query( $args );
		}

		global $wpdb;

		$clauses = $this->activity->order_clauses( $wpdb->users, $orderby, $order, $from, $to );
		$sort    = static function ( \WP_User_Query $query ) use ( $clauses ): void {
			$query->query_from   .= $clauses['join'];
			$query->query_orderby = $clauses['orderby'];
		};

		// The query runs in its constructor, so the hook only ever sees this one query.
		add_action( 'pre_user_query', $sort );

		try {
			return new \WP_User_Query( $args );
		} finally {
			remove_action( 'pre_user_query', $sort );
		}
	}

	/**
	 * Builds the user query arguments.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $order   "asc" or "desc" (used for login sorting).
	 *
	 * @return array<string, mixed> WP_User_Query arguments.
	 */
	private static function user_query_args( \WP_REST_Request $request, string $order ): array {
		$args = array(
			'number'      => (int) $request['per_page'],
			'paged'       => (int) $request['page'],
			'orderby'     => 'login',
			'order'       => strtoupper( $order ),
			'count_total' => true,
		);

		if ( is_string( $request['search'] ) && '' !== trim( $request['search'] ) ) {
			$args['search']         = '*' . trim( $request['search'] ) . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name', 'user_nicename' );
		}

		if ( is_string( $request['role'] ) ) {
			$args['role'] = $request['role'];
		}

		if ( is_array( $request['include'] ) && array() !== $request['include'] ) {
			$args['include'] = array_map( 'intval', $request['include'] );
		}

		return $args;
	}

	/**
	 * Returns the activity range in UTC.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return array{0: string, 1: string} Start and end ("Y-m-d H:i:s").
	 */
	private static function range( \WP_REST_Request $request ): array {
		$to   = is_string( $request['to'] ) ? self::full_datetime( $request['to'], '23:59:59' ) : gmdate( 'Y-m-d H:i:s' );
		$from = is_string( $request['from'] ) ? self::full_datetime( $request['from'], '00:00:00' ) : gmdate( 'Y-m-d H:i:s', time() - self::DEFAULT_DAYS * DAY_IN_SECONDS );

		return array( $from, $to );
	}

	/**
	 * Completes a date to "Y-m-d H:i:s".
	 *
	 * @param string $value        Date or datetime.
	 * @param string $default_time Time for bare dates.
	 *
	 * @return string Datetime.
	 */
	private static function full_datetime( string $value, string $default_time ): string {
		return 10 === strlen( $value ) ? $value . ' ' . $default_time : substr( $value . ':00', 0, 19 );
	}

	/**
	 * Builds one list item.
	 *
	 * @param \WP_User             $user     User.
	 * @param array<string, mixed> $activity Activity.
	 *
	 * @return array<string, mixed> Item.
	 */
	private static function item( \WP_User $user, array $activity ): array {
		$avatar = get_avatar_url( $user->ID, array( 'size' => 48 ) );

		return array(
			'id'            => $user->ID,
			'login'         => $user->user_login,
			'name'          => $user->display_name,
			'email'         => $user->user_email,
			'avatar'        => is_string( $avatar ) ? $avatar : null,
			'roles'         => array_values( $user->roles ),
			'last_activity' => $activity['last_activity'] ?? null,
			'last_login'    => $activity['last_login'] ?? null,
			'events'        => (int) ( $activity['events'] ?? 0 ),
			'failed_logins' => (int) ( $activity['failed_logins'] ?? 0 ),
		);
	}
}
