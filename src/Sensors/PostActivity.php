<?php
/**
 * What happened to one post during a request.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Sensors;

/**
 * Accumulates the changes and the most significant action for one post, so a request
 * that e.g. publishes and edits a post produces a single event.
 */
final class PostActivity {

	/**
	 * Event types from least to most significant.
	 */
	private const RANK = array(
		'post.updated'     => 0,
		'post.created'     => 1,
		'post.unpublished' => 2,
		'post.scheduled'   => 3,
		'post.published'   => 4,
		'post.restored'    => 5,
		'post.trashed'     => 6,
		'post.deleted'     => 7,
	);

	/**
	 * Most significant event type so far.
	 *
	 * @var string
	 */
	private string $kind;

	/**
	 * Changed fields: field => [first old value, latest new value].
	 *
	 * @var array<string, array{0: mixed, 1: mixed}>
	 */
	private array $fields = array();

	/**
	 * Extra context for the event.
	 *
	 * @var array<string, mixed>
	 */
	private array $context = array();

	/**
	 * Creates the activity.
	 *
	 * @param \WP_Post           $post The post (latest known state).
	 * @param array<string, int> $rank Event types from least (first, the default) to most significant.
	 */
	public function __construct( private \WP_Post $post, private readonly array $rank = self::RANK ) {
		$this->kind = (string) array_key_first( $rank );
	}

	/**
	 * Raises the event type when the new one is more significant.
	 *
	 * @param string $kind Event type, e.g. "post.published".
	 *
	 * @return void
	 */
	public function promote( string $kind ): void {
		if ( ( $this->rank[ $kind ] ?? -1 ) > ( $this->rank[ $this->kind ] ?? -1 ) ) {
			$this->kind = $kind;
		}
	}

	/**
	 * Adds a field change, keeping the first old value and the latest new value.
	 *
	 * @param string $field     Field.
	 * @param mixed  $old_value Old value.
	 * @param mixed  $new_value New value.
	 *
	 * @return void
	 */
	public function change( string $field, mixed $old_value, mixed $new_value ): void {
		$first = array_key_exists( $field, $this->fields ) ? $this->fields[ $field ][0] : $old_value;

		$this->fields[ $field ] = array( $first, $new_value );
	}

	/**
	 * Adds context values.
	 *
	 * @param array<string, mixed> $context Context.
	 *
	 * @return void
	 */
	public function add_context( array $context ): void {
		$this->context = array_merge( $this->context, $context );
	}

	/**
	 * Replaces the stored post with a newer state.
	 *
	 * @param \WP_Post $post Post.
	 *
	 * @return void
	 */
	public function refresh( \WP_Post $post ): void {
		$this->post = $post;
	}

	/**
	 * Returns the event type.
	 *
	 * @return string Type.
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Returns the collected fields.
	 *
	 * @return array<string, array{0: mixed, 1: mixed}> Field => [old, new].
	 */
	public function fields(): array {
		return $this->fields;
	}

	/**
	 * Returns the context.
	 *
	 * @return array<string, mixed> Context.
	 */
	public function context(): array {
		return $this->context;
	}

	/**
	 * Returns the post.
	 *
	 * @return \WP_Post Post.
	 */
	public function post(): \WP_Post {
		return $this->post;
	}
}
