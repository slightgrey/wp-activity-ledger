<?php
/**
 * Contract for activity sensors.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Sensors;

use WPActivityLedger\Loader;

/**
 * A sensor listens to WordPress hooks for one area (users, posts, plugins…) and hands
 * Event objects to the Recorder. Sensors never write to the database themselves.
 */
interface SensorInterface {

	/**
	 * Queues the sensor's hooks on the loader.
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public function register( Loader $loader ): void;
}
