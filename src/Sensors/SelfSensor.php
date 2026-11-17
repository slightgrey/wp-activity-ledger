<?php
/**
 * Activity Ledger's own changes (tamper protection).
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Sensors;

use WPActivityLedger\Diff\Differ;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Loader;
use WPActivityLedger\Recording\Recorder;
use WPActivityLedger\Settings\Settings;

/**
 * Records changes to Activity Ledger's settings, alert rules and alert channels.
 *
 * System events are always logged (see LogPolicy), so these can't be hidden by the
 * settings they change. Alert channel configs hold secrets (webhook URLs, signing
 * secrets), so only channel names and types are compared.
 */
final class SelfSensor implements SensorInterface {

	/**
	 * Alert rules option.
	 */
	public const RULES_OPTION = 'wpal_alert_rules';

	/**
	 * Alert channels option.
	 */
	public const CHANNELS_OPTION = 'wpal_alert_channels';

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
		$loader->add_action( 'update_option_' . Settings::OPTION, array( $this, 'on_settings_changed' ), 10, 2 );
		$loader->add_action( 'update_option_' . self::RULES_OPTION, array( $this, 'on_rules_changed' ), 10, 2 );
		$loader->add_action( 'add_option_' . self::RULES_OPTION, array( $this, 'on_rules_added' ), 10, 2 );
		$loader->add_action( 'update_option_' . self::CHANNELS_OPTION, array( $this, 'on_channels_changed' ), 10, 2 );
		$loader->add_action( 'add_option_' . self::CHANNELS_OPTION, array( $this, 'on_channels_added' ), 10, 2 );
	}

	/**
	 * Records a settings change with its diff.
	 *
	 * @param mixed $old_value Old settings.
	 * @param mixed $value     New settings.
	 *
	 * @return void
	 */
	public function on_settings_changed( $old_value, $value ): void {
		$changes = $this->differ->diff( (array) $old_value, (array) $value );

		if ( array() !== $changes ) {
			$this->recorder->record( new Event( 'system.settings_changed', object_type: 'settings', object_id: Settings::OPTION, object_label: 'Activity Ledger', changes: $changes ) );
		}
	}

	/**
	 * Records an alert rules change (rule names and enabled state are compared).
	 *
	 * @param mixed $old_value Old rules.
	 * @param mixed $value     New rules.
	 *
	 * @return void
	 */
	public function on_rules_changed( $old_value, $value ): void {
		$this->record_list( 'system.alert_rules_changed', self::summaries( $old_value, array( 'name', 'enabled' ) ), self::summaries( $value, array( 'name', 'enabled' ) ) );
	}

	/**
	 * Records the first alert rules being saved.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Rules.
	 *
	 * @return void
	 */
	public function on_rules_added( $option, $value ): void {
		unset( $option );
		$this->on_rules_changed( array(), $value );
	}

	/**
	 * Records an alert channels change (names and types only; configs hold secrets).
	 *
	 * @param mixed $old_value Old channels.
	 * @param mixed $value     New channels.
	 *
	 * @return void
	 */
	public function on_channels_changed( $old_value, $value ): void {
		$this->record_list( 'system.alert_channels_changed', self::summaries( $old_value, array( 'name', 'type' ) ), self::summaries( $value, array( 'name', 'type' ) ) );
	}

	/**
	 * Records the first alert channels being saved.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Channels.
	 *
	 * @return void
	 */
	public function on_channels_added( $option, $value ): void {
		unset( $option );
		$this->on_channels_changed( array(), $value );
	}

	/**
	 * Records a rules/channels change when the summaries differ.
	 *
	 * @param string                              $type   Event type.
	 * @param array<string, array<string, mixed>> $before Summaries by ID before.
	 * @param array<string, array<string, mixed>> $after  Summaries by ID after.
	 *
	 * @return void
	 */
	private function record_list( string $type, array $before, array $after ): void {
		$changes = $this->differ->diff( $before, $after );
		$names   = array_filter( array_map( static fn( array $s ): string => (string) ( $s['name'] ?? '' ), $after + $before ) );

		if ( array() !== $changes ) {
			$this->recorder->record( new Event( $type, object_type: 'alerts', object_label: implode( ', ', array_slice( $names, 0, 3 ) ), changes: $changes ) );
		}
	}

	/**
	 * Reduces stored rules/channels to the given keys, keyed by ID.
	 *
	 * @param mixed              $items Stored list.
	 * @param array<int, string> $keys  Keys to keep.
	 *
	 * @return array<string, array<string, mixed>> Summaries by ID.
	 */
	private static function summaries( mixed $items, array $keys ): array {
		$summaries = array();

		foreach ( is_array( $items ) ? $items : array() as $index => $item ) {
			$item = (array) $item;
			$id   = (string) ( $item['id'] ?? $index );

			$summaries[ $id ] = array_intersect_key( $item, array_flip( $keys ) );
		}

		return $summaries;
	}
}
