<?php
/**
 * Post, page and custom post type sensor.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Sensors;

use WPActivityLedger\Diff\Differ;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Loader;
use WPActivityLedger\Recording\Recorder;
use WPActivityLedger\Support\FilterValue;

/**
 * Records content activity, one event per post and request, recorded at shutdown.
 *
 * Revisions, autosaves, auto-drafts and internal post types are skipped. Attachments
 * belong to the media sensor; integrations can claim more types with the
 * wpal_post_sensor_skip_types filter.
 */
final class PostSensor implements SensorInterface {

	/**
	 * Post fields compared on update.
	 */
	public const FIELDS = array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_author', 'post_name', 'post_parent', 'menu_order', 'post_password', 'comment_status', 'ping_status', 'post_date' );

	/**
	 * Post types never handled here.
	 */
	private const SKIP_TYPES = array( 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'attachment', 'wp_font_face', 'wp_font_family' );

	/**
	 * Activity per post ID.
	 *
	 * @var array<int, PostActivity>
	 */
	private array $activities = array();

	/**
	 * Posts whose activity is dropped (auto-drafts sent to the trash), by ID.
	 *
	 * @var array<int, true>
	 */
	private array $discarded = array();

	/**
	 * Creates the sensor.
	 *
	 * @param Recorder    $recorder Recorder.
	 * @param Differ      $differ   Differ.
	 * @param MetaWatcher $meta     Post meta watcher.
	 */
	public function __construct(
		private readonly Recorder $recorder,
		private readonly Differ $differ,
		private readonly MetaWatcher $meta
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
		$loader->add_action( 'wp_insert_post', array( $this, 'on_insert' ), 10, 3 );
		$loader->add_action( 'post_updated', array( $this, 'on_update' ), 10, 3 );
		$loader->add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		$loader->add_action( 'before_delete_post', array( $this, 'on_delete' ), 10, 2 );
		$loader->add_action( 'set_object_terms', array( $this, 'on_terms' ), 10, 6 );
		$loader->add_action( 'post_stuck', array( $this, 'on_stuck' ), 10, 1 );
		$loader->add_action( 'post_unstuck', array( $this, 'on_unstuck' ), 10, 1 );
		$this->meta->register( $loader, array( $this, 'on_meta_change' ) );
		$loader->add_action( 'shutdown', array( $this, 'record_pending' ), 0, 0 );
	}

	/**
	 * Adds a meta change to the post's activity.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $field     Field name (e.g. "meta:color", "featured_image").
	 * @param mixed  $old_value Old value.
	 * @param mixed  $new_value New value.
	 *
	 * @return void
	 */
	public function on_meta_change( int $post_id, string $field, mixed $old_value, mixed $new_value ): void {
		$post = get_post( $post_id );

		if ( $post instanceof \WP_Post && $this->tracks( $post ) ) {
			$this->activity( $post )->change( $field, $old_value, $new_value );
		}
	}

	/**
	 * Adds a term change (names, sorted) to the post's activity.
	 *
	 * @param int             $object_id  Post ID.
	 * @param array<mixed>    $terms      Terms passed in.
	 * @param array<int, int> $tt_ids     New term taxonomy IDs.
	 * @param string          $taxonomy   Taxonomy.
	 * @param bool            $append     Whether terms were appended.
	 * @param array<int, int> $old_tt_ids Previous term taxonomy IDs.
	 *
	 * @return void
	 */
	public function on_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		unset( $terms, $append );
		$post = get_post( (int) $object_id );

		if ( ! $post instanceof \WP_Post || ! $this->tracks( $post ) ) {
			return;
		}

		$old_names = self::term_names( (array) $old_tt_ids, (string) $taxonomy );
		$new_names = self::term_names( (array) $tt_ids, (string) $taxonomy );

		if ( $old_names !== $new_names ) {
			$this->activity( $post )->change( 'tax:' . $taxonomy, $old_names, $new_names );
		}
	}

	/**
	 * Records a post being made sticky.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	public function on_stuck( $post_id ): void {
		$this->on_meta_change( (int) $post_id, 'sticky', false, true );
	}

	/**
	 * Records a post losing its sticky flag.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	public function on_unstuck( $post_id ): void {
		$this->on_meta_change( (int) $post_id, 'sticky', true, false );
	}

	/**
	 * Returns the sorted term names of term taxonomy IDs.
	 *
	 * @param array<int, int|string> $tt_ids   Term taxonomy IDs.
	 * @param string                 $taxonomy Taxonomy.
	 *
	 * @return array<int, string> Names.
	 */
	private static function term_names( array $tt_ids, string $taxonomy ): array {
		$names = array();

		foreach ( $tt_ids as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', (int) $tt_id, $taxonomy );

			if ( $term instanceof \WP_Term ) {
				$names[] = $term->name;
			}
		}

		sort( $names );

		return $names;
	}

	/**
	 * Turns a status change into published / scheduled / unpublished / trashed / restored.
	 *
	 * @param string $new_status New status.
	 * @param string $old_status Old status.
	 * @param mixed  $post       Post.
	 *
	 * @return void
	 */
	public function on_transition( $new_status, $old_status, mixed $post = null ): void {
		if ( $new_status === $old_status || ! $post instanceof \WP_Post || ! $this->tracks( $post ) ) {
			return;
		}

		if ( 'auto-draft' === $old_status && 'trash' === $new_status ) {
			// An auto-draft nobody wrote (e.g. removed with its author) is not content.
			$this->discarded[ $post->ID ] = true;

			return;
		}

		$kind = self::transition_kind( (string) $new_status, (string) $old_status );

		if ( null === $kind ) {
			return;
		}

		$activity = $this->activity( $post );
		$activity->promote( $kind );

		if ( 'post.scheduled' === $kind ) {
			$activity->add_context( array( 'date' => $post->post_date ) );
		}
	}

	/**
	 * Marks a post as permanently deleted, while its title is still available.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $post    Post.
	 *
	 * @return void
	 */
	public function on_delete( $post_id, mixed $post = null ): void {
		$post = $post instanceof \WP_Post ? $post : get_post( (int) $post_id );

		if ( ! $post instanceof \WP_Post || 'auto-draft' === $post->post_status || ! $this->tracks( $post ) ) {
			return;
		}

		$this->activity( $post )->promote( 'post.deleted' );
	}

	/**
	 * Maps a status change to an event type.
	 *
	 * @param string $new_status New status.
	 * @param string $old_status Old status.
	 *
	 * @return string|null Event type, or null when the change isn't an action of its own.
	 */
	private static function transition_kind( string $new_status, string $old_status ): ?string {
		if ( 'auto-draft' === $new_status || 'inherit' === $new_status ) {
			return null;
		}

		return match ( true ) {
			'trash' === $new_status                                             => 'post.trashed',
			'trash' === $old_status                                             => 'post.restored',
			'publish' === $new_status                                           => 'post.published',
			'future' === $new_status                                            => 'post.scheduled',
			'publish' === $old_status && in_array( $new_status, array( 'draft', 'pending', 'private' ), true ) => 'post.unpublished',
			default                                                             => null,
		};
	}

	/**
	 * Records a newly inserted post (not auto-drafts; their first real save counts as creation).
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $post    Post.
	 * @param bool  $update  Whether this was an update.
	 *
	 * @return void
	 */
	public function on_insert( $post_id, mixed $post = null, $update = false ): void {
		unset( $post_id );

		if ( $update || ! $post instanceof \WP_Post || 'auto-draft' === $post->post_status || ! $this->tracks( $post ) ) {
			return;
		}

		$this->activity( $post )->promote( 'post.created' );
	}

	/**
	 * Collects the changed fields of an updated post.
	 *
	 * @param int   $post_id     Post ID.
	 * @param mixed $post_after  Post after the update.
	 * @param mixed $post_before Post before the update.
	 *
	 * @return void
	 */
	public function on_update( $post_id, mixed $post_after = null, mixed $post_before = null ): void {
		unset( $post_id );

		if ( ! $post_after instanceof \WP_Post || ! $post_before instanceof \WP_Post || ! $this->tracks( $post_after ) ) {
			return;
		}

		$activity = $this->activity( $post_after );

		if ( 'auto-draft' === $post_before->post_status ) {
			$activity->promote( 'post.created' );
			return;
		}

		foreach ( self::FIELDS as $field ) {
			if ( 'post_date' === $field && self::has_floating_date( $post_before ) ) {
				continue; // A draft's date floats ("now" on every save) until it is set or published; not an edit.
			}

			$activity->change( $field, $post_before->$field, $post_after->$field );
		}
	}

	/**
	 * Records one event per post. Runs on shutdown (priority 0), before the Recorder flushes.
	 *
	 * @return void
	 */
	public function record_pending(): void {
		$activities       = $this->activities;
		$this->activities = array();

		foreach ( $activities as $activity ) {
			$this->record( $activity );
		}

		$this->discarded = array();
	}

	/**
	 * Records one post's activity, unless it is a plain update without real changes.
	 *
	 * @param PostActivity $activity Activity.
	 *
	 * @return void
	 */
	private function record( PostActivity $activity ): void {
		if ( isset( $this->discarded[ $activity->post()->ID ] ) ) {
			return;
		}

		$changes = array();

		foreach ( $activity->fields() as $field => [ $old, $new ] ) {
			if ( self::is_side_effect( $activity->kind(), $field ) ) {
				continue;
			}

			$change = $this->differ->compare( $field, $old, $new, (string) preg_replace( '/^meta:/', '', $field ) );

			if ( null !== $change ) {
				$changes[] = $change;
			}
		}

		if ( 'post.updated' === $activity->kind() && array() === $changes ) {
			return;
		}

		$post = $activity->post();

		$this->recorder->record(
			new Event(
				$activity->kind(),
				object_type: $post->post_type,
				object_id: (string) $post->ID,
				object_label: self::title( $post ),
				context: array_merge(
					array(
						'type_label' => self::type_label( $post->post_type ),
						'status'     => $post->post_status,
					),
					$activity->context()
				),
				changes: $changes
			)
		);
	}

	/**
	 * Tells whether a post has no fixed date yet (unpublished posts with an empty GMT date).
	 *
	 * @param \WP_Post $post Post.
	 *
	 * @return bool True when the date floats.
	 */
	private static function has_floating_date( \WP_Post $post ): bool {
		return '0000-00-00 00:00:00' === $post->post_date_gmt;
	}

	/**
	 * Tells whether a field change is only a side effect of the action itself.
	 *
	 * Deleting makes field changes meaningless; trashing/restoring renames the slug
	 * ("foo" → "foo__trashed") and stores trash meta.
	 *
	 * @param string $kind  Event type.
	 * @param string $field Field.
	 *
	 * @return bool True to skip the field.
	 */
	private static function is_side_effect( string $kind, string $field ): bool {
		if ( 'post.deleted' === $kind ) {
			return true;
		}

		return in_array( $kind, array( 'post.trashed', 'post.restored' ), true ) && 'post_name' === $field;
	}

	/**
	 * Returns (creating if needed) the activity of a post.
	 *
	 * @param \WP_Post $post Post.
	 *
	 * @return PostActivity Activity.
	 */
	private function activity( \WP_Post $post ): PostActivity {
		if ( ! isset( $this->activities[ $post->ID ] ) ) {
			$this->activities[ $post->ID ] = new PostActivity( $post );
		}

		$this->activities[ $post->ID ]->refresh( $post );

		return $this->activities[ $post->ID ];
	}

	/**
	 * Tells whether a post is tracked by this sensor.
	 *
	 * @param \WP_Post $post Post.
	 *
	 * @return bool True when tracked.
	 */
	private function tracks( \WP_Post $post ): bool {
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return false;
		}

		/**
		 * Filters the post types the content sensor ignores (integrations claim their own).
		 *
		 * @param array<int, string> $types Post type slugs.
		 */
		$skip = FilterValue::strings( apply_filters( 'wpal_post_sensor_skip_types', self::SKIP_TYPES ), self::SKIP_TYPES );

		return ! in_array( $post->post_type, $skip, true );
	}

	/**
	 * Returns a display title ("(no title)" when empty).
	 *
	 * @param \WP_Post $post Post.
	 *
	 * @return string Title.
	 */
	private static function title( \WP_Post $post ): string {
		$title = trim( wp_strip_all_tags( $post->post_title ) );

		return '' === $title ? __( '(no title)', 'wp-activity-ledger' ) : $title;
	}

	/**
	 * Returns the lowercase singular label of a post type, e.g. "page".
	 *
	 * @param string $post_type Post type slug.
	 *
	 * @return string Label.
	 */
	private static function type_label( string $post_type ): string {
		$object = get_post_type_object( $post_type );

		return null === $object ? $post_type : strtolower( (string) $object->labels->singular_name );
	}
}
