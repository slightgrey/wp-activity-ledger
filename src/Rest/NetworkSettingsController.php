<?php
/**
 * Network settings REST controller.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Rest;

use WPActivityLedger\Settings\NetworkSettings;

/**
 * GET/POST /network-settings (multisite, network administrators): the settings lock and
 * the defaults for new sites. Also GET /sites, the site list for the network site switcher.
 */
final class NetworkSettingsController extends BaseController {

	/**
	 * Most sites listed in the switcher.
	 */
	private const MAX_SITES = 500;

	/**
	 * Registers the routes (multisite only).
	 *
	 * @return void
	 */
	public function register_routes(): void {
		if ( ! is_multisite() ) {
			return;
		}

		register_rest_route(
			$this->namespace,
			'/network-settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn() => rest_ensure_response( NetworkSettings::get() ),
					'permission_callback' => self::permission( 'manage_network_options' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => self::permission( 'manage_network_options' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/sites',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'sites' ),
				'permission_callback' => self::permission( 'manage_network_options' ),
			)
		);
	}

	/**
	 * Saves the network settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error Saved settings, or 400.
	 */
	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$json               = $request->get_json_params(); // Null when the body isn't JSON.
		[ $saved, $errors ] = NetworkSettings::update( empty( $json ) ? $request->get_body_params() : $json );

		if ( array() !== $errors ) {
			return self::error( 'wpal_invalid_settings', __( 'Some settings are not valid.', 'wp-activity-ledger' ), 400, array( 'errors' => $errors ) );
		}

		return rest_ensure_response( $saved );
	}

	/**
	 * Lists the network's sites (ID, name, URL), main site first.
	 *
	 * @return \WP_REST_Response Sites.
	 */
	public function sites(): \WP_REST_Response {
		$sites = array();

		foreach ( get_sites( array( 'number' => self::MAX_SITES ) ) as $site ) {
			$sites[] = array(
				'id'   => (int) $site->blog_id,
				'name' => (string) get_blog_option( (int) $site->blog_id, 'blogname' ),
				'url'  => $site->domain . $site->path,
				'main' => is_main_site( (int) $site->blog_id ),
			);
		}

		usort(
			$sites,
			static function ( array $a, array $b ): int {
				$main = (int) $b['main'] <=> (int) $a['main'];

				return 0 !== $main ? $main : $a['id'] <=> $b['id'];
			}
		);

		return rest_ensure_response( $sites );
	}
}
