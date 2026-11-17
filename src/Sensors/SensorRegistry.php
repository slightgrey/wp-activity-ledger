<?php
/**
 * Collects and registers the sensors.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Sensors;

use WPActivityLedger\Loader;

/**
 * Holds the active sensors and registers their hooks.
 */
final class SensorRegistry {

	/**
	 * Creates the registry.
	 *
	 * @param array<string, SensorInterface> $sensors Core sensors keyed by slug.
	 */
	public function __construct( private readonly array $sensors ) {
	}

	/**
	 * Returns the sensors after the wpal_sensors filter (non-sensors are dropped).
	 *
	 * @return array<string, SensorInterface> Sensors keyed by slug.
	 */
	public function sensors(): array {
		/**
		 * Filters the active sensors. Remove a key to switch a sensor off, or add your own.
		 *
		 * @param array<string, SensorInterface> $sensors Sensors keyed by slug.
		 */
		return self::only_sensors( apply_filters( 'wpal_sensors', $this->sensors ) );
	}

	/**
	 * Queues the hooks of every active sensor.
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public function register( Loader $loader ): void {
		foreach ( $this->sensors() as $sensor ) {
			$sensor->register( $loader );
		}
	}

	/**
	 * Keeps only SensorInterface values from a filter result.
	 *
	 * @param mixed $value Filter result.
	 *
	 * @return array<string, SensorInterface> Sensors.
	 */
	private static function only_sensors( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_filter( $value, static fn( $sensor ): bool => $sensor instanceof SensorInterface );
	}
}
