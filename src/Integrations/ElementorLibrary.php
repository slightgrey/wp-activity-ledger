<?php
/**
 * Elementor template library and site kit activity.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Integrations;

use WPActivityLedger\Diff\Differ;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Loader;
use WPActivityLedger\Recording\Recorder;

/**
 * Records templates (elementor_library posts) created, imported, saved, trashed, restored and
 * deleted, and Site Settings saves of the kit with a diff of its settings (global colours,
 * fonts, layout…).
 *
 * Created events are recorded at shutdown, when the template type meta is in place; the kit
 * Elementor creates for itself is not a template and is skipped.
 */
final class ElementorLibrary {

	/**
	 * Template type meta key.
	 */
	private const TYPE_META = '_elementor_template_type';

	/**
	 * Kit (Site Settings) meta key.
	 */
	private const SETTINGS_META = '_elementor_page_settings';

	/**
	 * State before a save, by post ID: kit settings and element count.
	 *
	 * @var array<int, array{settings: mixed, elements: int}>
	 */
	private array $before = array();

	/**
	 * Saved library items, by post ID.
	 *
	 * @var array<int, true>
	 */
	private array $saved = array();

	/**
	 * Library items created in this request, by post ID.
	 *
	 * @var array<int, true>
	 */
	private array $created = array();

	/**
	 * Creates the watcher.
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
		$loader->add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		$loader->add_action( 'trashed_post', array( $this, 'on_trashed' ), 10, 1 );
		$loader->add_action( 'untrashed_post', array( $this, 'on_untrashed' ), 10, 1 );
		$loader->add_action( 'before_delete_post', array( $this, 'on_delete' ), 10, 2 );
	}

	/**
	 * Remembers the kit settings and element count before a library item is saved in Elementor.
	 *
	 * @param \WP_Post $post Library post.
	 *
	 * @return void
	 */
	public function before_save( \WP_Post $post ): void {
		$this->before[ $post->ID ] ??= array(
			'settings' => get_post_meta( $post->ID, self::SETTINGS_META, true ),
			'elements' => Elementor::element_count( $post->ID ),
		);
	}

	/**
	 * Queues a library item saved in Elementor.
	 *
	 * @param \WP_Post $post Library post.
	 *
	 * @return void
	 */
	public function after_save( \WP_Post $post ): void {
		if ( array_key_exists( $post->ID, $this->before ) ) {
			$this->saved[ $post->ID ] = true;
		}
	}

	/**
	 * Marks a template created when its post leaves the new/auto-draft state.
	 *
	 * @param string $new_status New status.
	 * @param string $old_status Old status.
	 * @param mixed  $post       Post.
	 *
	 * @return void
	 */
	public function on_transition( $new_status, $old_status, mixed $post = null ): void {
		$is_creation = in_array( $old_status, array( 'new', 'auto-draft' ), true ) && ! in_array( $new_status, array( 'new', 'auto-draft', 'inherit', 'trash' ), true );

		if ( $is_creation && $post instanceof \WP_Post && Elementor::LIBRARY_TYPE === $post->post_type ) {
			$this->created[ $post->ID ] = true;
		}
	}

	/**
	 * Records a template moved to the trash.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	public function on_trashed( $post_id ): void {
		$this->record_simple( (int) $post_id, 'elementor.template_trashed' );
	}

	/**
	 * Records a template restored from the trash.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	public function on_untrashed( $post_id ): void {
		$this->record_simple( (int) $post_id, 'elementor.template_restored' );
	}

	/**
	 * Records a permanently deleted template (auto-drafts are skipped).
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $post    Post.
	 *
	 * @return void
	 */
	public function on_delete( $post_id, mixed $post = null ): void {
		$post = $post instanceof \WP_Post ? $post : get_post( (int) $post_id );

		if ( $post instanceof \WP_Post && 'auto-draft' !== $post->post_status ) {
			$this->record_simple( $post->ID, 'elementor.template_deleted' );
		}
	}

	/**
	 * Records created templates and saved templates/kits (called by the integration on shutdown).
	 *
	 * @return void
	 */
	public function record_pending(): void {
		$created       = $this->created;
		$saved         = $this->saved;
		$before        = $this->before;
		$this->created = array();
		$this->saved   = array();
		$this->before  = array();

		foreach ( array_keys( $created ) as $post_id ) {
			if ( 'kit' !== self::template_type( $post_id ) ) {
				$this->record_simple( $post_id, 'elementor.template_created' );
			}
		}

		foreach ( array_keys( array_diff_key( $saved, $created ) ) as $post_id ) {
			$this->record_save( $post_id, $before[ $post_id ] );
		}
	}

	/**
	 * Records a template or kit saved in Elementor.
	 *
	 * @param int                                   $post_id Post ID.
	 * @param array{settings: mixed, elements: int} $before  State before the save.
	 *
	 * @return void
	 */
	private function record_save( int $post_id, array $before ): void {
		if ( 'kit' === self::template_type( $post_id ) ) {
			$changes = $this->differ->diff_value( 'site_settings', $before['settings'], get_post_meta( $post_id, self::SETTINGS_META, true ) );
			$this->record_simple( $post_id, 'elementor.kit_updated', $changes, array( 'count' => count( $changes ) ) );

			return;
		}

		$elements = Elementor::element_count( $post_id );
		$change   = $this->differ->compare( 'elements', $before['elements'], $elements );

		$this->record_simple( $post_id, 'elementor.template_updated', null === $change ? array() : array( $change ), array( 'elements' => $elements ) );
	}

	/**
	 * Records an event about a library item.
	 *
	 * @param int                                         $post_id Post ID.
	 * @param string                                      $type    Event type.
	 * @param array<int, \WPActivityLedger\Domain\Change> $changes Changes.
	 * @param array<string, mixed>                        $context Extra context.
	 *
	 * @return void
	 */
	private function record_simple( int $post_id, string $type, array $changes = array(), array $context = array() ): void {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || Elementor::LIBRARY_TYPE !== $post->post_type ) {
			return;
		}

		$this->recorder->record(
			new Event(
				$type,
				object_type: Elementor::LIBRARY_TYPE,
				object_id: (string) $post->ID,
				object_label: '' === $post->post_title ? '#' . $post->ID : $post->post_title,
				context: array_merge( array( 'template_type' => self::template_type( $post->ID ) ), $context ),
				changes: $changes
			)
		);
	}

	/**
	 * Returns a library item's template type ("page", "section", "header", "kit"…).
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string Template type.
	 */
	private static function template_type( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::TYPE_META, true );
	}
}
