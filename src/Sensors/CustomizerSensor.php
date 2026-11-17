<?php
/**
 * Customizer and widgets sensor.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Sensors;

use WPActivityLedger\Diff\Differ;
use WPActivityLedger\Domain\Change;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Loader;
use WPActivityLedger\Recording\Recorder;

/**
 * Records Customizer saves and widget changes.
 *
 * The Customizer previews settings while saving, so its API can't give old values.
 * Instead, every option write between customize_save and customize_save_after is
 * collected into one "customizer.saved" event (theme mods diffed key by key). Widget
 * option writes outside the Customizer become one "widget.updated" event per request.
 * Both claim their options (wpal_option_is_claimed) so the options sensor skips them.
 */
final class CustomizerSensor implements SensorInterface {

	/**
	 * Whether a Customizer save is in progress.
	 *
	 * @var bool
	 */
	private bool $in_customizer = false;

	/**
	 * Changes collected during the Customizer save.
	 *
	 * @var array<int, Change>
	 */
	private array $customizer_changes = array();

	/**
	 * Widget option changes: option => [first old, latest new].
	 *
	 * @var array<string, array{0: mixed, 1: mixed}>
	 */
	private array $widgets = array();

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
		$loader->add_action( 'customize_save', array( $this, 'start_customizer_save' ), 1, 0 );
		$loader->add_action( 'customize_save_after', array( $this, 'finish_customizer_save' ), 99, 0 );
		$loader->add_action( 'updated_option', array( $this, 'on_option_updated' ), 10, 3 );
		$loader->add_action( 'added_option', array( $this, 'on_option_added' ), 10, 2 );
		$loader->add_filter( 'wpal_option_is_claimed', array( $this, 'claims_option' ), 10, 2 );
		$loader->add_action( 'shutdown', array( $this, 'record_pending' ), 0, 0 );
	}

	/**
	 * Starts collecting a Customizer save.
	 *
	 * @return void
	 */
	public function start_customizer_save(): void {
		$this->in_customizer      = true;
		$this->customizer_changes = array();
	}

	/**
	 * Records the collected Customizer changes as one event.
	 *
	 * @return void
	 */
	public function finish_customizer_save(): void {
		$this->in_customizer      = false;
		$changes                  = $this->customizer_changes;
		$this->customizer_changes = array();

		if ( array() === $changes ) {
			return;
		}

		$this->recorder->record(
			new Event(
				'customizer.saved',
				object_type: 'customizer',
				object_label: (string) get_stylesheet(),
				context: array( 'count' => count( $changes ) ),
				changes: $changes
			)
		);
	}

	/**
	 * Collects an updated option into the Customizer save or the widget changes.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Old value.
	 * @param mixed  $value     New value.
	 *
	 * @return void
	 */
	public function on_option_updated( $option, $old_value, $value ): void {
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
	public function on_option_added( $option, $value ): void {
		$this->collect( (string) $option, null, $value );
	}

	/**
	 * Claims widget options always, and every option during a Customizer save.
	 *
	 * @param mixed  $claimed Whether another sensor already claimed it.
	 * @param string $option  Option name.
	 *
	 * @return bool True when claimed.
	 */
	public function claims_option( $claimed, $option ): bool {
		return (bool) $claimed || $this->in_customizer || self::is_widget_option( (string) $option );
	}

	/**
	 * Records one widget.updated event for this request. Runs on shutdown (priority 0).
	 *
	 * @return void
	 */
	public function record_pending(): void {
		$widgets       = $this->widgets;
		$this->widgets = array();
		$changes       = array();

		foreach ( $widgets as $option => [ $old, $new ] ) {
			array_push( $changes, ...$this->option_changes( $option, $old, $new ) );
		}

		if ( array() !== $changes ) {
			$this->recorder->record( new Event( 'widget.updated', object_type: 'widgets', object_label: (string) get_stylesheet(), changes: $changes ) );
		}
	}

	/**
	 * Routes an option write to the Customizer save or the widget changes.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Old value.
	 * @param mixed  $value     New value.
	 *
	 * @return void
	 */
	private function collect( string $option, mixed $old_value, mixed $value ): void {
		if ( $this->in_customizer ) {
			array_push( $this->customizer_changes, ...$this->option_changes( $option, $old_value, $value ) );
			return;
		}

		if ( self::is_widget_option( $option ) ) {
			$first                    = array_key_exists( $option, $this->widgets ) ? $this->widgets[ $option ][0] : $old_value;
			$this->widgets[ $option ] = array( $first, $value );
		}
	}

	/**
	 * Diffs an option: arrays key by key ("theme_mod:color", "widget_text:2"), scalars as one field.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Old value.
	 * @param mixed  $value     New value.
	 *
	 * @return array<int, Change> Changes.
	 */
	private function option_changes( string $option, mixed $old_value, mixed $value ): array {
		$old_value = Differ::decode( $old_value );
		$value     = Differ::decode( $value );

		if ( is_array( $old_value ) || is_array( $value ) ) {
			$prefix = str_starts_with( $option, 'theme_mods_' ) ? 'theme_mod:' : $option . ':';
			$old    = is_array( $old_value ) ? $old_value : array();
			$new    = is_array( $value ) ? $value : array();
			unset( $old['_multiwidget'], $new['_multiwidget'] );

			return $this->differ->diff( $old, $new, null, $prefix );
		}

		$change = $this->differ->compare( $option, $old_value, $value );

		return null === $change ? array() : array( $change );
	}

	/**
	 * Tells whether an option stores widgets.
	 *
	 * @param string $option Option name.
	 *
	 * @return bool True for sidebars_widgets and widget_* options.
	 */
	private static function is_widget_option( string $option ): bool {
		return 'sidebars_widgets' === $option || str_starts_with( $option, 'widget_' );
	}
}
