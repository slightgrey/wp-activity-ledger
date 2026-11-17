<?php
/**
 * Tests for the TermSensor.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Sensors;

use WPActivityLedger\Plugin;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * @covers \WPActivityLedger\Sensors\TermSensor
 */
final class TermSensorTest extends IntegrationTestCase {

	/**
	 * Returns events about one term, oldest first, with changes.
	 *
	 * @param int $term_id Term ID.
	 *
	 * @return array<int, array<string, mixed>> Events.
	 */
	private static function events_for( int $term_id ): array {
		Plugin::instance()->recorder()->flush();

		$items = Plugin::instance()->repository()->query(
			array(
				'object_type' => 'term',
				'object_id'   => (string) $term_id,
				'orderby'     => 'id',
				'order'       => 'asc',
			)
		)['items'];

		return array_map( static fn( array $i ): array => (array) Plugin::instance()->repository()->find( $i['id'] ), $items );
	}

	/**
	 * Create, rename (with parent change) and delete a category.
	 *
	 * @return void
	 */
	public function test_lifecycle(): void {
		$this->as_user( 'editor' );
		$suffix = wp_generate_password( 5, false );
		$parent = wp_insert_term( "Parent $suffix", 'category' );
		$term   = wp_insert_term( "News $suffix", 'category' );
		$this->assertIsArray( $parent );
		$this->assertIsArray( $term );

		wp_update_term(
			(int) $term['term_id'],
			'category',
			array(
				'name'   => "Updates $suffix",
				'parent' => (int) $parent['term_id'],
			)
		);
		wp_update_term( (int) $term['term_id'], 'category', array( 'name' => "Updates $suffix" ) );
		wp_delete_term( (int) $term['term_id'], 'category' );

		$events = self::events_for( (int) $term['term_id'] );

		$this->assertSame( array( 'term.created', 'term.updated', 'term.deleted' ), array_column( $events, 'event_type' ), 'The no-op update is not recorded.' );
		$this->assertSame( "Created category \"News $suffix\"", $events[0]['message'] );

		$fields = array_column( $events[1]['changes'], null, 'field' );
		$this->assertSame( "News $suffix", $fields['name']['old'] );
		$this->assertSame( "Updates $suffix", $fields['name']['new'] );
		$this->assertNull( $fields['parent']['old'] );
		$this->assertSame( "Parent $suffix", $fields['parent']['new'] );
		$this->assertSame( "Deleted category \"Updates $suffix\"", $events[2]['message'] );
	}

	/**
	 * Menus (nav_menu terms) are not recorded here.
	 *
	 * @return void
	 */
	public function test_nav_menu_is_skipped(): void {
		$this->as_user( 'administrator' );
		$menu_id = wp_create_nav_menu( 'WPAL menu ' . wp_generate_password( 5, false ) );
		$this->assertIsInt( $menu_id );

		$this->assertSame( array(), self::events_for( $menu_id ) );
	}
}
