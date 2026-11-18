<?php
/**
 * Events REST controller.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Rest;

use WPActivityLedger\Database\EventRepository;
use WPActivityLedger\Database\QueryBuilder;
use WPActivityLedger\Security\Capabilities;

/**
 * GET /events (filtered, paged list) and GET /events/{id} (one event with its changes).
 *
 * Events are read-only: there are no routes to edit or delete single events.
 */
final class EventsController extends BaseController {

	/**
	 * Filter arguments passed on to the query builder.
	 */
	private const FILTERS = array( 'page', 'per_page', 'from', 'to', 'user', 'role', 'category', 'type', 'severity', 'object_type', 'object_id', 'ip', 'session', 'search', 'orderby', 'order' );

	/**
	 * Creates the controller.
	 *
	 * @param EventRepository $repository Event storage.
	 */
	public function __construct( private readonly EventRepository $repository ) {
		$this->rest_base = 'events';
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => self::permission( Capabilities::VIEW ),
				'args'                => self::filter_args(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => self::permission( Capabilities::VIEW ),
				'args'                => array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			)
		);
	}

	/**
	 * Returns a page of events with total headers.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response Response.
	 */
	public function get_items( $request ): \WP_REST_Response {
		$result   = $this->repository->query( self::filters( $request ) );
		$response = rest_ensure_response( array_map( array( self::class, 'expand' ), $result['items'] ) );

		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $result['total'] / max( 1, $result['per_page'] ) ) );

		return $response;
	}

	/**
	 * Returns one event with its changes.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error Response, or 404.
	 */
	public function get_item( $request ): \WP_REST_Response|\WP_Error {
		$event = $this->repository->find( (int) $request['id'] );

		if ( null === $event ) {
			return self::error( 'wpal_event_not_found', __( 'Event not found.', 'wp-activity-ledger' ), 404 );
		}

		return rest_ensure_response( self::expand( $event ) );
	}

	/**
	 * Adds the actor's display data and the object's edit link to an event.
	 *
	 * @param array<string, mixed> $event Event.
	 *
	 * @return array<string, mixed> Event.
	 */
	public static function expand( array $event ): array {
		$event['user']      = self::actor( (int) $event['user_id'], (string) $event['user_login'] );
		$event['edit_link'] = ObjectLinks::edit_link( (string) $event['object_type'], (string) $event['object_id'] );

		return $event;
	}

	/**
	 * Returns the actor's display data (the stored login when the user is gone).
	 *
	 * @param int    $user_id    User ID.
	 * @param string $user_login Login snapshot.
	 *
	 * @return array{id: int, login: string, name: string, avatar: string|null, exists: bool} Actor.
	 */
	private static function actor( int $user_id, string $user_login ): array {
		$user   = $user_id > 0 ? get_userdata( $user_id ) : false;
		$avatar = $user_id > 0 ? get_avatar_url( $user_id, array( 'size' => 48 ) ) : false;

		return array(
			'id'     => $user_id,
			'login'  => $user_login,
			'name'   => $user instanceof \WP_User ? $user->display_name : $user_login,
			'avatar' => is_string( $avatar ) ? $avatar : null,
			'exists' => $user instanceof \WP_User,
		);
	}

	/**
	 * Returns the filters given in a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return array<string, mixed> Filters for the query builder.
	 */
	public static function filters( \WP_REST_Request $request ): array {
		$filters = array();

		foreach ( self::FILTERS as $name ) {
			if ( null !== $request->get_param( $name ) ) {
				$filters[ $name ] = $request->get_param( $name );
			}
		}

		return $filters;
	}

	/**
	 * Returns the argument schema of the event filters.
	 *
	 * @return array<string, array<string, mixed>> Arguments.
	 */
	public static function filter_args(): array {
		$list = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);

		return array(
			'page'        => array(
				'type'    => 'integer',
				'minimum' => 1,
				'default' => 1,
			),
			'per_page'    => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => QueryBuilder::MAX_PER_PAGE,
				'default' => 25,
			),
			'from'        => self::date_arg( __( 'Start, UTC.', 'wp-activity-ledger' ) ),
			'to'          => self::date_arg( __( 'End, UTC.', 'wp-activity-ledger' ) ),
			'user'        => array(
				'type'  => 'array',
				'items' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
			'role'        => array(
				'type'    => 'string',
				'pattern' => '^[a-z0-9_-]{1,60}$',
			),
			'category'    => $list,
			'type'        => $list,
			'severity'    => array(
				'type' => 'string',
				'enum' => array( '0', '1', '2', '3', 'info', 'notice', 'warning', 'critical' ),
			),
			'object_type' => array(
				'type'      => 'string',
				'maxLength' => 32,
			),
			'object_id'   => array(
				'type'      => 'string',
				'maxLength' => 64,
			),
			'ip'          => array(
				'type'      => 'string',
				'maxLength' => 45,
			),
			'session'     => array(
				'type'    => 'string',
				'pattern' => '^[a-f0-9]{1,16}$',
			),
			'search'      => array(
				'type'      => 'string',
				'maxLength' => 100,
			),
			'orderby'     => array(
				'type'    => 'string',
				'enum'    => QueryBuilder::ORDERBY,
				'default' => 'created_at',
			),
			'order'       => array(
				'type'    => 'string',
				'enum'    => array( 'asc', 'desc' ),
				'default' => 'desc',
			),
		);
	}
}
