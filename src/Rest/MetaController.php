<?php
/**
 * Meta REST controller.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Rest;

use WPActivityLedger\Domain\Category;
use WPActivityLedger\Domain\EventTypeRegistry;
use WPActivityLedger\Domain\Severity;
use WPActivityLedger\Integrations\IntegrationManager;
use WPActivityLedger\Security\Capabilities;
use WPActivityLedger\Support\IpResolver;

/**
 * GET /meta: what the admin app needs to label and filter events (event types, categories,
 * severities, roles, active integrations), the current user's capabilities and UI prefs;
 * PUT /me/prefs: saves the current user's UI prefs.
 */
final class MetaController extends BaseController {

	/**
	 * Creates the controller.
	 *
	 * @param EventTypeRegistry  $types        Event types.
	 * @param IntegrationManager $integrations Integrations.
	 */
	public function __construct(
		private readonly EventTypeRegistry $types,
		private readonly IntegrationManager $integrations
	) {
		$this->rest_base = 'meta';
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/meta',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_meta' ),
				'permission_callback' => self::permission( Capabilities::VIEW ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/me/prefs',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_prefs' ),
				'permission_callback' => self::permission( Capabilities::VIEW ),
			)
		);
	}

	/**
	 * Returns the meta data.
	 *
	 * @return \WP_REST_Response Response.
	 */
	public function get_meta(): \WP_REST_Response {
		return rest_ensure_response(
			array(
				'event_types'   => array_values( array_map( static fn( $type ): array => $type->to_array(), $this->types->all() ) ),
				'categories'    => array_map(
					static fn( Category $c ): array => array(
						'slug'  => $c->value,
						'label' => $c->label(),
						'group' => $c->group(),
					),
					Category::cases()
				),
				'severities'    => array_map(
					static fn( Severity $s ): array => array(
						'value' => $s->value,
						'slug'  => $s->slug(),
						'label' => $s->label(),
					),
					Severity::cases()
				),
				'roles'         => self::roles(),
				'integrations'  => $this->integrations(),
				'supported'     => array_values( $this->integrations->supported() ),
				'caps'          => self::caps(),
				'prefs'         => UiPrefs::get( get_current_user_id() ),
				'proxy_headers' => IpResolver::ALLOWED_HEADERS,
				'multisite'     => is_multisite(),
				'super_admin'   => is_super_admin(),
			)
		);
	}

	/**
	 * Saves the current user's UI prefs (unknown keys are dropped).
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response Saved prefs.
	 */
	public function update_prefs( \WP_REST_Request $request ): \WP_REST_Response {
		$json  = $request->get_json_params(); // Null when the body isn't JSON.
		$input = empty( $json ) ? $request->get_body_params() : $json;

		return rest_ensure_response( UiPrefs::save( get_current_user_id(), $input ) );
	}

	/**
	 * Returns the active integrations.
	 *
	 * @return array<int, array{slug: string, name: string}> Integrations.
	 */
	private function integrations(): array {
		$list = array();

		foreach ( $this->integrations->active() as $slug => $name ) {
			$list[] = array(
				'slug' => $slug,
				'name' => $name,
			);
		}

		return $list;
	}

	/**
	 * Returns the site's roles.
	 *
	 * @return array<int, array{slug: string, name: string}> Roles.
	 */
	private static function roles(): array {
		$roles = array();

		foreach ( wp_roles()->get_names() as $slug => $name ) {
			$roles[] = array(
				'slug' => (string) $slug,
				'name' => translate_user_role( (string) $name ),
			);
		}

		return $roles;
	}

	/**
	 * Returns which plugin capabilities the current user has.
	 *
	 * @return array<string, bool> Short name => granted.
	 */
	private static function caps(): array {
		return array(
			'view'            => current_user_can( Capabilities::VIEW ), // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Plugin capabilities.
			'export'          => current_user_can( Capabilities::EXPORT ), // phpcs:ignore WordPress.WP.Capabilities.Undetermined
			'manage_settings' => current_user_can( Capabilities::MANAGE_SETTINGS ), // phpcs:ignore WordPress.WP.Capabilities.Undetermined
			'delete'          => current_user_can( Capabilities::DELETE ), // phpcs:ignore WordPress.WP.Capabilities.Undetermined
			'manage_sessions' => current_user_can( Capabilities::MANAGE_SESSIONS ), // phpcs:ignore WordPress.WP.Capabilities.Undetermined
		);
	}
}
