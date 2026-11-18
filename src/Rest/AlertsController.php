<?php
/**
 * Alerts REST controller.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Rest;

use WPActivityLedger\Alerts\ChannelStore;
use WPActivityLedger\Alerts\Deliverer;
use WPActivityLedger\Alerts\ListStore;
use WPActivityLedger\Alerts\RuleStore;
use WPActivityLedger\Security\Capabilities;

/**
 * CRUD for alert channels and rules under /alerts/channels and /alerts/rules, plus
 * POST /alerts/channels/{id}/test. Channel credentials are masked in every response.
 * Changes are recorded by the self sensor, which watches the two options.
 */
final class AlertsController extends BaseController {

	/**
	 * Creates the controller.
	 *
	 * @param ChannelStore $channels  Channels.
	 * @param RuleStore    $rules     Rules.
	 * @param Deliverer    $deliverer Sends test alerts.
	 */
	public function __construct(
		private readonly ChannelStore $channels,
		private readonly RuleStore $rules,
		private readonly Deliverer $deliverer
	) {
		$this->rest_base = 'alerts';
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		foreach ( array( 'channels', 'rules' ) as $kind ) {
			register_rest_route(
				$this->namespace,
				"/alerts/$kind",
				array(
					$this->route( \WP_REST_Server::READABLE, $kind, 'list_items' ),
					$this->route( \WP_REST_Server::CREATABLE, $kind, 'create_entry' ),
				)
			);

			register_rest_route(
				$this->namespace,
				"/alerts/$kind/(?P<id>[a-f0-9-]{36})",
				array(
					$this->route( \WP_REST_Server::READABLE, $kind, 'read_entry' ),
					$this->route( \WP_REST_Server::EDITABLE, $kind, 'update_entry' ),
					$this->route( \WP_REST_Server::DELETABLE, $kind, 'delete_entry' ),
				)
			);
		}

		register_rest_route(
			$this->namespace,
			'/alerts/channels/(?P<id>[a-f0-9-]{36})/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_channel' ),
				'permission_callback' => self::permission( Capabilities::MANAGE_SETTINGS ),
			)
		);
	}

	/**
	 * Builds one endpoint definition.
	 *
	 * @param string $methods HTTP methods.
	 * @param string $kind    "channels" or "rules".
	 * @param string $handler Handler method.
	 *
	 * @return array<string, mixed> Endpoint.
	 */
	private function route( string $methods, string $kind, string $handler ): array {
		return array(
			'methods'             => $methods,
			'callback'            => fn( \WP_REST_Request $request ) => $this->$handler( $kind, $request ),
			'permission_callback' => self::permission( Capabilities::MANAGE_SETTINGS ),
		);
	}

	/**
	 * Lists channels or rules.
	 *
	 * @param string $kind Kind.
	 *
	 * @return \WP_REST_Response Items.
	 */
	public function list_items( string $kind ): \WP_REST_Response {
		return rest_ensure_response( array_map( fn( array $item ): array => $this->present( $kind, $item ), $this->store( $kind )->all() ) );
	}

	/**
	 * Returns one channel or rule.
	 *
	 * @param string           $kind    Kind.
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error Item, or 404.
	 */
	public function read_entry( string $kind, \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$item = $this->store( $kind )->get( (string) $request['id'] );

		return null === $item ? self::not_found() : rest_ensure_response( $this->present( $kind, $item ) );
	}

	/**
	 * Creates a channel or rule.
	 *
	 * @param string           $kind    Kind.
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error Created item (201), or 400.
	 */
	public function create_entry( string $kind, \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		[ $item, $errors ] = $this->store( $kind )->create( self::input( $request ) );

		if ( null === $item ) {
			return self::invalid( $errors );
		}

		$response = rest_ensure_response( $this->present( $kind, $item ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Updates a channel or rule.
	 *
	 * @param string           $kind    Kind.
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error Updated item, 400 or 404.
	 */
	public function update_entry( string $kind, \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$store = $this->store( $kind );

		if ( null === $store->get( (string) $request['id'] ) ) {
			return self::not_found();
		}

		[ $item, $errors ] = $store->update( (string) $request['id'], self::input( $request ) );

		return null === $item ? self::invalid( $errors ) : rest_ensure_response( $this->present( $kind, $item ) );
	}

	/**
	 * Deletes a channel (removing it from rules) or a rule.
	 *
	 * @param string           $kind    Kind.
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error Result, or 404.
	 */
	public function delete_entry( string $kind, \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (string) $request['id'];

		if ( ! $this->store( $kind )->delete( $id ) ) {
			return self::not_found();
		}

		if ( 'channels' === $kind ) {
			$this->rules->forget_channel( $id );
		}

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Sends a sample alert through a channel right away.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error Result ({ ok, error }), or 404.
	 */
	public function test_channel( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$channel = $this->channels->get( (string) $request['id'] );

		if ( null === $channel ) {
			return self::not_found();
		}

		$error = $this->deliverer->send( $channel, $this->deliverer->sample_message() );

		return rest_ensure_response(
			array(
				'ok'    => null === $error,
				'error' => $error,
			)
		);
	}

	/**
	 * Returns the store of a kind.
	 *
	 * @param string $kind Kind.
	 *
	 * @return ListStore Store.
	 */
	private function store( string $kind ): ListStore {
		return 'channels' === $kind ? $this->channels : $this->rules;
	}

	/**
	 * Prepares an item for a response (channel credentials masked).
	 *
	 * @param string               $kind Kind.
	 * @param array<string, mixed> $item Item.
	 *
	 * @return array<string, mixed> Item.
	 */
	private function present( string $kind, array $item ): array {
		return 'channels' === $kind ? ChannelStore::masked( $item ) : $item;
	}

	/**
	 * Returns the submitted fields (JSON or form body).
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return array<string, mixed> Fields.
	 */
	private static function input( \WP_REST_Request $request ): array {
		$json = $request->get_json_params(); // Null when the body isn't JSON.

		return empty( $json ) ? $request->get_body_params() : $json;
	}

	/**
	 * Returns a 404 error.
	 *
	 * @return \WP_Error Error.
	 */
	private static function not_found(): \WP_Error {
		return self::error( 'wpal_alert_not_found', __( 'Not found.', 'wp-activity-ledger' ), 404 );
	}

	/**
	 * Returns a 400 error with field messages.
	 *
	 * @param array<string, string> $errors Errors by field.
	 *
	 * @return \WP_Error Error.
	 */
	private static function invalid( array $errors ): \WP_Error {
		return self::error( 'wpal_invalid_alert', __( 'Some fields are not valid.', 'wp-activity-ledger' ), 400, array( 'errors' => $errors ) );
	}
}
