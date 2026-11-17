<?php
/**
 * Plugin activation.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Lifecycle;

use WPActivityLedger\Database\Installer;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Plugin;
use WPActivityLedger\Security\Capabilities;
use WPActivityLedger\Settings\Settings;

/**
 * Prepares a site when the plugin is activated: tables, default settings, capabilities.
 */
final class Activator {

	/**
	 * Minimum PHP version.
	 */
	public const MIN_PHP = '8.1';

	/**
	 * Minimum WordPress version.
	 */
	public const MIN_WP = '6.5';

	/**
	 * Activation hook callback.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 *
	 * @return void
	 */
	public static function activate( bool $network_wide = false ): void {
		unset( $network_wide ); // Network-wide activation is added with multisite support.

		self::check_requirements();
		self::activate_site();
	}

	/**
	 * Sets up the current site.
	 *
	 * @return void
	 */
	public static function activate_site(): void {
		Installer::install();
		add_option( Settings::OPTION, Settings::defaults(), '', true );

		$settings = new Settings();

		Capabilities::grant_defaults();
		Capabilities::sync_role_grants( (array) $settings->get( 'view_roles' ), (array) $settings->get( 'export_roles' ) );

		Plugin::instance()->recorder()->record_now(
			new Event( 'system.activated', object_type: 'plugin', object_id: plugin_basename( WPAL_FILE ), object_label: 'WP Activity Ledger', context: array( 'version' => WPAL_VERSION ) )
		);
	}

	/**
	 * Stops activation with a readable message when PHP or WordPress is too old.
	 *
	 * @return void
	 */
	private static function check_requirements(): void {
		global $wp_version;

		if ( version_compare( PHP_VERSION, self::MIN_PHP, '>=' ) && version_compare( (string) $wp_version, self::MIN_WP, '>=' ) ) {
			return;
		}

		deactivate_plugins( plugin_basename( WPAL_FILE ) );

		wp_die(
			esc_html(
				sprintf(
					/* translators: 1: required PHP version, 2: required WordPress version. */
					__( 'WP Activity Ledger needs PHP %1$s and WordPress %2$s or newer.', 'wp-activity-ledger' ),
					self::MIN_PHP,
					self::MIN_WP
				)
			),
			esc_html__( 'Plugin activation failed', 'wp-activity-ledger' ),
			array( 'back_link' => true )
		);
	}
}
