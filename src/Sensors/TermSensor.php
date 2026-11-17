<?php
/**
 * Taxonomy term sensor.
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
 * Records term creation, edits (name/slug/parent/description diff) and deletion.
 *
 * Menus (nav_menu) have their own sensor; block-theme internals are skipped.
 */
final class TermSensor implements SensorInterface {

	/**
	 * Taxonomies not handled here.
	 */
	private const SKIP_TAXONOMIES = array( 'nav_menu', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category' );

	/**
	 * Term fields compared on edit.
	 */
	private const FIELDS = array( 'name', 'slug', 'parent', 'description' );

	/**
	 * Terms as they were before an edit: term ID => fields.
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
		$loader->add_action( 'created_term', array( $this, 'on_created' ), 10, 3 );
		$loader->add_action( 'edit_terms', array( $this, 'capture_before' ), 10, 2 );
		$loader->add_action( 'edited_term', array( $this, 'on_edited' ), 10, 3 );
		$loader->add_action( 'delete_term', array( $this, 'on_deleted' ), 10, 4 );
	}

	/**
	 * Records a new term.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 *
	 * @return void
	 */
	public function on_created( $term_id, $tt_id, $taxonomy ): void {
		unset( $tt_id );
		$term = get_term( (int) $term_id, (string) $taxonomy );

		if ( $term instanceof \WP_Term && self::tracks( $term->taxonomy ) ) {
			$this->record( 'term.created', $term );
		}
	}

	/**
	 * Remembers a term before it is edited.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 *
	 * @return void
	 */
	public function capture_before( $term_id, $taxonomy ): void {
		$term = get_term( (int) $term_id, (string) $taxonomy );

		if ( $term instanceof \WP_Term ) {
			$this->before[ $term->term_id ] = $term->to_array();
		}
	}

	/**
	 * Records an edit when a compared field changed.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 *
	 * @return void
	 */
	public function on_edited( $term_id, $tt_id, $taxonomy ): void {
		unset( $tt_id );
		clean_term_cache( (int) $term_id, (string) $taxonomy );

		$term   = get_term( (int) $term_id, (string) $taxonomy );
		$before = $this->before[ (int) $term_id ] ?? null;

		unset( $this->before[ (int) $term_id ] );

		if ( ! $term instanceof \WP_Term || null === $before || ! self::tracks( $term->taxonomy ) ) {
			return;
		}

		$changes = $this->changes( $before, $term );

		if ( array() !== $changes ) {
			$this->record( 'term.updated', $term, $changes );
		}
	}

	/**
	 * Records a deletion.
	 *
	 * @param int    $term_id      Term ID.
	 * @param int    $tt_id        Term taxonomy ID.
	 * @param string $taxonomy     Taxonomy.
	 * @param mixed  $deleted_term Copy of the deleted term.
	 *
	 * @return void
	 */
	public function on_deleted( $term_id, $tt_id, $taxonomy, mixed $deleted_term = null ): void {
		unset( $term_id, $tt_id, $taxonomy );

		if ( $deleted_term instanceof \WP_Term && self::tracks( $deleted_term->taxonomy ) ) {
			$this->record( 'term.deleted', $deleted_term );
		}
	}

	/**
	 * Compares the edited fields; parents are shown by name.
	 *
	 * @param array<string, mixed> $before Term before.
	 * @param \WP_Term             $term   Term after.
	 *
	 * @return array<int, Change> Changes.
	 */
	private function changes( array $before, \WP_Term $term ): array {
		$changes = array();

		foreach ( self::FIELDS as $field ) {
			$old = $before[ $field ] ?? null;
			$new = $term->$field;

			if ( 'parent' === $field ) {
				$old = self::term_name( (int) $old, $term->taxonomy );
				$new = self::term_name( (int) $new, $term->taxonomy );
			}

			$change = $this->differ->compare( $field, $old, $new );

			if ( null !== $change ) {
				$changes[] = $change;
			}
		}

		return $changes;
	}

	/**
	 * Records an event about a term.
	 *
	 * @param string             $type    Event type.
	 * @param \WP_Term           $term    Term.
	 * @param array<int, Change> $changes Changes.
	 *
	 * @return void
	 */
	private function record( string $type, \WP_Term $term, array $changes = array() ): void {
		$taxonomy = get_taxonomy( $term->taxonomy );

		$this->recorder->record(
			new Event(
				$type,
				object_type: 'term',
				object_id: (string) $term->term_id,
				object_label: $term->name,
				context: array(
					'taxonomy'      => false === $taxonomy ? $term->taxonomy : strtolower( (string) $taxonomy->labels->singular_name ),
					'taxonomy_slug' => $term->taxonomy,
				),
				changes: $changes
			)
		);
	}

	/**
	 * Returns a term's name, or null for "no parent".
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 *
	 * @return string|null Name.
	 */
	private static function term_name( int $term_id, string $taxonomy ): ?string {
		if ( $term_id <= 0 ) {
			return null;
		}

		$term = get_term( $term_id, $taxonomy );

		return $term instanceof \WP_Term ? $term->name : "#$term_id";
	}

	/**
	 * Tells whether a taxonomy is handled here.
	 *
	 * @param string $taxonomy Taxonomy.
	 *
	 * @return bool True when tracked.
	 */
	private static function tracks( string $taxonomy ): bool {
		return ! in_array( $taxonomy, self::SKIP_TAXONOMIES, true );
	}
}
