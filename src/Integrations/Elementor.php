<?php
/**
 * Elementor integration.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Integrations;

use WPActivityLedger\Diff\Differ;
use WPActivityLedger\Domain\Category;
use WPActivityLedger\Domain\Change;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Domain\EventType;
use WPActivityLedger\Domain\Severity;
use WPActivityLedger\Loader;
use WPActivityLedger\Recording\Recorder;
use WPActivityLedger\Sensors\OptionFilter;

/**
 * Records Elementor activity: documents (pages, posts) saved in the editor, the template
 * library and site kit (ElementorLibrary), and Elementor settings (ElementorSettingsWatcher).
 *
 * One save records one "elementor.document_saved" event with the element count and status
 * changes. The content sensor's "post.updated" for the same post in the same request is
 * merged into it, so a save is not logged twice. Templates and the site kit
 * (elementor_library) are left out of the content sensor.
 */
final class Elementor implements IntegrationInterface {

	/**
	 * Elementor's post type for templates and kits.
	 */
	public const LIBRARY_TYPE = 'elementor_library';

	/**
	 * State before a save, by post ID: element count and status.
	 *
	 * @var array<int, array{elements: int, status: string}>
	 */
	private array $before = array();

	/**
	 * Saved documents waiting to be recorded, by post ID.
	 *
	 * @var array<int, array{post: \WP_Post, type: string, elements: int, status: string}>
	 */
	private array $saved = array();

	/**
	 * Changes taken over from the content sensor's events, by post ID.
	 *
	 * @var array<int, array<int, Change>>
	 */
	private array $absorbed = array();

	/**
	 * Records templates and the site kit.
	 *
	 * @var ElementorLibrary
	 */
	private ElementorLibrary $library;

	/**
	 * Records Elementor settings.
	 *
	 * @var ElementorSettingsWatcher
	 */
	private ElementorSettingsWatcher $settings;

	/**
	 * Creates the integration.
	 *
	 * @param Recorder     $recorder Recorder.
	 * @param Differ       $differ   Differ.
	 * @param OptionFilter $noise    Option noise filter.
	 */
	public function __construct(
		private readonly Recorder $recorder,
		private readonly Differ $differ,
		OptionFilter $noise
	) {
		$this->library  = new ElementorLibrary( $recorder, $differ );
		$this->settings = new ElementorSettingsWatcher( $recorder, $differ, $noise );
	}

	/**
	 * Returns the slug.
	 *
	 * @return string Slug.
	 */
	public function slug(): string {
		return 'elementor';
	}

	/**
	 * Returns the name.
	 *
	 * @return string Name.
	 */
	public function name(): string {
		return 'Elementor';
	}

	/**
	 * Tells whether Elementor is active.
	 *
	 * @return bool True when active.
	 */
	public function is_available(): bool {
		return defined( 'ELEMENTOR_VERSION' );
	}

	/**
	 * Queues the hooks. The shutdown callback is registered after the core sensors', so the
	 * content sensor's events are absorbed before the Elementor events are recorded.
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_filter( 'wpal_post_sensor_skip_types', array( $this, 'skip_post_types' ), 10, 1 );
		$loader->add_action( 'elementor/document/before_save', array( $this, 'before_save' ), 10, 1 );
		$loader->add_action( 'elementor/document/after_save', array( $this, 'after_save' ), 10, 1 );
		$loader->add_filter( 'wpal_event_before_save', array( $this, 'absorb_post_update' ), 10, 1 );
		$loader->add_action( 'shutdown', array( $this, 'record_pending' ), 0, 0 );
		$this->library->register( $loader );
		$this->settings->register( $loader );
	}

	/**
	 * Returns the Elementor event types.
	 *
	 * @return array<int, EventType> Types.
	 */
	public function event_types(): array {
		$c = Category::Elementor;

		return array(
			new EventType( 'elementor.document_saved', $c, Severity::Info, __( 'Edited with Elementor', 'wp-activity-ledger' ), __( 'Edited {type_label} "{object}" with Elementor', 'wp-activity-ledger' ) ),
			new EventType( 'elementor.template_created', $c, Severity::Info, __( 'Template created', 'wp-activity-ledger' ), __( 'Created Elementor template "{object}"', 'wp-activity-ledger' ) ),
			new EventType( 'elementor.template_updated', $c, Severity::Info, __( 'Template updated', 'wp-activity-ledger' ), __( 'Edited Elementor template "{object}"', 'wp-activity-ledger' ) ),
			new EventType( 'elementor.template_trashed', $c, Severity::Notice, __( 'Template trashed', 'wp-activity-ledger' ), __( 'Moved Elementor template "{object}" to the trash', 'wp-activity-ledger' ) ),
			new EventType( 'elementor.template_restored', $c, Severity::Info, __( 'Template restored', 'wp-activity-ledger' ), __( 'Restored Elementor template "{object}" from the trash', 'wp-activity-ledger' ) ),
			new EventType( 'elementor.template_deleted', $c, Severity::Warning, __( 'Template deleted', 'wp-activity-ledger' ), __( 'Deleted Elementor template "{object}"', 'wp-activity-ledger' ) ),
			new EventType( 'elementor.kit_updated', $c, Severity::Notice, __( 'Site Settings changed', 'wp-activity-ledger' ), __( 'Changed {count} Elementor Site Setting(s)', 'wp-activity-ledger' ) ),
			new EventType( 'elementor.settings_changed', $c, Severity::Notice, __( 'Elementor settings changed', 'wp-activity-ledger' ), __( 'Changed {count} Elementor setting(s)', 'wp-activity-ledger' ) ),
			new EventType( 'elementor.conditions_changed', $c, Severity::Notice, __( 'Display conditions changed', 'wp-activity-ledger' ), __( 'Changed Elementor theme-builder display conditions', 'wp-activity-ledger' ) ),
		);
	}

	/**
	 * Adds the Elementor library to the content sensor's skip list.
	 *
	 * @param mixed $types Skipped post types.
	 *
	 * @return array<int, string> Skipped post types.
	 */
	public function skip_post_types( $types ): array {
		return array_values( array_unique( array_merge( array_map( 'strval', (array) $types ), array( self::LIBRARY_TYPE ) ) ) );
	}

	/**
	 * Remembers the element count and status before a document is saved.
	 *
	 * @param mixed $document Elementor document.
	 *
	 * @return void
	 */
	public function before_save( mixed $document = null ): void {
		$post = self::post_of( $document );

		if ( null !== $post && self::LIBRARY_TYPE === $post->post_type ) {
			$this->library->before_save( $post );
		} elseif ( null !== $post ) {
			$this->before[ $post->ID ] ??= array(
				'elements' => self::element_count( $post->ID ),
				'status'   => $post->post_status,
			);
		}
	}

	/**
	 * Queues a saved document for recording.
	 *
	 * @param mixed $document Elementor document.
	 *
	 * @return void
	 */
	public function after_save( mixed $document = null ): void {
		$post = self::post_of( $document );

		if ( null !== $post && self::LIBRARY_TYPE === $post->post_type ) {
			$this->library->after_save( $post );

			return;
		}

		if ( null === $post || ! array_key_exists( $post->ID, $this->before ) ) {
			return;
		}

		$this->saved[ $post->ID ]       ??= array(
			'post'     => $post,
			'type'     => method_exists( $document, 'get_name' ) ? (string) $document->get_name() : '',
			'elements' => $this->before[ $post->ID ]['elements'],
			'status'   => $this->before[ $post->ID ]['status'],
		);
		$this->saved[ $post->ID ]['post'] = get_post( $post->ID ) ?? $post;
	}

	/**
	 * Drops the content sensor's "post.updated" for a post saved in Elementor, keeping its changes.
	 *
	 * @param mixed $event Event about to be buffered.
	 *
	 * @return mixed The event, or null when it was merged into the Elementor event.
	 */
	public function absorb_post_update( mixed $event ): mixed {
		if ( ! $event instanceof Event || 'post.updated' !== $event->event_type || ! isset( $this->saved[ (int) $event->object_id ] ) ) {
			return $event;
		}

		$this->absorbed[ (int) $event->object_id ] = array_merge( $this->absorbed[ (int) $event->object_id ] ?? array(), $event->changes );

		return null;
	}

	/**
	 * Records saved documents, library and settings changes. Runs on shutdown (priority 0).
	 *
	 * @return void
	 */
	public function record_pending(): void {
		$saved          = $this->saved;
		$absorbed       = $this->absorbed;
		$this->saved    = array();
		$this->absorbed = array();
		$this->before   = array();

		foreach ( $saved as $post_id => $item ) {
			$this->record_save( $item, $absorbed[ $post_id ] ?? array() );
		}

		$this->library->record_pending();
		$this->settings->record_pending();
	}

	/**
	 * Records the event of one saved document.
	 *
	 * @param array{post: \WP_Post, type: string, elements: int, status: string} $item     Saved document.
	 * @param array<int, Change>                                                 $absorbed Changes from the content sensor.
	 *
	 * @return void
	 */
	private function record_save( array $item, array $absorbed ): void {
		$post     = $item['post'];
		$elements = self::element_count( $post->ID );
		$changes  = array_filter(
			array(
				$this->differ->compare( 'post_status', $item['status'], $post->post_status ),
				$this->differ->compare( 'elements', $item['elements'], $elements ),
			)
		);
		$labels   = get_post_type_object( $post->post_type );

		$this->recorder->record(
			new Event(
				'elementor.document_saved',
				object_type: $post->post_type,
				object_id: (string) $post->ID,
				object_label: '' === $post->post_title ? '#' . $post->ID : $post->post_title,
				context: array(
					'type_label'     => $labels instanceof \WP_Post_Type ? strtolower( (string) $labels->labels->singular_name ) : $post->post_type,
					'document_type'  => $item['type'],
					'status'         => $post->post_status,
					'elements'       => $elements,
					'elements_delta' => $elements - $item['elements'],
				),
				changes: array_merge( array_values( $changes ), self::without_status( $absorbed ) )
			)
		);
	}

	/**
	 * Removes status changes taken over from the content sensor (the Elementor event has its own).
	 *
	 * @param array<int, Change> $changes Changes.
	 *
	 * @return array<int, Change> Changes.
	 */
	private static function without_status( array $changes ): array {
		return array_values( array_filter( $changes, static fn( Change $change ): bool => 'post_status' !== $change->field ) );
	}

	/**
	 * Returns the post of a document that is recorded (not autosaves or revisions).
	 *
	 * @param mixed $document Elementor document.
	 *
	 * @return \WP_Post|null Post, or null when the document isn't recorded.
	 */
	private static function post_of( mixed $document ): ?\WP_Post {
		if ( ! is_object( $document ) || ! method_exists( $document, 'get_post' ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return null;
		}

		$post = $document->get_post();

		if ( ! $post instanceof \WP_Post || 'revision' === $post->post_type ) {
			return null;
		}

		return $post;
	}

	/**
	 * Counts the elements (sections, containers, columns, widgets) stored for a post.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return int Element count.
	 */
	public static function element_count( int $post_id ): int {
		$data     = get_post_meta( $post_id, '_elementor_data', true );
		$elements = is_string( $data ) ? json_decode( $data, true ) : $data;

		return is_array( $elements ) ? self::count_tree( $elements ) : 0;
	}

	/**
	 * Counts the elements in a tree of Elementor element arrays.
	 *
	 * @param array<mixed> $elements Elements.
	 *
	 * @return int Count.
	 */
	private static function count_tree( array $elements ): int {
		$count = 0;

		foreach ( $elements as $element ) {
			if ( is_array( $element ) ) {
				$count += 1 + self::count_tree( is_array( $element['elements'] ?? null ) ? $element['elements'] : array() );
			}
		}

		return $count;
	}
}
