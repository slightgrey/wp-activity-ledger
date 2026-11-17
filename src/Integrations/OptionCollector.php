<?php
/**
 * Collects option changes for an integration.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Integrations;

use WPActivityLedger\Loader;

/**
 * Collects added, updated and deleted options an integration owns, keeping the first old
 * value and the latest new value per option for one request, and claims them so the core
 * options sensor skips them.
 */
final class OptionCollector {

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
	 * Creates the collector.
	 *
	 * @param \Closure $claims Tells whether an option (name) belongs to the integration.
	 * @param \Closure $tracks Tells whether a claimed option (name) is recorded (not noise).
	 *
	 * @phpstan-param \Closure(string): bool $claims
	 * @phpstan-param \Closure(string): bool $tracks
	 */
	public function __construct(
		private readonly \Closure $claims,
		private readonly \Closure $tracks
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
	 * Claims the integration's options.
	 *
	 * @param mixed  $claimed Whether another sensor already claimed it.
	 * @param string $option  Option name.
	 *
	 * @return bool True when claimed.
	 */
	public function claims_option( $claimed, $option ): bool {
		return (bool) $claimed || ( $this->claims )( (string) $option );
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
	 * Returns and clears the collected changes.
	 *
	 * @return array<string, array{0: mixed, 1: mixed}> Option => [old, new].
	 */
	public function take(): array {
		$pending       = $this->pending;
		$this->pending = array();

		return $pending;
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
	 * Tells whether an option is recorded.
	 *
	 * @param string $option Option name.
	 *
	 * @return bool True when claimed and not noise.
	 */
	private function tracks( string $option ): bool {
		return ( $this->claims )( $option ) && ( $this->tracks )( $option );
	}
}
