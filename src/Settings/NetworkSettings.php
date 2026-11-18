<?php
/**
 * Network-wide settings.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Settings;

use WPActivityLedger\Security\Capabilities;

/**
 * Network settings (site option "wpal_network_settings"):
 * - lock: site administrators can view but not change settings, alerts or purge logs;
 * - defaults: plugin settings given to sites created from now on (null: plugin defaults).
 */
final class NetworkSettings {

	/**
	 * Returns the network settings.
	 *
	 * @return array{lock: bool, defaults: array<string, mixed>|null} Settings.
	 */
	public static function get(): array {
		$stored = get_site_option( Capabilities::NETWORK_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'lock'     => ! empty( $stored['lock'] ),
			'defaults' => is_array( $stored['defaults'] ?? null ) ? $stored['defaults'] : null,
		);
	}

	/**
	 * Saves submitted network settings; "defaults" is validated like site settings.
	 *
	 * @param array<string, mixed> $input Submitted fields.
	 *
	 * @return array{0: array{lock: bool, defaults: array<string, mixed>|null}, 1: array<string, string>} Saved settings and errors.
	 */
	public static function update( array $input ): array {
		$settings = self::get();

		if ( array_key_exists( 'lock', $input ) ) {
			$settings['lock'] = (bool) $input['lock'];
		}

		if ( array_key_exists( 'defaults', $input ) ) {
			if ( ! is_array( $input['defaults'] ) ) {
				$settings['defaults'] = null;
			} else {
				[ $defaults, $errors ] = SettingsValidator::validate( array_intersect_key( $input['defaults'], Settings::defaults() ), Settings::defaults() );

				if ( array() !== $errors ) {
					return array( self::get(), $errors );
				}

				$settings['defaults'] = $defaults;
			}
		}

		update_site_option( Capabilities::NETWORK_OPTION, $settings );

		return array( $settings, array() );
	}

	/**
	 * Gives a new site the network default settings (before activation adds its own).
	 *
	 * @return void
	 */
	public static function seed_site(): void {
		$defaults = self::get()['defaults'];

		if ( null !== $defaults ) {
			add_option( Settings::OPTION, array_merge( Settings::defaults(), $defaults ), '', true );
		}
	}
}
