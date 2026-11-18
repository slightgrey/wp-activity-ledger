<?php
/**
 * Multisite lifecycle.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Lifecycle;

use WPActivityLedger\Loader;

/**
 * Keeps every site of a network set up while the plugin is network-active: installs it on
 * all sites at network activation (100 at a time), on each new site, and adds a deleted
 * site's tables to the ones WordPress drops.
 */
final class Network {

	/**
	 * Sites handled per query during network activation.
	 */
	private const BATCH = 100;

	/**
	 * Queues the hooks (multisite only).
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public static function register( Loader $loader ): void {
		if ( ! is_multisite() ) {
			return;
		}

		$loader->add_action( 'wp_initialize_site', array( self::class, 'on_new_site' ), 200, 1 );
		$loader->add_filter( 'wpmu_drop_tables', array( self::class, 'drop_tables' ), 10, 2 );
	}

	/**
	 * Sets up the plugin on every site of the network.
	 *
	 * @return int Sites set up.
	 */
	public static function activate_all(): int {
		$count  = 0;
		$offset = 0;

		do {
			$ids = get_sites(
				array(
					'fields'   => 'ids',
					'number'   => self::BATCH,
					'offset'   => $offset,
					'archived' => 0,
					'deleted'  => 0,
				)
			);

			foreach ( $ids as $site_id ) {
				self::activate_site( (int) $site_id );
				++$count;
			}

			$offset += self::BATCH;
			$full    = count( $ids ) === self::BATCH;
		} while ( $full );

		return $count;
	}

	/**
	 * Sets up a new site when the plugin is network-active.
	 *
	 * @param mixed $site New site.
	 *
	 * @return void
	 */
	public static function on_new_site( mixed $site = null ): void {
		if ( $site instanceof \WP_Site && self::network_active() ) {
			self::activate_site( (int) $site->blog_id );
		}
	}

	/**
	 * Adds the plugin's tables of a deleted site to the tables WordPress drops.
	 *
	 * @param mixed $tables  Table names.
	 * @param mixed $site_id Site ID.
	 *
	 * @return array<int, string> Table names.
	 */
	public static function drop_tables( $tables, $site_id = 0 ): array {
		global $wpdb;

		$prefix = $wpdb->get_blog_prefix( (int) $site_id );

		return array_values( array_unique( array_merge( (array) $tables, array( $prefix . 'wpal_events', $prefix . 'wpal_event_changes' ) ) ) );
	}

	/**
	 * Tells whether the plugin is active for the whole network.
	 *
	 * @return bool True when network-active.
	 */
	public static function network_active(): bool {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active_for_network( plugin_basename( WPAL_FILE ) );
	}

	/**
	 * Sets up one site.
	 *
	 * @param int $site_id Site ID.
	 *
	 * @return void
	 */
	private static function activate_site( int $site_id ): void {
		switch_to_blog( $site_id );

		try {
			Activator::activate_site();
		} finally {
			restore_current_blog();
		}
	}
}
