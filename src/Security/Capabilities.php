<?php
/**
 * Custom capabilities.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Security;

use WPActivityLedger\Loader;

/**
 * Defines the plugin's capabilities, grants them to roles and enforces the multisite
 * network lock.
 */
final class Capabilities {

	public const VIEW            = 'wpal_view_logs';
	public const EXPORT          = 'wpal_export_logs';
	public const MANAGE_SETTINGS = 'wpal_manage_settings';
	public const DELETE          = 'wpal_delete_logs';
	public const MANAGE_SESSIONS = 'wpal_manage_sessions';

	/**
	 * Every capability.
	 */
	public const ALL = array( self::VIEW, self::EXPORT, self::MANAGE_SETTINGS, self::DELETE, self::MANAGE_SESSIONS );

	/**
	 * Capabilities a network lock takes away from site administrators.
	 */
	public const LOCKABLE = array( self::MANAGE_SETTINGS, self::DELETE );

	/**
	 * Site option holding network-wide settings (multisite).
	 */
	public const NETWORK_OPTION = 'wpal_network_settings';

	/**
	 * Registers the capability filter.
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public static function register( Loader $loader ): void {
		$loader->add_filter( 'map_meta_cap', array( self::class, 'apply_network_lock' ), 10, 2 );
	}

	/**
	 * Gives the administrator role every capability.
	 *
	 * @return void
	 */
	public static function grant_defaults(): void {
		$admin = get_role( 'administrator' );

		if ( null === $admin ) {
			return;
		}

		foreach ( self::ALL as $cap ) {
			$admin->add_cap( $cap );
		}
	}

	/**
	 * Grants view/export to the chosen roles and removes them from every other role.
	 *
	 * The administrator role is never changed here.
	 *
	 * @param array<int, string> $view_roles   Roles that may view logs.
	 * @param array<int, string> $export_roles Roles that may export logs.
	 *
	 * @return void
	 */
	public static function sync_role_grants( array $view_roles, array $export_roles ): void {
		foreach ( wp_roles()->role_objects as $slug => $role ) {
			if ( 'administrator' === $slug ) {
				continue;
			}

			self::set_cap( $role, self::VIEW, in_array( $slug, $view_roles, true ) );
			self::set_cap( $role, self::EXPORT, in_array( $slug, $export_roles, true ) );
		}
	}

	/**
	 * Removes every plugin capability from every role (uninstall).
	 *
	 * @return void
	 */
	public static function remove_all(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::ALL as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Denies settings and purge to everyone but super admins while the network lock is on.
	 *
	 * @param array<int, string> $caps Primitive capabilities required.
	 * @param string             $cap  Capability being checked.
	 *
	 * @return array<int, string> Required capabilities.
	 */
	public static function apply_network_lock( array $caps, string $cap ): array {
		if ( ! in_array( $cap, self::LOCKABLE, true ) || ! is_multisite() || ! self::network_locked() ) {
			return $caps;
		}

		return array( 'manage_network_options' );
	}

	/**
	 * Tells whether the network admin locked the settings of every site.
	 *
	 * @return bool True when locked.
	 */
	public static function network_locked(): bool {
		$network = get_site_option( self::NETWORK_OPTION, array() );

		return is_array( $network ) && ! empty( $network['lock'] );
	}

	/**
	 * Adds or removes one capability, only when it differs (each change writes the roles option).
	 *
	 * @param \WP_Role $role Role.
	 * @param string   $cap  Capability.
	 * @param bool     $has  Whether the role should have it.
	 *
	 * @return void
	 */
	private static function set_cap( \WP_Role $role, string $cap, bool $has ): void {
		if ( $has === $role->has_cap( $cap ) ) {
			return;
		}

		if ( $has ) {
			$role->add_cap( $cap );
			return;
		}

		$role->remove_cap( $cap );
	}
}
