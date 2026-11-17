<?php
/**
 * Tests for the MenuSensor.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Sensors;

use WPActivityLedger\Plugin;
use WPActivityLedger\Sensors\MenuSensor;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * @covers \WPActivityLedger\Sensors\MenuSensor
 */
final class MenuSensorTest extends IntegrationTestCase {

	/**
	 * Records what the menu sensor collected and flushes.
	 *
	 * @return void
	 */
	private static function settle(): void {
		$sensor = Plugin::instance()->core_sensors()['menus'];
		self::assertInstanceOf( MenuSensor::class, $sensor );
		$sensor->record_pending();
		Plugin::instance()->recorder()->flush();
	}

	/**
	 * Returns events about one menu with their changes, oldest first.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id   Object ID ('' = any).
	 *
	 * @return array<int, array<string, mixed>> Events.
	 */
	private static function events_for( string $object_type, string $object_id ): array {
		self::settle();

		$items = Plugin::instance()->repository()->query(
			array(
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'orderby'     => 'id',
				'order'       => 'asc',
			)
		)['items'];

		return array_map( static fn( array $i ): array => (array) Plugin::instance()->repository()->find( $i['id'] ), $items );
	}

	/**
	 * Adds a custom link item.
	 *
	 * @param int    $menu_id Menu ID.
	 * @param string $title   Label.
	 *
	 * @return int Item ID.
	 */
	private static function add_item( int $menu_id, string $title ): int {
		return (int) wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'  => $title,
				'menu-item-url'    => 'https://example.test/' . sanitize_title( $title ),
				'menu-item-status' => 'publish',
				'menu-item-type'   => 'custom',
			)
		);
	}

	/**
	 * Create, add/remove items and rename in one save, then delete.
	 *
	 * @return void
	 */
	public function test_lifecycle(): void {
		$this->as_user( 'administrator' );
		$name    = 'WPAL Main ' . wp_generate_password( 4, false );
		$menu_id = wp_create_nav_menu( $name );
		$this->assertIsInt( $menu_id );
		$home = self::add_item( $menu_id, 'Home' );
		self::settle();

		self::add_item( $menu_id, 'Contact' );
		wp_delete_post( $home, true );
		wp_update_nav_menu_object( $menu_id, array( 'menu-name' => "$name v2" ) );
		self::settle();

		wp_delete_nav_menu( $menu_id );

		$events = self::events_for( 'menu', (string) $menu_id );

		$this->assertSame( array( 'menu.created', 'menu.updated', 'menu.deleted' ), array_column( $events, 'event_type' ) );

		$fields = array_column( $events[1]['changes'], null, 'field' );
		$this->assertSame( $name, $fields['name']['old'] );
		$this->assertSame( "$name v2", $fields['name']['new'] );
		$this->assertSame( array( 'Contact' ), $fields['items_added']['new'] );
		$this->assertSame( array( 'Home' ), $fields['items_removed']['old'] );
		$this->assertSame( array(), $events[2]['changes'], 'Items removed with the menu are not listed.' );
	}

	/**
	 * Location changes are recorded by menu name.
	 *
	 * @return void
	 */
	public function test_locations(): void {
		$this->as_user( 'administrator' );
		$name    = 'WPAL Footer ' . wp_generate_password( 4, false );
		$menu_id = wp_create_nav_menu( $name );
		$this->assertIsInt( $menu_id );
		self::settle();

		$before = get_theme_mod( 'nav_menu_locations' );
		set_theme_mod( 'nav_menu_locations', array( 'wpal-footer' => $menu_id ) );

		$events = self::events_for( 'menu_locations', '' );
		$last   = end( $events );

		$this->assertNotFalse( $last );
		$this->assertSame( 'menu.locations_changed', $last['event_type'] );
		$this->assertSame( $name, $last['changes'][0]['new']['wpal-footer'] ?? null );

		set_theme_mod( 'nav_menu_locations', $before );
	}
}
