<?php
/**
 * User profile sensor.
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
 * Records profile edits as one "user.updated" event per user and request.
 *
 * Core fields come from profile_update; user meta from the meta hooks (minus the
 * ignored keys, so admin-screen preferences and similar noise are skipped). Changes are collected during the request (first old value, last new
 * value) and recorded at shutdown, before the Recorder flushes. Users created or deleted
 * in the same request are skipped: their own events already cover them.
 */
final class UserProfileSensor implements SensorInterface {

	/**
	 * Core user fields compared on profile_update.
	 */
	private const CORE_FIELDS = array( 'user_email', 'user_url', 'user_nicename', 'display_name', 'user_status' );

	/**
	 * User meta that changes as a side effect of normal admin use, or is covered by other
	 * events (capabilities = role events). Always ignored, on top of the settings' list.
	 */
	private const NOISE_META = array(
		'*capabilities',
		'*user_level',
		'session_tokens',
		'wpal_ui_prefs',
		'dismissed_wp_pointers',
		'show_welcome_panel',
		'default_password_nag',
		'closedpostboxes_*',
		'metaboxhidden_*',
		'meta-box-order_*',
		'screen_layout_*',
		'manage*columnshidden',
		'edit_*_per_page',
		'*_per_page',
		'wp_*user-settings*',
		'*dashboard_quick_press_last_post_id',
		'community-events-location',
		'last_update',
		'wc_last_active',
		'_woocommerce_persistent_cart_*',
		'_woocommerce_tracks_*',
		'*elementor_*',
	);

	/**
	 * Matcher for ignored meta keys, built on first use.
	 *
	 * @var PatternMatcher|null
	 */
	private ?PatternMatcher $ignored = null;

	/**
	 * Pending raw values: user ID => field => [old, new].
	 *
	 * @var array<int, array<string, array{0: mixed, 1: mixed}>>
	 */
	private array $pending = array();

	/**
	 * Meta values captured before an update or delete: user ID => key => value.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $meta_before = array();

	/**
	 * Users created or deleted during this request (user ID => true).
	 *
	 * @var array<int, bool>
	 */
	private array $skip_users = array();

	/**
	 * Creates the sensor.
	 *
	 * @param Recorder $recorder Recorder.
	 * @param Differ   $differ   Differ.
	 * @param Settings $settings Settings (ignored meta keys).
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
		$loader->add_action( 'user_register', array( $this, 'skip_user' ), 10, 1 );
		$loader->add_action( 'delete_user', array( $this, 'skip_user' ), 10, 1 );
		$loader->add_action( 'profile_update', array( $this, 'on_profile_update' ), 10, 2 );
		$loader->add_filter( 'update_user_metadata', array( $this, 'capture_meta_before' ), 10, 3 );
		$loader->add_filter( 'delete_user_metadata', array( $this, 'capture_meta_before' ), 10, 3 );
		$loader->add_action( 'added_user_meta', array( $this, 'on_meta_written' ), 10, 4 );
		$loader->add_action( 'updated_user_meta', array( $this, 'on_meta_written' ), 10, 4 );
		$loader->add_action( 'deleted_user_meta', array( $this, 'on_meta_deleted' ), 10, 3 );
		$loader->add_action( 'shutdown', array( $this, 'record_pending' ), 0, 0 );
	}

	/**
	 * Marks a user as created or deleted in this request.
	 *
	 * @param int $user_id User.
	 *
	 * @return void
	 */
	public function skip_user( $user_id ): void {
		$this->skip_users[ (int) $user_id ] = true;
	}

	/**
	 * Collects changed core fields and a password change after a profile update.
	 *
	 * @param int   $user_id       User.
	 * @param mixed $old_user_data User before the update (WP_User).
	 *
	 * @return void
	 */
	public function on_profile_update( $user_id, mixed $old_user_data = null ): void {
		$user_id = (int) $user_id;
		$new     = get_userdata( $user_id );

		if ( ! $old_user_data instanceof \WP_User || false === $new ) {
			return;
		}

		foreach ( self::CORE_FIELDS as $field ) {
			$this->add( $user_id, $field, $old_user_data->data->$field ?? null, $new->data->$field ?? null );
		}

		$this->add( $user_id, 'password', (string) $old_user_data->user_pass, (string) $new->user_pass );
	}

	/**
	 * Remembers a meta value before WordPress updates or deletes it (filter pass-through).
	 *
	 * @param mixed  $check     Short-circuit value (returned unchanged).
	 * @param int    $user_id   User.
	 * @param string $meta_key  Meta key.
	 *
	 * @return mixed The unchanged $check.
	 */
	public function capture_meta_before( $check, $user_id, $meta_key ) {
		$user_id = (int) $user_id;
		$key     = (string) $meta_key;

		if ( $this->tracks_meta( $key ) && ! array_key_exists( $key, $this->meta_before[ $user_id ] ?? array() ) ) {
			$this->meta_before[ $user_id ][ $key ] = metadata_exists( 'user', $user_id, $key ) ? get_user_meta( $user_id, $key, true ) : null;
		}

		return $check;
	}

	/**
	 * Collects an added or updated meta value.
	 *
	 * @param int    $meta_id    Meta row ID.
	 * @param int    $user_id    User.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 *
	 * @return void
	 */
	public function on_meta_written( $meta_id, $user_id, $meta_key, $meta_value ): void {
		unset( $meta_id );
		$key = (string) $meta_key;

		if ( $this->tracks_meta( $key ) ) {
			$this->add( (int) $user_id, 'meta:' . $key, $this->meta_before[ (int) $user_id ][ $key ] ?? null, $meta_value );
		}
	}

	/**
	 * Collects a deleted meta value.
	 *
	 * @param array<int, int> $meta_ids Deleted meta row IDs.
	 * @param int             $user_id  User.
	 * @param string          $meta_key Meta key.
	 *
	 * @return void
	 */
	public function on_meta_deleted( $meta_ids, $user_id, $meta_key ): void {
		unset( $meta_ids );
		$key = (string) $meta_key;

		if ( $this->tracks_meta( $key ) ) {
			$this->add( (int) $user_id, 'meta:' . $key, $this->meta_before[ (int) $user_id ][ $key ] ?? null, null );
		}
	}

	/**
	 * Records one user.updated event per changed user. Runs on shutdown (priority 0).
	 *
	 * @return void
	 */
	public function record_pending(): void {
		$pending       = $this->pending;
		$this->pending = array();

		foreach ( $pending as $user_id => $fields ) {
			if ( ! isset( $this->skip_users[ $user_id ] ) ) {
				$this->record_user( $user_id, $fields );
			}
		}

		$this->meta_before = array();
		$this->skip_users  = array();
	}

	/**
	 * Records the changes of one user, if any field really changed.
	 *
	 * @param int                                      $user_id User.
	 * @param array<string, array{0: mixed, 1: mixed}> $fields  Field => [old, new].
	 *
	 * @return void
	 */
	private function record_user( int $user_id, array $fields ): void {
		$user    = get_userdata( $user_id );
		$changes = array();

		foreach ( $fields as $field => [ $old, $new ] ) {
			$change = $this->differ->compare( $field, $old, $new, (string) preg_replace( '/^meta:/', '', $field ) );

			if ( null !== $change ) {
				$changes[] = $change;
			}
		}

		if ( array() === $changes || false === $user ) {
			return;
		}

		$this->recorder->record(
			new Event(
				'user.updated',
				object_type: 'user',
				object_id: (string) $user_id,
				object_label: (string) $user->user_login,
				changes: $changes
			)
		);
	}

	/**
	 * Adds a field change, keeping the first old value and the latest new value.
	 *
	 * @param int    $user_id   User.
	 * @param string $field     Field.
	 * @param mixed  $old_value Old value.
	 * @param mixed  $new_value New value.
	 *
	 * @return void
	 */
	private function add( int $user_id, string $field, mixed $old_value, mixed $new_value ): void {
		$first_old = array_key_exists( $field, $this->pending[ $user_id ] ?? array() ) ? $this->pending[ $user_id ][ $field ][0] : $old_value;

		$this->pending[ $user_id ][ $field ] = array( $first_old, $new_value );
	}

	/**
	 * Tells whether a user meta key is tracked.
	 *
	 * @param string $key Meta key.
	 *
	 * @return bool True when tracked.
	 */
	private function tracks_meta( string $key ): bool {
		if ( null === $this->ignored ) {
			/**
			 * Filters the ignored user/post meta key patterns.
			 *
			 * @param array<int, string> $patterns Glob patterns.
			 */
			$patterns      = apply_filters( 'wpal_ignored_meta_keys', array_merge( self::NOISE_META, (array) $this->settings->get( 'ignored_meta_keys' ) ) );
			$this->ignored = new PatternMatcher( FilterValue::strings( $patterns, self::NOISE_META ) );
		}

		return '' !== $key && ! $this->ignored->matches( $key );
	}
}
