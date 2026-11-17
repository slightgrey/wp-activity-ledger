<?php
/**
 * Navigation menu sensor.
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
 * Records classic menus: created, renamed, items added/removed, deleted, and changes to
 * the theme's menu locations. One event per menu and request, recorded at shutdown.
 *
 * The Menus screen re-saves every item on each save, so only additions (wp_add_nav_menu_item)
 * and removals (item deletion) count as item changes.
 */
final class MenuSensor implements SensorInterface {

	/**
	 * Per-menu activity: menu ID => [kind, name, old_name, added titles, removed titles].
	 *
	 * @var array<int, array{kind: string, name: string, old_name: string|null, added: array<int, string>, removed: array<int, string>}>
	 */
	private array $menus = array();

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
		$loader->add_action( 'wp_create_nav_menu', array( $this, 'on_created' ), 10, 1 );
		$loader->add_action( 'edit_terms', array( $this, 'capture_name' ), 10, 2 );
		$loader->add_action( 'wp_update_nav_menu', array( $this, 'on_updated' ), 10, 1 );
		$loader->add_action( 'wp_add_nav_menu_item', array( $this, 'on_item_added' ), 10, 2 );
		$loader->add_action( 'before_delete_post', array( $this, 'on_item_deleted' ), 10, 2 );
		$loader->add_action( 'pre_delete_term', array( $this, 'on_menu_deleted' ), 10, 2 );
		$loader->add_filter( 'pre_set_theme_mod_nav_menu_locations', array( $this, 'on_locations' ), 10, 2 );
		$loader->add_action( 'shutdown', array( $this, 'record_pending' ), 0, 0 );
	}

	/**
	 * Marks a menu as created.
	 *
	 * @param int $menu_id Menu (term) ID.
	 *
	 * @return void
	 */
	public function on_created( $menu_id ): void {
		$this->touch( (int) $menu_id, 'menu.created' );
	}

	/**
	 * Remembers a menu's name before a rename.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 *
	 * @return void
	 */
	public function capture_name( $term_id, $taxonomy ): void {
		if ( 'nav_menu' !== $taxonomy ) {
			return;
		}

		$term = get_term( (int) $term_id, 'nav_menu' );

		if ( $term instanceof \WP_Term ) {
			$this->touch( $term->term_id );
			$this->menus[ $term->term_id ]['old_name'] ??= $term->name;
		}
	}

	/**
	 * Marks a menu as updated (name changes are compared at shutdown).
	 *
	 * @param int $menu_id Menu ID.
	 *
	 * @return void
	 */
	public function on_updated( $menu_id ): void {
		$this->touch( (int) $menu_id );
	}

	/**
	 * Collects a newly added item.
	 *
	 * @param int $menu_id Menu ID.
	 * @param int $item_id Item (post) ID.
	 *
	 * @return void
	 */
	public function on_item_added( $menu_id, $item_id ): void {
		$this->touch( (int) $menu_id );
		$this->menus[ (int) $menu_id ]['added'][] = self::item_title( (int) $item_id );
	}

	/**
	 * Collects a removed item (before its post is deleted).
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $post    Post.
	 *
	 * @return void
	 */
	public function on_item_deleted( $post_id, mixed $post = null ): void {
		if ( ! $post instanceof \WP_Post || 'nav_menu_item' !== $post->post_type ) {
			return;
		}

		$menus = wp_get_object_terms( (int) $post_id, 'nav_menu', array( 'fields' => 'ids' ) );

		foreach ( is_array( $menus ) ? $menus : array() as $menu_id ) {
			$this->touch( (int) $menu_id );
			$this->menus[ (int) $menu_id ]['removed'][] = self::item_title( (int) $post_id );
		}
	}

	/**
	 * Marks a menu as deleted, while its name is still known.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 *
	 * @return void
	 */
	public function on_menu_deleted( $term_id, $taxonomy ): void {
		if ( 'nav_menu' === $taxonomy ) {
			$this->touch( (int) $term_id, 'menu.deleted' );
		}
	}

	/**
	 * Records changed menu locations (filter pass-through on the theme mod).
	 *
	 * @param mixed $value     New locations (location => menu ID).
	 * @param mixed $old_value Old locations.
	 *
	 * @return mixed The unchanged $value.
	 */
	public function on_locations( $value, $old_value ) {
		$change = $this->differ->compare( 'locations', self::location_names( $old_value ), self::location_names( $value ) );

		if ( null !== $change ) {
			$this->recorder->record( new Event( 'menu.locations_changed', object_type: 'menu_locations', object_label: (string) get_stylesheet(), changes: array( $change ) ) );
		}

		return $value;
	}

	/**
	 * Records one event per touched menu. Runs on shutdown (priority 0).
	 *
	 * @return void
	 */
	public function record_pending(): void {
		$menus       = $this->menus;
		$this->menus = array();

		foreach ( $menus as $menu_id => $menu ) {
			$this->record( $menu_id, $menu );
		}
	}

	/**
	 * Records one menu's activity.
	 *
	 * @param int                                                                                                              $menu_id Menu ID.
	 * @param array{kind: string, name: string, old_name: string|null, added: array<int, string>, removed: array<int, string>} $menu    Activity.
	 *
	 * @return void
	 */
	private function record( int $menu_id, array $menu ): void {
		$term    = get_term( $menu_id, 'nav_menu' );
		$name    = $term instanceof \WP_Term ? $term->name : $menu['name'];
		$changes = 'menu.deleted' === $menu['kind'] ? array() : $this->changes( $menu, $name );

		if ( 'menu.updated' === $menu['kind'] && array() === $changes ) {
			return;
		}

		$this->recorder->record(
			new Event( $menu['kind'], object_type: 'menu', object_id: (string) $menu_id, object_label: $name, changes: $changes )
		);
	}

	/**
	 * Builds the name and item changes of a menu.
	 *
	 * @param array{kind: string, name: string, old_name: string|null, added: array<int, string>, removed: array<int, string>} $menu Activity.
	 * @param string                                                                                                           $name Current name.
	 *
	 * @return array<int, Change> Changes.
	 */
	private function changes( array $menu, string $name ): array {
		$candidates = array(
			'menu.created' === $menu['kind'] ? null : $this->differ->compare( 'name', $menu['old_name'] ?? $name, $name ),
			array() === $menu['added'] ? null : $this->differ->compare( 'items_added', null, $menu['added'] ),
			array() === $menu['removed'] ? null : $this->differ->compare( 'items_removed', $menu['removed'], null ),
		);

		return array_values( array_filter( $candidates ) );
	}

	/**
	 * Starts tracking a menu, or raises its event type.
	 *
	 * @param int    $menu_id Menu ID.
	 * @param string $kind    Event type (created/deleted win over updated).
	 *
	 * @return void
	 */
	private function touch( int $menu_id, string $kind = 'menu.updated' ): void {
		if ( ! isset( $this->menus[ $menu_id ] ) ) {
			$term                    = get_term( $menu_id, 'nav_menu' );
			$this->menus[ $menu_id ] = array(
				'kind'     => 'menu.updated',
				'name'     => $term instanceof \WP_Term ? $term->name : "#$menu_id",
				'old_name' => null,
				'added'    => array(),
				'removed'  => array(),
			);
		}

		if ( 'menu.updated' !== $kind && 'menu.deleted' !== $this->menus[ $menu_id ]['kind'] ) {
			$this->menus[ $menu_id ]['kind'] = $kind;
		}
	}

	/**
	 * Returns the label of a menu item.
	 *
	 * @param int $item_id Item ID.
	 *
	 * @return string Label.
	 */
	private static function item_title( int $item_id ): string {
		$post = get_post( $item_id );

		if ( ! $post instanceof \WP_Post ) {
			return "#$item_id";
		}

		$item  = wp_setup_nav_menu_item( $post );
		$title = trim( (string) ( $item->title ?? '' ) );

		return '' === $title ? "#$item_id" : $title;
	}

	/**
	 * Maps menu locations to menu names.
	 *
	 * @param mixed $locations Location => menu ID.
	 *
	 * @return array<string, string> Location => menu name (unassigned locations left out).
	 */
	private static function location_names( mixed $locations ): array {
		$names = array();

		foreach ( is_array( $locations ) ? $locations : array() as $location => $menu_id ) {
			$term = (int) $menu_id > 0 ? get_term( (int) $menu_id, 'nav_menu' ) : null;

			if ( $term instanceof \WP_Term ) {
				$names[ (string) $location ] = $term->name;
			}
		}

		ksort( $names );

		return $names;
	}
}
