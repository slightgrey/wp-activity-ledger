<?php
/**
 * Site settings (options) sensor.
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
use WPActivityLedger\Support\FilterValue;
use WPActivityLedger\Support\PatternMatcher;

/**
 * Records option changes, all changes of one request in one "option.updated" event,
 * labelled with the settings page they came from.
 *
 * Skipped: ignored patterns (settings + wpal_ignored_option_patterns), Activity Ledger's
 * own options (the self sensor records those), and options another sensor claims via
 * wpal_option_is_claimed (widgets, Customizer, auto-updates, integrations).
 */
final class OptionSensor implements SensorInterface {

	/**
	 * Options always skipped here: Activity Ledger's own (the self sensor records them) and
	 * values WordPress or common plugins rewrite on their own. Kept in code, not settings,
	 * so updates of this list reach existing sites.
	 */
	private const NOISE = array(
		'wpal_*',
		'_transient_*',
		'_site_transient_*',
		'cron',
		'*_lock',
		'rewrite_rules',
		'user_count',
		'recently_activated',
		'active_plugins',
		'uninstall_plugins',
		'db_upgraded',
		'finished_updating_comment_type',
		'auto_core_update_*',
		'can_compress_scripts',
		'*_last_checked',
		'*_cache*',
		'action_scheduler_*',
		'jetpack_*sync*',
		'_elementor_*',
	);

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
	 * Ignored-option matcher, built on first use.
	 *
	 * @var PatternMatcher|null
	 */
	private ?PatternMatcher $ignored = null;

	/**
	 * Creates the sensor.
	 *
	 * @param Recorder $recorder Recorder.
	 * @param Differ   $differ   Differ.
	 * @param Settings $settings Settings (ignored option patterns).
	 */
	public function __construct(
		private readonly Recorder $recorder,
		private readonly Differ $differ,
		private readonly Settings $settings
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
		$loader->add_action( 'updated_option', array( $this, 'on_updated' ), 10, 3 );
		$loader->add_action( 'added_option', array( $this, 'on_added' ), 10, 2 );
		$loader->add_action( 'delete_option', array( $this, 'before_delete' ), 10, 1 );
		$loader->add_action( 'deleted_option', array( $this, 'on_deleted' ), 10, 1 );
		$loader->add_action( 'shutdown', array( $this, 'record_pending' ), 0, 0 );
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
	 * Records one event with every option changed in this request. Runs on shutdown (priority 0).
	 *
	 * @return void
	 */
	public function record_pending(): void {
		$pending       = $this->pending;
		$this->pending = array();
		$changes       = array();

		foreach ( $pending as $option => [ $old, $new ] ) {
			array_push( $changes, ...$this->differ->diff_value( $option, $old, $new ) );
		}

		if ( array() === $changes ) {
			return;
		}

		$page = self::settings_page();

		$this->recorder->record(
			new Event(
				'option.updated',
				object_type: 'settings',
				object_id: $page,
				object_label: $page,
				context: array(
					'count'   => count( $changes ),
					'options' => array_keys( $pending ),
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
	 * @return bool True when tracked.
	 */
	private function tracks( string $option ): bool {
		if ( '' === $option || $this->ignored()->matches( $option ) ) {
			return false;
		}

		/**
		 * Filters whether another sensor records this option (so the options sensor skips it).
		 *
		 * @param bool   $claimed Whether it is claimed.
		 * @param string $option  Option name.
		 */
		return ! apply_filters( 'wpal_option_is_claimed', false, $option );
	}

	/**
	 * Returns the ignored-option matcher.
	 *
	 * @return PatternMatcher Matcher.
	 */
	private function ignored(): PatternMatcher {
		if ( null === $this->ignored ) {
			$defaults = array_map( 'strval', (array) $this->settings->get( 'ignored_option_patterns' ) );

			/**
			 * Filters the ignored option name patterns.
			 *
			 * @param array<int, string> $patterns Glob patterns.
			 */
			$patterns      = FilterValue::strings( apply_filters( 'wpal_ignored_option_patterns', $defaults ), $defaults );
			$this->ignored = new PatternMatcher( array_merge( self::NOISE, $patterns ) );
		}

		return $this->ignored;
	}

	/**
	 * Returns the settings page the request came from.
	 *
	 * @return string "general", "reading", a plugin page slug, or "site settings".
	 */
	private static function settings_page(): string {
		// phpcs:disable WordPress.Security.NonceVerification -- Only read to label the event; core verified the save.
		$page = isset( $_POST['option_page'] ) ? sanitize_key( wp_unslash( $_POST['option_page'] ) ) : '';
		$page = '' === $page && isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : $page;
		// phpcs:enable

		return '' === $page ? __( 'site settings', 'wp-activity-ledger' ) : $page;
	}
}
