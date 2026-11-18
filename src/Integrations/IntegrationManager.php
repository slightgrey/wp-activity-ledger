<?php
/**
 * Loads the integrations whose plugins are active.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Integrations;

use WPActivityLedger\Domain\EventTypeRegistry;
use WPActivityLedger\Loader;

/**
 * Registers the available integrations and their event types.
 */
final class IntegrationManager {

	/**
	 * Integrations that were available and registered, keyed by slug.
	 *
	 * @var array<string, IntegrationInterface>
	 */
	private array $active = array();

	/**
	 * Creates the manager.
	 *
	 * @param array<int, IntegrationInterface> $integrations Built-in integrations.
	 */
	public function __construct( private readonly array $integrations ) {
	}

	/**
	 * Registers every available integration's hooks and event types.
	 *
	 * @param Loader            $loader Hook loader.
	 * @param EventTypeRegistry $types  Event type registry.
	 *
	 * @return void
	 */
	public function load( Loader $loader, EventTypeRegistry $types ): void {
		foreach ( $this->candidates() as $integration ) {
			if ( isset( $this->active[ $integration->slug() ] ) || ! $integration->is_available() ) {
				continue;
			}

			$integration->register( $loader );
			$this->active[ $integration->slug() ] = $integration;
			$types->add_provider(
				static function ( EventTypeRegistry $registry ) use ( $integration ): void {
					foreach ( $integration->event_types() as $type ) {
						$registry->register( $type );
					}
				}
			);
		}
	}

	/**
	 * Returns the active integrations' names, keyed by slug (for the admin app).
	 *
	 * @return array<string, string> Slug => name.
	 */
	public function active(): array {
		return array_map( static fn( IntegrationInterface $i ): string => $i->name(), $this->active );
	}

	/**
	 * Returns the names of every known integration, active or not, keyed by slug.
	 *
	 * @return array<string, string> Slug => name.
	 */
	public function supported(): array {
		$names = array();

		foreach ( $this->candidates() as $integration ) {
			$names[ $integration->slug() ] = $integration->name();
		}

		return $names;
	}

	/**
	 * Returns an active integration by slug.
	 *
	 * @param string $slug Slug.
	 *
	 * @return IntegrationInterface|null The integration, or null when not active.
	 */
	public function get( string $slug ): ?IntegrationInterface {
		return $this->active[ $slug ] ?? null;
	}

	/**
	 * Returns the built-in integrations plus those added with the wpal_integrations filter.
	 *
	 * @return array<int, IntegrationInterface> Integrations.
	 */
	private function candidates(): array {
		/**
		 * Filters the integrations. Add your own IntegrationInterface implementation here.
		 *
		 * @param array<int, IntegrationInterface> $integrations Integrations.
		 */
		return self::only_integrations( apply_filters( 'wpal_integrations', $this->integrations ), $this->integrations );
	}

	/**
	 * Keeps only IntegrationInterface values from a filter result.
	 *
	 * @param mixed                            $value    Filter result.
	 * @param array<int, IntegrationInterface> $fallback Used when the result is not an array.
	 *
	 * @return array<int, IntegrationInterface> Integrations.
	 */
	private static function only_integrations( mixed $value, array $fallback ): array {
		if ( ! is_array( $value ) ) {
			return $fallback;
		}

		return array_values( array_filter( $value, static fn( $i ): bool => $i instanceof IntegrationInterface ) );
	}
}
