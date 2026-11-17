<?php
/**
 * Media library sensor.
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
 * Records uploads, edits of title/caption/description/alt text, image edits and
 * deletions; one event per attachment and request, recorded at shutdown.
 */
final class MediaSensor implements SensorInterface {

	/**
	 * Event types from least to most significant.
	 */
	private const RANK = array(
		'media.updated'  => 0,
		'media.edited'   => 1,
		'media.uploaded' => 2,
		'media.deleted'  => 3,
	);

	/**
	 * Attachment fields compared on update: post field => field name in the log.
	 */
	private const FIELDS = array(
		'post_title'   => 'title',
		'post_excerpt' => 'caption',
		'post_content' => 'description',
		'post_parent'  => 'attached_to',
	);

	/**
	 * Alt text meta key.
	 */
	private const ALT_KEY = '_wp_attachment_image_alt';

	/**
	 * Activity per attachment ID.
	 *
	 * @var array<int, PostActivity>
	 */
	private array $activities = array();

	/**
	 * Alt text values captured before an update: attachment ID => value.
	 *
	 * @var array<int, string|null>
	 */
	private array $alt_before = array();

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
		$loader->add_action( 'add_attachment', array( $this, 'on_upload' ), 10, 1 );
		$loader->add_action( 'attachment_updated', array( $this, 'on_update' ), 10, 3 );
		$loader->add_action( 'delete_attachment', array( $this, 'on_delete' ), 10, 1 );
		$loader->add_filter( 'wp_save_image_editor_file', array( $this, 'on_image_edit' ), 10, 5 );
		$loader->add_filter( 'update_post_metadata', array( $this, 'capture_alt' ), 10, 3 );
		$loader->add_action( 'added_post_meta', array( $this, 'on_alt_written' ), 10, 4 );
		$loader->add_action( 'updated_post_meta', array( $this, 'on_alt_written' ), 10, 4 );
		$loader->add_action( 'shutdown', array( $this, 'record_pending' ), 0, 0 );
	}

	/**
	 * Records an upload.
	 *
	 * @param int $post_id Attachment ID.
	 *
	 * @return void
	 */
	public function on_upload( $post_id ): void {
		$this->promote( (int) $post_id, 'media.uploaded' );
	}

	/**
	 * Collects changed attachment fields.
	 *
	 * @param int   $post_id Attachment ID.
	 * @param mixed $after   Attachment after.
	 * @param mixed $before  Attachment before.
	 *
	 * @return void
	 */
	public function on_update( $post_id, mixed $after = null, mixed $before = null ): void {
		if ( ! $after instanceof \WP_Post || ! $before instanceof \WP_Post ) {
			return;
		}

		$activity = $this->activity( $after );

		foreach ( self::FIELDS as $post_field => $field ) {
			$activity->change( $field, $before->$post_field, $after->$post_field );
		}

		unset( $post_id );
	}

	/**
	 * Records a deletion (the attachment still exists at this point).
	 *
	 * @param int $post_id Attachment ID.
	 *
	 * @return void
	 */
	public function on_delete( $post_id ): void {
		$this->promote( (int) $post_id, 'media.deleted' );
	}

	/**
	 * Records an image edit (crop, rotate, scale) as a pass-through filter.
	 *
	 * @param mixed  $override  Short-circuit value (returned unchanged).
	 * @param string $filename  File being saved.
	 * @param mixed  $image     Image editor.
	 * @param string $mime_type MIME type.
	 * @param int    $post_id   Attachment ID.
	 *
	 * @return mixed The unchanged $override.
	 */
	public function on_image_edit( $override, $filename, $image, $mime_type, $post_id ) {
		unset( $filename, $image, $mime_type );
		$this->promote( (int) $post_id, 'media.edited' );

		return $override;
	}

	/**
	 * Remembers the alt text before it is updated (filter pass-through).
	 *
	 * @param mixed  $check    Short-circuit value (returned unchanged).
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 *
	 * @return mixed The unchanged $check.
	 */
	public function capture_alt( $check, $post_id, $meta_key ) {
		$post_id = (int) $post_id;

		if ( self::ALT_KEY === $meta_key && ! array_key_exists( $post_id, $this->alt_before ) ) {
			$this->alt_before[ $post_id ] = metadata_exists( 'post', $post_id, self::ALT_KEY ) ? (string) get_post_meta( $post_id, self::ALT_KEY, true ) : null;
		}

		return $check;
	}

	/**
	 * Collects an alt text change.
	 *
	 * @param int    $meta_id    Meta row ID.
	 * @param int    $post_id    Attachment ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 *
	 * @return void
	 */
	public function on_alt_written( $meta_id, $post_id, $meta_key, $meta_value ): void {
		unset( $meta_id );
		$post = get_post( (int) $post_id );

		if ( self::ALT_KEY !== $meta_key || ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return;
		}

		$this->activity( $post )->change( 'alt', $this->alt_before[ $post->ID ] ?? null, $meta_value );
	}

	/**
	 * Records one event per attachment. Runs on shutdown (priority 0).
	 *
	 * @return void
	 */
	public function record_pending(): void {
		$activities       = $this->activities;
		$this->activities = array();
		$this->alt_before = array();

		foreach ( $activities as $activity ) {
			$this->record( $activity );
		}
	}

	/**
	 * Records one attachment's activity, unless it is an update without real changes.
	 *
	 * @param PostActivity $activity Activity.
	 *
	 * @return void
	 */
	private function record( PostActivity $activity ): void {
		$changes = array();

		foreach ( 'media.deleted' === $activity->kind() ? array() : $activity->fields() as $field => [ $old, $new ] ) {
			$change = $this->differ->compare( $field, $old, $new );

			if ( null !== $change ) {
				$changes[] = $change;
			}
		}

		if ( 'media.updated' === $activity->kind() && array() === $changes ) {
			return;
		}

		$post  = $activity->post();
		$file  = basename( (string) get_attached_file( $post->ID ) );
		$title = trim( $post->post_title );

		$this->recorder->record(
			new Event(
				$activity->kind(),
				object_type: 'attachment',
				object_id: (string) $post->ID,
				object_label: '' === $title ? $file : $title,
				context: array(
					'mime_type' => $post->post_mime_type,
					'file'      => $file,
				),
				changes: $changes
			)
		);
	}

	/**
	 * Raises an attachment's event type.
	 *
	 * @param int    $post_id Attachment ID.
	 * @param string $kind    Event type.
	 *
	 * @return void
	 */
	private function promote( int $post_id, string $kind ): void {
		$post = get_post( $post_id );

		if ( $post instanceof \WP_Post && 'attachment' === $post->post_type ) {
			$this->activity( $post )->promote( $kind );
		}
	}

	/**
	 * Returns (creating if needed) the activity of an attachment.
	 *
	 * @param \WP_Post $post Attachment.
	 *
	 * @return PostActivity Activity.
	 */
	private function activity( \WP_Post $post ): PostActivity {
		$this->activities[ $post->ID ] ??= new PostActivity( $post, self::RANK );
		$this->activities[ $post->ID ]->refresh( $post );

		return $this->activities[ $post->ID ];
	}
}
