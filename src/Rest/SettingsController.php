<?php
/**
 * Settings REST controller.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Rest;

use WPActivityLedger\Security\Capabilities;
use WPActivityLedger\Settings\Settings;

/**
 * GET /settings and POST /settings (partial updates; nothing is saved when a field is invalid).
 *
 * The change itself is recorded by the self sensor, which watches the settings option.
 */
final class SettingsController extends BaseController {

	/**
	 * Creates the controller.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( private readonly Settings $settings ) {
		$this->rest_base = 'settings';
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => self::permission( Capabilities::MANAGE_SETTINGS ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => self::permission( Capabilities::MANAGE_SETTINGS ),
				),
			)
		);
	}

	/**
	 * Returns the settings.
	 *
	 * @return \WP_REST_Response Settings.
	 */
	public function get_settings(): \WP_REST_Response {
		return rest_ensure_response( $this->settings->all() );
	}

	/**
	 * Validates and saves submitted settings, then syncs the role grants.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error Saved settings, or 400 with field errors.
	 */
	public function update_settings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$json  = $request->get_json_params(); // Null when the body isn't JSON.
		$input = empty( $json ) ? $request->get_body_params() : $json;
		$input = array_intersect_key( $input, Settings::defaults() );

		$errors = $this->settings->update( $input );

		if ( array() !== $errors ) {
			return self::error( 'wpal_invalid_settings', __( 'Some settings are not valid.', 'wp-activity-ledger' ), 400, array( 'errors' => $errors ) );
		}

		Capabilities::sync_role_grants( (array) $this->settings->get( 'view_roles' ), (array) $this->settings->get( 'export_roles' ) );

		return rest_ensure_response( $this->settings->all() );
	}
}
