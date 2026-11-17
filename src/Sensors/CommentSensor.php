<?php
/**
 * Comment moderation sensor.
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
 * Records comment moderation by staff: approve, unapprove, spam, trash, delete, edit.
 *
 * Visitors posting comments is not recorded; only logged-in users who can moderate.
 */
final class CommentSensor implements SensorInterface {

	/**
	 * Status after a transition => event type.
	 */
	private const STATUS_TYPES = array(
		'approved'   => 'comment.approved',
		'unapproved' => 'comment.unapproved',
		'spam'       => 'comment.spammed',
		'trash'      => 'comment.trashed',
	);

	/**
	 * Comment fields compared on edit: comment field => field name in the log.
	 */
	private const FIELDS = array(
		'comment_content'      => 'content',
		'comment_author'       => 'author',
		'comment_author_email' => 'author_email',
		'comment_author_url'   => 'author_url',
		'comment_date'         => 'date',
	);

	/**
	 * Comments as they were before an edit: comment ID => fields.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $before = array();

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
		$loader->add_action( 'transition_comment_status', array( $this, 'on_transition' ), 10, 3 );
		$loader->add_action( 'delete_comment', array( $this, 'on_delete' ), 10, 2 );
		$loader->add_filter( 'wp_update_comment_data', array( $this, 'capture_before' ), 10, 2 );
		$loader->add_action( 'edit_comment', array( $this, 'on_edit' ), 10, 1 );
	}

	/**
	 * Records a moderation status change.
	 *
	 * @param string $new_status New status.
	 * @param string $old_status Old status.
	 * @param mixed  $comment    Comment.
	 *
	 * @return void
	 */
	public function on_transition( $new_status, $old_status, mixed $comment = null ): void {
		$type = self::STATUS_TYPES[ (string) $new_status ] ?? null;

		if ( null === $type || $new_status === $old_status || ! $comment instanceof \WP_Comment || ! self::by_staff() ) {
			return;
		}

		$this->record( $type, $comment, array( 'old_status' => (string) $old_status ) );
	}

	/**
	 * Records a permanent deletion.
	 *
	 * @param int   $comment_id Comment ID.
	 * @param mixed $comment    Comment.
	 *
	 * @return void
	 */
	public function on_delete( $comment_id, mixed $comment = null ): void {
		$comment = $comment instanceof \WP_Comment ? $comment : get_comment( (int) $comment_id );

		if ( $comment instanceof \WP_Comment && self::by_staff() ) {
			$this->record( 'comment.deleted', $comment );
		}
	}

	/**
	 * Remembers a comment before it is edited (filter pass-through).
	 *
	 * @param mixed $data    New comment data (returned unchanged).
	 * @param mixed $comment Comment before the edit (array).
	 *
	 * @return mixed The unchanged $data.
	 */
	public function capture_before( $data, $comment = null ) {
		if ( is_array( $comment ) && isset( $comment['comment_ID'] ) ) {
			$this->before[ (int) $comment['comment_ID'] ] = $comment;
		}

		return $data;
	}

	/**
	 * Records an edit with its field changes.
	 *
	 * @param int $comment_id Comment ID.
	 *
	 * @return void
	 */
	public function on_edit( $comment_id ): void {
		$comment = get_comment( (int) $comment_id );
		$before  = $this->before[ (int) $comment_id ] ?? null;

		unset( $this->before[ (int) $comment_id ] );

		if ( ! $comment instanceof \WP_Comment || null === $before || ! self::by_staff() ) {
			return;
		}

		$changes = $this->changes( $before, $comment );

		if ( array() !== $changes ) {
			$this->record( 'comment.edited', $comment, array(), $changes );
		}
	}

	/**
	 * Compares the edited fields.
	 *
	 * @param array<string, mixed> $before  Comment before.
	 * @param \WP_Comment          $comment Comment after.
	 *
	 * @return array<int, Change> Changes.
	 */
	private function changes( array $before, \WP_Comment $comment ): array {
		$changes = array();

		foreach ( self::FIELDS as $field => $label ) {
			$change = $this->differ->compare( $label, $before[ $field ] ?? null, $comment->$field );

			if ( null !== $change ) {
				$changes[] = $change;
			}
		}

		return $changes;
	}

	/**
	 * Records an event about a comment.
	 *
	 * @param string               $type    Event type.
	 * @param \WP_Comment          $comment Comment.
	 * @param array<string, mixed> $context Extra context.
	 * @param array<int, Change>   $changes Changes.
	 *
	 * @return void
	 */
	private function record( string $type, \WP_Comment $comment, array $context = array(), array $changes = array() ): void {
		$post_title = get_the_title( (int) $comment->comment_post_ID );

		$this->recorder->record(
			new Event(
				$type,
				object_type: 'comment',
				object_id: (string) $comment->comment_ID,
				object_label: '' === $post_title ? '#' . $comment->comment_post_ID : $post_title,
				context: array_merge(
					array(
						'author'  => '' === $comment->comment_author ? __( 'anonymous', 'wp-activity-ledger' ) : $comment->comment_author,
						'post_id' => (int) $comment->comment_post_ID,
					),
					$context
				),
				changes: $changes
			)
		);
	}

	/**
	 * Tells whether the current user is staff who can moderate comments.
	 *
	 * @return bool True for moderators.
	 */
	private static function by_staff(): bool {
		return is_user_logged_in() && current_user_can( 'moderate_comments' );
	}
}
