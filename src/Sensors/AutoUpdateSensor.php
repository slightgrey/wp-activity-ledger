<?php
/**
 * Auto-update settings sensor.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Sensors;

use WPActivityLedger\Diff\Differ;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Loader;
use WPActivityLedger\Recording\Recorder;

/**
 * Records auto-updates being switched on or off for plugins and themes (one event per
 * item), and changes to WordPress core's auto-update settings.
 *
 * These are site options, always written with update_site_option(). Its hooks fire on
 * single sites too (after the regular option hooks), so only they are used, otherwise
 * every change would be recorded twice. The options are claimed so the options sensor
 * doesn't record them again.
 */
final class AutoUpdateSensor implements SensorInterface {

	/**
	 * Item lists: option => type.
	 */
	private const LISTS = array(
		'auto_update_plugins' => 'plugin',
		'auto_update_themes'  => 'theme',
	);

	/**
	 * Core auto-update options.
	 */
	private const CORE = array( 'auto_update_core_major', 'auto_update_core_minor', 'auto_update_core_dev' );

	/**
	 * Creates the sensor.
	 *
	 * @param Recorder $recorder Recorder.
	 * @param Differ   $differ   Differ.
	 */
	public function __construct(
		private readonly Recorder $recorder,
		private readonly Differ $differ
	) {
	}

	/**
	 * Queues the hooks.
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public function register( Loader $loader ): void {
		foreach ( array_merge( array_keys( self::LISTS ), self::CORE ) as $option ) {
			$loader->add_action( "update_site_option_$option", array( $this, 'on_site_option_updated' ), 10, 3 );
			$loader->add_action( "add_site_option_$option", array( $this, 'on_option_added' ), 10, 2 );
		}

		$loader->add_filter( 'wpal_option_is_claimed', array( $this, 'claims_option' ), 10, 2 );
	}

	/**
	 * Handles update_site_option_{option} (option, new value, old value).
	 *
	 * @param string $option    Option name.
	 * @param mixed  $value     New value.
	 * @param mixed  $old_value Old value.
	 *
	 * @return void
	 */
	public function on_site_option_updated( $option, $value, $old_value ): void {
		$this->handle( (string) $option, $old_value, $value );
	}

	/**
	 * Handles add_site_option_{option} (option, value).
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 *
	 * @return void
	 */
	public function on_option_added( $option, $value ): void {
		$this->handle( (string) $option, null, $value );
	}

	/**
	 * Claims the auto-update options.
	 *
	 * @param mixed  $claimed Whether already claimed.
	 * @param string $option  Option name.
	 *
	 * @return bool True when claimed.
	 */
	public function claims_option( $claimed, $option ): bool {
		return (bool) $claimed || isset( self::LISTS[ (string) $option ] ) || in_array( (string) $option, self::CORE, true );
	}

	/**
	 * Records the change of one option.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Old value.
	 * @param mixed  $value     New value.
	 *
	 * @return void
	 */
	private function handle( string $option, mixed $old_value, mixed $value ): void {
		if ( isset( self::LISTS[ $option ] ) ) {
			$this->record_list_change( self::LISTS[ $option ], (array) $old_value, (array) $value );
			return;
		}

		$change = $this->differ->compare( $option, $old_value, $value );

		if ( null !== $change ) {
			$this->recorder->record( new Event( 'core.auto_update_changed', object_type: 'core', object_id: 'WordPress', object_label: 'WordPress', changes: array( $change ) ) );
		}
	}

	/**
	 * Records one event per plugin/theme whose auto-updates were switched on or off.
	 *
	 * @param string             $type   "plugin" or "theme".
	 * @param array<int, string> $before Items before.
	 * @param array<int, string> $after  Items after.
	 *
	 * @return void
	 */
	private function record_list_change( string $type, array $before, array $after ): void {
		$changes = array(
			'on'  => array_diff( $after, $before ),
			'off' => array_diff( $before, $after ),
		);

		foreach ( $changes as $state => $items ) {
			foreach ( $items as $item ) {
				$label = 'plugin' === $type ? PluginSensor::details( (string) $item )['name'] : (string) wp_get_theme( (string) $item )->get( 'Name' );

				$this->recorder->record(
					new Event(
						"$type.auto_update_changed",
						object_type: $type,
						object_id: (string) $item,
						object_label: '' === $label ? (string) $item : $label,
						context: array( 'state' => $state )
					)
				);
			}
		}
	}
}
