<?php
/**
 * Plugin deactivation.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Lifecycle;

/**
 * Runs when the plugin is deactivated. Data, settings and capabilities are kept;
 * logging the deactivation and clearing scheduled tasks are added in group 9.
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
		unset( $network_wide );
	}
}
