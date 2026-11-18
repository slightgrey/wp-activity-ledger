<?php
/**
 * REST route registration.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Rest;

use WPActivityLedger\Database\EventStats;
use WPActivityLedger\Database\UserActivity;
use WPActivityLedger\Loader;
use WPActivityLedger\Plugin;

/**
 * Builds the REST controllers on `rest_api_init` and registers their routes.
 */
final class Routes {

	/**
	 * Creates the registrar.
	 *
	 * @param Plugin $plugin Plugin services.
	 */
	public function __construct( private readonly Plugin $plugin ) {
	}

	/**
	 * Queues the hook.
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'rest_api_init', array( $this, 'register_routes' ), 10, 0 );
	}

	/**
	 * Registers every controller's routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		foreach ( $this->controllers() as $controller ) {
			$controller->register_routes();
		}
	}

	/**
	 * Returns the controllers.
	 *
	 * @return array<int, BaseController> Controllers.
	 */
	private function controllers(): array {
		return array(
			new EventsController( $this->plugin->repository() ),
			new MetaController( $this->plugin->types(), $this->plugin->integrations() ),
			new StatsController( new EventStats() ),
			new SettingsController( $this->plugin->settings() ),
			new UsersController( new UserActivity() ),
			new SessionsController( $this->plugin->recorder() ),
			new NetworkSettingsController(),
			new PurgeController( $this->plugin->repository(), $this->plugin->recorder() ),
			new AlertsController( $this->plugin->alert_channels(), $this->plugin->alert_rules(), $this->plugin->deliverer() ),
		);
	}
}
