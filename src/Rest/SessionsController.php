<?php
/**
 * Sessions REST controller.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Rest;

use WPActivityLedger\Domain\Event;
use WPActivityLedger\Recording\Recorder;
use WPActivityLedger\Security\Capabilities;
use WPActivityLedger\Security\SessionStore;

/**
 * GET /users/{id}/sessions, DELETE /users/{id}/sessions/{verifier} and DELETE
 * /users/{id}/sessions (the current session survives when users end their own).
 *
 * Needs `wpal_manage_sessions` plus `edit_user` on the target. Ending sessions is recorded.
 */
final class SessionsController extends BaseController {

	/**
	 * Creates the controller.
	 *
	 * @param Recorder $recorder Recorder.
	 */
	public function __construct( private readonly Recorder $recorder ) {
		$this->rest_base = 'users';
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$id = array(
			'id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);

		register_rest_route(
			$this->namespace,
			'/users/(?P<id>\d+)/sessions',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_sessions' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => $id,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'destroy_all' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => $id,
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/users/(?P<id>\d+)/sessions/(?P<verifier>[a-f0-9]{64})',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'destroy_one' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => $id,
			)
		);
	}

	/**
	 * Checks `wpal_manage_sessions` and `edit_user` on the target user.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return true|\WP_Error True, or 401/403.
	 */
	public function can_manage( \WP_REST_Request $request ): bool|\WP_Error {
		$base = self::permission( Capabilities::MANAGE_SESSIONS )();

		if ( true !== $base ) {
			return $base;
		}

		if ( ! current_user_can( 'edit_user', (int) $request['id'] ) ) {
			return self::error( 'rest_forbidden', __( 'Sorry, you are not allowed to manage this user’s sessions.', 'wp-activity-ledger' ), 403 );
		}

		return true;
	}

	/**
	 * Lists the user's active sessions.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error Sessions, or 404 for an unknown user.
	 */
	public function list_sessions( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$user = get_userdata( (int) $request['id'] );

		if ( ! $user instanceof \WP_User ) {
			return self::error( 'wpal_user_not_found', __( 'User not found.', 'wp-activity-ledger' ), 404 );
		}

		$store   = new SessionStore( $user->ID );
		$current = $store->current_verifier();
		$items   = array();

		foreach ( $store->all() as $verifier => $session ) {
			$items[] = array(
				'verifier'     => (string) $verifier,
				'session_hash' => substr( (string) $verifier, 0, 16 ),
				'login'        => gmdate( 'Y-m-d H:i:s', (int) ( $session['login'] ?? 0 ) ),
				'expiration'   => gmdate( 'Y-m-d H:i:s', (int) ( $session['expiration'] ?? 0 ) ),
				'ip'           => (string) ( $session['ip'] ?? '' ),
				'user_agent'   => (string) ( $session['ua'] ?? '' ),
				'current'      => $verifier === $current,
			);
		}

		usort( $items, static fn( array $a, array $b ): int => strcmp( $b['login'], $a['login'] ) );

		return rest_ensure_response( $items );
	}

	/**
	 * Ends one session.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error Result, or 404.
	 */
	public function destroy_one( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$user = get_userdata( (int) $request['id'] );

		if ( ! $user instanceof \WP_User || ! ( new SessionStore( $user->ID ) )->destroy( (string) $request['verifier'] ) ) {
			return self::error( 'wpal_session_not_found', __( 'Session not found.', 'wp-activity-ledger' ), 404 );
		}

		$this->record( $user, 1, false );

		return rest_ensure_response( array( 'destroyed' => 1 ) );
	}

	/**
	 * Ends all of the user's sessions (all but the current one when it is the current user).
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error Result, or 404.
	 */
	public function destroy_all( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$user = get_userdata( (int) $request['id'] );

		if ( ! $user instanceof \WP_User ) {
			return self::error( 'wpal_user_not_found', __( 'User not found.', 'wp-activity-ledger' ), 404 );
		}

		$store = new SessionStore( $user->ID );
		$keep  = $store->current_verifier();
		$count = $store->destroy_all( $keep );

		if ( $count > 0 ) {
			$this->record( $user, $count, '' !== $keep );
		}

		return rest_ensure_response( array( 'destroyed' => $count ) );
	}

	/**
	 * Records ended sessions.
	 *
	 * @param \WP_User $user      Target user.
	 * @param int      $count     Sessions ended.
	 * @param bool     $kept_self Whether the current session was kept.
	 *
	 * @return void
	 */
	private function record( \WP_User $user, int $count, bool $kept_self ): void {
		$this->recorder->record(
			new Event(
				'user.session_destroyed',
				object_type: 'user',
				object_id: (string) $user->ID,
				object_label: $user->user_login,
				context: array(
					'count'        => $count,
					'kept_current' => $kept_self,
				)
			)
		);
	}
}
