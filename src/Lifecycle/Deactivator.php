<?php
/**
 * Plugin deactivation.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Lifecycle;

use WPActivityLedger\Domain\Event;
use WPActivityLedger\Plugin;

/**
 * Runs when the plugin is deactivated. The deactivation is logged first (critical, stored
 * immediately), then scheduled tasks are cleared. Data, settings and capabilities are kept.
 */
final class Deactivator {

	/**
	 * Deactivation hook callback.
	 *
	 * @param bool $network_wide Whether the plugin is being network-deactivated.
	 *
	 * @return void
	 */
	public static function deactivate( bool $network_wide = false ): void {
		self::log( $network_wide );
		self::clear_scheduled();
	}

	/**
	 * Removes every scheduled wpal_* cron event and Action Scheduler action of the plugin.
	 *
	 * @return void
	 */
	public static function clear_scheduled(): void {
		foreach ( self::cron_hooks() as $hook ) {
			wp_unschedule_hook( $hook ); // Unlike wp_clear_scheduled_hook(), removes events with any arguments.
		}

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'wpal' );
		}
	}

	/**
	 * Returns the distinct wpal_* hooks in the cron schedule.
	 *
	 * @return array<int, string> Hook names.
	 */
	private static function cron_hooks(): array {
		$hooks = array();

		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( array_keys( (array) $events ) as $hook ) {
				if ( str_starts_with( (string) $hook, 'wpal_' ) ) {
					$hooks[ (string) $hook ] = (string) $hook;
				}
			}
		}

		return array_values( $hooks );
	}

	/**
	 * Stores the deactivation event right away, while the plugin still runs.
	 *
	 * @param bool $network_wide Whether network-wide.
	 *
	 * @return void
	 */
	private static function log( bool $network_wide ): void {
		Plugin::instance()->recorder()->record_now(
			new Event(
				'system.deactivated',
				object_type: 'plugin',
				object_id: plugin_basename( WPAL_FILE ),
				object_label: 'WP Activity Ledger',
				context: array(
					'network_wide' => $network_wide,
					'version'      => WPAL_VERSION,
				)
			)
		);
	}
}
