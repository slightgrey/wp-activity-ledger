<?php
/**
 * WooCommerce settings and payment gateway activity.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Integrations;

use WPActivityLedger\Diff\Differ;
use WPActivityLedger\Domain\Change;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Loader;
use WPActivityLedger\Recording\Recorder;
use WPActivityLedger\Sensors\OptionFilter;

/**
 * Records changes of "woocommerce_*" options: one "settings_changed" event per request,
 * labelled with the WooCommerce settings tab, plus one "gateway_toggled" event per payment
 * method switched on or off.
 *
 * Gateway settings live in "woocommerce_{gateway id}_settings" with "enabled" => "yes"|"no".
 * Watching the option (not the settings-page save action) also covers the toggle on the
 * payments list. The options are claimed so the options sensor skips them.
 */
final class WooSettingsWatcher {

	/**
	 * Changed options: option => [first old value, latest new value].
	 *
	 * @var array<string, array{0: mixed, 1: mixed}>
	 */
	private array $pending = array();

	/**
	 * Values captured before deletion: option => value.
	 *
	 * @var array<string, mixed>
	 */
	private array $deleting = array();

	/**
	 * Creates the watcher.
	 *
	 * @param Recorder     $recorder Recorder.
	 * @param Differ       $differ   Differ.
	 * @param OptionFilter $noise    Decides which options are noise.
	 */
	public function __construct(
		private readonly Recorder $recorder,
		private readonly Differ $differ,
		private readonly OptionFilter $noise
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
		$loader->add_filter( 'wpal_option_is_claimed', array( $this, 'claims_option' ), 10, 2 );
		$loader->add_action( 'updated_option', array( $this, 'on_updated' ), 10, 3 );
		$loader->add_action( 'added_option', array( $this, 'on_added' ), 10, 2 );
		$loader->add_action( 'delete_option', array( $this, 'before_delete' ), 10, 1 );
		$loader->add_action( 'deleted_option', array( $this, 'on_deleted' ), 10, 1 );
	}

	/**
	 * Claims the WooCommerce options for this watcher.
	 *
	 * @param mixed  $claimed Whether another sensor already claimed it.
	 * @param string $option  Option name.
	 *
	 * @return bool True when claimed.
	 */
	public function claims_option( $claimed, $option ): bool {
		return (bool) $claimed || self::is_woocommerce_option( (string) $option );
	}

	/**
	 * Collects an updated option.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Old value.
	 * @param mixed  $value     New value.
	 *
	 * @return void
	 */
	public function on_updated( $option, $old_value, $value ): void {
		$this->collect( (string) $option, $old_value, $value );
	}

	/**
	 * Collects an added option.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 *
	 * @return void
	 */
	public function on_added( $option, $value ): void {
		$this->collect( (string) $option, null, $value );
	}

	/**
	 * Remembers an option's value before it is deleted.
	 *
	 * @param string $option Option name.
	 *
	 * @return void
	 */
	public function before_delete( $option ): void {
		if ( $this->tracks( (string) $option ) ) {
			$this->deleting[ (string) $option ] = get_option( (string) $option );
		}
	}

	/**
	 * Collects a deleted option.
	 *
	 * @param string $option Option name.
	 *
	 * @return void
	 */
	public function on_deleted( $option ): void {
		if ( array_key_exists( (string) $option, $this->deleting ) ) {
			$this->collect( (string) $option, $this->deleting[ (string) $option ], null );
			unset( $this->deleting[ (string) $option ] );
		}
	}

	/**
	 * Records the gateway toggles and one settings event (called by the integration on shutdown).
	 *
	 * @return void
	 */
	public function record_pending(): void {
		$pending       = $this->pending;
		$this->pending = array();
		$changes       = array();

		foreach ( $pending as $option => [ $old, $new ] ) {
			[ $old, $new ] = $this->split_gateway_toggle( $option, $old, $new );
			array_push( $changes, ...$this->differ->diff_value( $option, $old, $new ) );
		}

		if ( array() !== $changes ) {
			$this->record_settings( $changes );
		}
	}

	/**
	 * Records a gateway toggle hidden in a gateway settings change and removes it from the values.
	 *
	 * @param string $option Option name.
	 * @param mixed  $old_value    Old value.
	 * @param mixed  $new_value    New value.
	 *
	 * @return array{0: mixed, 1: mixed} Old and new value without the "enabled" key when toggled.
	 */
	private function split_gateway_toggle( string $option, mixed $old_value, mixed $new_value ): array {
		$gateway = self::gateway_for( $option );
		$before  = is_array( $old_value ) ? ( $old_value['enabled'] ?? 'no' ) : 'no';
		$after   = is_array( $new_value ) ? ( $new_value['enabled'] ?? 'no' ) : 'no';

		if ( null === $gateway || $before === $after ) {
			return array( $old_value, $new_value );
		}

		$this->record_toggle( $gateway, 'yes' === $after, $this->differ->compare( 'enabled', $before, $after ) );

		if ( is_array( $old_value ) ) {
			unset( $old_value['enabled'] );
		}

		if ( is_array( $new_value ) ) {
			unset( $new_value['enabled'] );
		}

		return array( $old_value, $new_value );
	}

	/**
	 * Records a payment method switched on or off.
	 *
	 * @param \WC_Payment_Gateway $gateway Gateway.
	 * @param bool                $enabled Whether it is now on.
	 * @param Change|null         $change  The "enabled" change.
	 *
	 * @return void
	 */
	private function record_toggle( \WC_Payment_Gateway $gateway, bool $enabled, ?Change $change ): void {
		$title = wp_strip_all_tags( (string) $gateway->get_method_title() );

		$this->recorder->record(
			new Event(
				'woocommerce.gateway_toggled',
				object_type: 'payment_gateway',
				object_id: (string) $gateway->id,
				object_label: '' === $title ? (string) $gateway->id : $title,
				context: array(
					'enabled' => $enabled,
					'state'   => $enabled ? __( 'on', 'wp-activity-ledger' ) : __( 'off', 'wp-activity-ledger' ),
				),
				changes: null === $change ? array() : array( $change )
			)
		);
	}

	/**
	 * Records one event with the WooCommerce settings changed in this request.
	 *
	 * @param array<int, Change> $changes Changes.
	 *
	 * @return void
	 */
	private function record_settings( array $changes ): void {
		[ $tab, $section ] = self::settings_tab();

		$this->recorder->record(
			new Event(
				'woocommerce.settings_changed',
				object_type: 'settings',
				object_id: '' === $section ? 'wc-settings:' . $tab : 'wc-settings:' . $tab . ':' . $section,
				object_label: '' === $section ? $tab : $tab . ' › ' . $section,
				context: array(
					'count'   => count( $changes ),
					'tab'     => $tab,
					'section' => $section,
				),
				changes: $changes
			)
		);
	}

	/**
	 * Adds an option change, keeping the first old value and the latest new value.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Old value.
	 * @param mixed  $value     New value.
	 *
	 * @return void
	 */
	private function collect( string $option, mixed $old_value, mixed $value ): void {
		if ( ! $this->tracks( $option ) ) {
			return;
		}

		$first                    = array_key_exists( $option, $this->pending ) ? $this->pending[ $option ][0] : $old_value;
		$this->pending[ $option ] = array( $first, $value );
	}

	/**
	 * Tells whether an option is recorded here.
	 *
	 * @param string $option Option name.
	 *
	 * @return bool True for WooCommerce options that aren't noise.
	 */
	private function tracks( string $option ): bool {
		return self::is_woocommerce_option( $option ) && ! $this->noise->is_noise( $option );
	}

	/**
	 * Tells whether an option belongs to WooCommerce.
	 *
	 * @param string $option Option name.
	 *
	 * @return bool True for "woocommerce_*" options.
	 */
	private static function is_woocommerce_option( string $option ): bool {
		return str_starts_with( $option, 'woocommerce_' );
	}

	/**
	 * Returns the payment gateway whose settings an option holds.
	 *
	 * @param string $option Option name.
	 *
	 * @return \WC_Payment_Gateway|null Gateway, or null when the option isn't a gateway's settings.
	 */
	private static function gateway_for( string $option ): ?\WC_Payment_Gateway {
		if ( ! preg_match( '/^woocommerce_(.+)_settings$/', $option, $match ) || ! function_exists( 'WC' ) ) {
			return null;
		}

		$gateway = WC()->payment_gateways()->payment_gateways()[ $match[1] ] ?? null;

		return $gateway instanceof \WC_Payment_Gateway ? $gateway : null;
	}

	/**
	 * Returns the WooCommerce settings tab and section of the request.
	 *
	 * @return array{0: string, 1: string} Tab ("general" by default, "woocommerce" outside wc-settings) and section.
	 */
	private static function settings_tab(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only read to label the event; WooCommerce verified the save.
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		// phpcs:enable

		if ( 'wc-settings' !== $page ) {
			return array( 'woocommerce', '' );
		}

		return array( '' === $tab ? 'general' : $tab, $section );
	}
}
