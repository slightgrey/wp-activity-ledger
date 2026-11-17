<?php
/**
 * Watches one meta type for changes.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Sensors;

use WPActivityLedger\Loader;
use WPActivityLedger\Settings\Settings;
use WPActivityLedger\Support\FilterValue;
use WPActivityLedger\Support\PatternMatcher;

/**
 * Captures old and new values of meta changes (post meta by default) and reports them
 * to a callback as (object ID, field, old value, new value).
 *
 * Private keys ("_" prefix) are skipped unless allow-listed; ignored keys come from the
 * settings and the wpal_ignored_meta_keys filter. Some keys get readable field names
 * ("_thumbnail_id" → "featured_image", shown by attachment title).
 */
final class MetaWatcher {

	/**
	 * Private keys tracked by default: key => field name.
	 */
	private const PRIVATE_FIELDS = array(
		'_thumbnail_id'     => 'featured_image',
		'_wp_page_template' => 'template',
	);

	/**
	 * Values captured before an update or delete: object ID => key => value.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $before = array();

	/**
	 * Receives changes.
	 *
	 * @var callable(int, string, mixed, mixed): void
	 */
	private $callback;

	/**
	 * Ignored-key matcher, built on first use.
	 *
	 * @var PatternMatcher|null
	 */
	private ?PatternMatcher $ignored = null;

	/**
	 * Allowed private keys => field names, built on first use.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $private_fields = null;

	/**
	 * Creates the watcher.
	 *
	 * @param Settings $settings  Settings (ignored meta keys).
	 * @param string   $meta_type Meta type: post, term, comment.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly string $meta_type = 'post'
	) {
		$this->callback = static function (): void {};
	}

	/**
	 * Queues the meta hooks and sets the change callback.
	 *
	 * @param Loader                                    $loader   Hook loader.
	 * @param callable(int, string, mixed, mixed): void $callback Receives (object ID, field, old, new).
	 *
	 * @return void
	 */
	public function register( Loader $loader, callable $callback ): void {
		$this->callback = $callback;
		$type           = $this->meta_type;

		$loader->add_filter( "update_{$type}_metadata", array( $this, 'capture_before' ), 10, 3 );
		$loader->add_filter( "delete_{$type}_metadata", array( $this, 'capture_before' ), 10, 3 );
		$loader->add_action( "added_{$type}_meta", array( $this, 'on_written' ), 10, 4 );
		$loader->add_action( "updated_{$type}_meta", array( $this, 'on_written' ), 10, 4 );
		$loader->add_action( "deleted_{$type}_meta", array( $this, 'on_deleted' ), 10, 3 );
	}

	/**
	 * Remembers a meta value before it is updated or deleted (filter pass-through).
	 *
	 * @param mixed  $check     Short-circuit value (returned unchanged).
	 * @param int    $object_id Object ID.
	 * @param string $meta_key  Meta key.
	 *
	 * @return mixed The unchanged $check.
	 */
	public function capture_before( $check, $object_id, $meta_key ) {
		$id  = (int) $object_id;
		$key = (string) $meta_key;

		if ( $this->tracks( $key ) && ! array_key_exists( $key, $this->before[ $id ] ?? array() ) ) {
			$this->before[ $id ][ $key ] = metadata_exists( $this->meta_type, $id, $key ) ? get_metadata( $this->meta_type, $id, $key, true ) : null;
		}

		return $check;
	}

	/**
	 * Reports an added or updated value.
	 *
	 * @param int    $meta_id    Meta row ID.
	 * @param int    $object_id  Object ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 *
	 * @return void
	 */
	public function on_written( $meta_id, $object_id, $meta_key, $meta_value ): void {
		unset( $meta_id );
		$this->report( (int) $object_id, (string) $meta_key, $meta_value );
	}

	/**
	 * Reports a deleted value.
	 *
	 * @param array<int, int> $meta_ids  Deleted meta row IDs.
	 * @param int             $object_id Object ID.
	 * @param string          $meta_key  Meta key.
	 *
	 * @return void
	 */
	public function on_deleted( $meta_ids, $object_id, $meta_key ): void {
		unset( $meta_ids );
		$this->report( (int) $object_id, (string) $meta_key, null );
	}

	/**
	 * Tells whether a meta key is tracked.
	 *
	 * @param string $key Meta key.
	 *
	 * @return bool True when tracked.
	 */
	public function tracks( string $key ): bool {
		if ( '' === $key || $this->ignored()->matches( $key ) ) {
			return false;
		}

		return '_' !== $key[0] || isset( $this->private_fields()[ $key ] );
	}

	/**
	 * Passes a change to the callback with a readable field name and values.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $key       Meta key.
	 * @param mixed  $new_value New value.
	 *
	 * @return void
	 */
	private function report( int $object_id, string $key, mixed $new_value ): void {
		if ( ! $this->tracks( $key ) ) {
			return;
		}

		$old_value = $this->before[ $object_id ][ $key ] ?? null;
		$field     = $this->private_fields()[ $key ] ?? 'meta:' . $key;

		if ( 'featured_image' === $field ) {
			$old_value = self::attachment_label( $old_value );
			$new_value = self::attachment_label( $new_value );
		}

		( $this->callback )( $object_id, $field, $old_value, $new_value );
	}

	/**
	 * Returns the ignored-key matcher.
	 *
	 * @return PatternMatcher Matcher.
	 */
	private function ignored(): PatternMatcher {
		if ( null === $this->ignored ) {
			$defaults = array_map( 'strval', (array) $this->settings->get( 'ignored_meta_keys' ) );

			/** This filter is documented in src/Sensors/UserProfileSensor.php */
			$this->ignored = new PatternMatcher( FilterValue::strings( apply_filters( 'wpal_ignored_meta_keys', $defaults ), $defaults ) );
		}

		return $this->ignored;
	}

	/**
	 * Returns the allowed private keys and their field names.
	 *
	 * @return array<string, string> Key => field.
	 */
	private function private_fields(): array {
		if ( null === $this->private_fields ) {
			/**
			 * Filters the private ("_" prefixed) meta keys that are tracked anyway.
			 *
			 * @param array<string, string> $fields Meta key => field name shown in the log.
			 * @param string                $type   Meta type.
			 */
			$fields               = apply_filters( 'wpal_tracked_private_meta', self::PRIVATE_FIELDS, $this->meta_type );
			$this->private_fields = FilterValue::string_map( $fields, self::PRIVATE_FIELDS );
		}

		return $this->private_fields;
	}

	/**
	 * Turns an attachment ID into "Title (#ID)".
	 *
	 * @param mixed $value Attachment ID.
	 *
	 * @return string|null Label, or null for no image.
	 */
	private static function attachment_label( mixed $value ): ?string {
		$id = is_numeric( $value ) ? (int) $value : 0;

		if ( $id <= 0 ) {
			return null;
		}

		$title = get_the_title( $id );

		return ( '' === $title ? __( '(no title)', 'wp-activity-ledger' ) : $title ) . " (#$id)";
	}
}
