<?php
/**
 * Network (multisite) sensor.
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
 * Records network activity: sites created, deleted, archived, spammed or deactivated (and
 * back), and network settings (site options), grouped per request. Every event is marked
 * `network`, so the Recorder stores it in the main site's log.
 *
 * Only active on multisite: on a single site, site options are plain options, which the
 * options sensor already records.
 */
final class MultisiteSensor implements SensorInterface {

	/**
	 * Status hooks: hook => event type.
	 */
	private const STATUS_HOOKS = array(
		'archive_blog'    => 'multisite.site_archived',
		'unarchive_blog'  => 'multisite.site_unarchived',
		'make_spam_blog'  => 'multisite.site_spammed',
		'make_ham_blog'   => 'multisite.site_unspammed',
		'deactivate_blog' => 'multisite.site_deactivated',
		'activate_blog'   => 'multisite.site_activated',
	);

	/**
	 * Changed network options: option => [first old value, latest new value].
	 *
	 * @var array<string, array{0: mixed, 1: mixed}>
	 */
	private array $pending = array();

	/**
	 * Creates the sensor.
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
	 * Queues the hooks (multisite only).
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public function register( Loader $loader ): void {
		if ( ! is_multisite() ) {
			return;
		}

		$loader->add_action( 'wp_initialize_site', array( $this, 'on_site_created' ), 300, 1 );
		$loader->add_action( 'wp_uninitialize_site', array( $this, 'on_site_deleted' ), 5, 1 );

		foreach ( array_keys( self::STATUS_HOOKS ) as $hook ) {
			$loader->add_action( $hook, array( $this, 'on_' . $hook ), 10, 1 );
		}

		$loader->add_action( 'update_site_option', array( $this, 'on_option_updated' ), 10, 3 );
		$loader->add_action( 'add_site_option', array( $this, 'on_option_added' ), 10, 2 );
		$loader->add_action( 'delete_site_option', array( $this, 'on_option_deleted' ), 10, 1 );
		$loader->add_action( 'shutdown', array( $this, 'record_pending' ), 0, 0 );
	}

	/**
	 * Records a new site.
	 *
	 * @param mixed $site Site.
	 *
	 * @return void
	 */
	public function on_site_created( mixed $site = null ): void {
		if ( $site instanceof \WP_Site ) {
			$this->record_site( 'multisite.site_created', $site );
		}
	}

	/**
	 * Records a deleted site (before its tables are dropped, while its name is known).
	 *
	 * @param mixed $site Site.
	 *
	 * @return void
	 */
	public function on_site_deleted( mixed $site = null ): void {
		if ( $site instanceof \WP_Site ) {
			$this->record_site( 'multisite.site_deleted', $site );
		}
	}

	/**
	 * Records an archived site.
	 *
	 * @param int $site_id Site ID.
	 *
	 * @return void
	 */
	public function on_archive_blog( $site_id ): void {
		$this->record_status( 'archive_blog', (int) $site_id );
	}

	/**
	 * Records an unarchived site.
	 *
	 * @param int $site_id Site ID.
	 *
	 * @return void
	 */
	public function on_unarchive_blog( $site_id ): void {
		$this->record_status( 'unarchive_blog', (int) $site_id );
	}

	/**
	 * Records a site marked as spam.
	 *
	 * @param int $site_id Site ID.
	 *
	 * @return void
	 */
	public function on_make_spam_blog( $site_id ): void {
		$this->record_status( 'make_spam_blog', (int) $site_id );
	}

	/**
	 * Records a site unmarked as spam.
	 *
	 * @param int $site_id Site ID.
	 *
	 * @return void
	 */
	public function on_make_ham_blog( $site_id ): void {
		$this->record_status( 'make_ham_blog', (int) $site_id );
	}

	/**
	 * Records a deactivated site.
	 *
	 * @param int $site_id Site ID.
	 *
	 * @return void
	 */
	public function on_deactivate_blog( $site_id ): void {
		$this->record_status( 'deactivate_blog', (int) $site_id );
	}

	/**
	 * Records an activated site.
	 *
	 * @param int $site_id Site ID.
	 *
	 * @return void
	 */
	public function on_activate_blog( $site_id ): void {
		$this->record_status( 'activate_blog', (int) $site_id );
	}

	/**
	 * Collects an updated network option.
	 *
	 * @param string $option    Option.
	 * @param mixed  $value     New value.
	 * @param mixed  $old_value Old value.
	 *
	 * @return void
	 */
	public function on_option_updated( $option, $value, $old_value ): void {
		$this->collect( (string) $option, $old_value, $value );
	}

	/**
	 * Collects an added network option.
	 *
	 * @param string $option Option.
	 * @param mixed  $value  Value.
	 *
	 * @return void
	 */
	public function on_option_added( $option, $value ): void {
		$this->collect( (string) $option, null, $value );
	}

	/**
	 * Collects a deleted network option (its old value is no longer known).
	 *
	 * @param string $option Option.
	 *
	 * @return void
	 */
	public function on_option_deleted( $option ): void {
		$this->collect( (string) $option, '(set)', null );
	}

	/**
	 * Records the network options changed in this request as one event.
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

		$this->recorder->record(
			new Event(
				'multisite.network_settings_changed',
				object_type: 'network',
				object_id: (string) get_current_network_id(),
				object_label: (string) get_network()?->site_name,
				context: array(
					'count'   => count( $changes ),
					'network' => true,
				),
				changes: $changes
			)
		);
	}

	/**
	 * Adds a network option change, keeping the first old value.
	 *
	 * @param string $option    Option.
	 * @param mixed  $old_value Old value.
	 * @param mixed  $value     New value.
	 *
	 * @return void
	 */
	private function collect( string $option, mixed $old_value, mixed $value ): void {
		/** This filter is documented in src/Sensors/OptionSensor.php */
		if ( $this->noise->is_noise( $option ) || apply_filters( 'wpal_option_is_claimed', false, $option ) ) {
			return;
		}

		$first                    = array_key_exists( $option, $this->pending ) ? $this->pending[ $option ][0] : $old_value;
		$this->pending[ $option ] = array( $first, $value );
	}

	/**
	 * Records a site status change.
	 *
	 * @param string $hook    Status hook.
	 * @param int    $site_id Site ID.
	 *
	 * @return void
	 */
	private function record_status( string $hook, int $site_id ): void {
		$site = get_site( $site_id );

		if ( $site instanceof \WP_Site ) {
			$this->record_site( self::STATUS_HOOKS[ $hook ], $site );
		}
	}

	/**
	 * Records an event about a site.
	 *
	 * @param string   $type Event type.
	 * @param \WP_Site $site Site.
	 *
	 * @return void
	 */
	private function record_site( string $type, \WP_Site $site ): void {
		$name = (string) get_blog_option( (int) $site->blog_id, 'blogname' );
		$url  = $site->domain . $site->path;

		$this->recorder->record(
			new Event(
				$type,
				object_type: 'site',
				object_id: (string) $site->blog_id,
				object_label: '' === $name ? $url : $name,
				context: array(
					'url'     => $url,
					'network' => true,
				)
			)
		);
	}
}
