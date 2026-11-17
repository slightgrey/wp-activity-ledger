<?php
/**
 * Tests for EventRepository writes.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Database;

use WPActivityLedger\Database\EventRepository;
use WPActivityLedger\Database\Schema;
use WPActivityLedger\Domain\Change;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

// phpcs:disable WordPress.DB -- Tests inspect the custom tables directly.

/**
 * @covers \WPActivityLedger\Database\EventRepository
 */
final class EventRepositoryWriteTest extends IntegrationTestCase {

	/**
	 * Builds a test event.
	 *
	 * @param string             $label   Object label (used to find the row again).
	 * @param array<int, Change> $changes Changes.
	 *
	 * @return Event Event.
	 */
	private static function event( string $label, array $changes = array() ): Event {
		return new Event(
			'post.updated',
			category: 'content',
			object_type: 'post',
			object_id: '1',
			object_label: $label,
			changes: $changes,
			created_at: gmdate( 'Y-m-d H:i:s' )
		);
	}

	/**
	 * Events with and without changes are stored, and each change points at its own event.
	 *
	 * @return void
	 */
	public function test_insert_many_links_changes_to_events(): void {
		global $wpdb;

		$tag    = 'wpal-test-' . wp_generate_password( 8, false );
		$events = array(
			self::event( "$tag-a", array( new Change( 'post_title', 'A1', 'A2' ), new Change( 'post_status', 'draft', 'publish' ) ) ),
			self::event( "$tag-plain" ),
			self::event( "$tag-b", array( new Change( 'post_title', 'B1', 'B2' ), new Change( 'tags', null, array( 'x' ) ) ) ),
			self::event( "$tag-c", array( new Change( 'post_excerpt', 'C1', null, true ), new Change( 'menu_order', 1, 2 ) ) ),
		);

		$this->assertSame( 4, ( new EventRepository() )->insert_many( $events ) );

		$events_table  = Schema::events_table();
		$changes_table = Schema::changes_table();
		$rows          = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.object_label, c.field, c.old_value, c.new_value, c.truncated FROM $events_table e JOIN $changes_table c ON c.event_id = e.id WHERE e.object_label LIKE %s ORDER BY c.id",
				$wpdb->esc_like( $tag ) . '%'
			),
			ARRAY_A
		);

		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $events_table WHERE object_label LIKE %s", $wpdb->esc_like( $tag ) . '%' ) );

		$this->assertSame( 4, $count );
		$this->assertCount( 6, $rows );

		$this->assertNotContains( "$tag-plain", array_column( $rows, 'object_label' ), 'The plain event must have no changes.' );

		foreach ( $rows as $row ) {
			$letter = substr( $row['object_label'], -1 );

			if ( null !== $row['old_value'] && 'post_title' === $row['field'] ) {
				$this->assertSame( strtoupper( $letter ) . '1', $row['old_value'], 'Change linked to the wrong event.' );
			}
		}

		$by_field = array_column( $rows, null, 'field' );
		$this->assertNull( $by_field['tags']['old_value'] );
		$this->assertSame( '["x"]', $by_field['tags']['new_value'] );
		$this->assertNull( $by_field['post_excerpt']['new_value'] );
		$this->assertSame( '1', $by_field['post_excerpt']['truncated'] );
	}

	/**
	 * More than one chunk of plain events is stored completely.
	 *
	 * @return void
	 */
	public function test_insert_many_chunks_large_batches(): void {
		global $wpdb;

		$tag    = 'wpal-bulk-' . wp_generate_password( 8, false );
		$events = array();

		for ( $i = 0; $i < 1203; $i++ ) {
			$events[] = self::event( "$tag-$i" );
		}

		$this->assertSame( 1203, ( new EventRepository() )->insert_many( $events ) );

		$table = Schema::events_table();
		$this->assertSame( 1203, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE object_label LIKE %s", $wpdb->esc_like( $tag ) . '%' ) ) );
	}

	/**
	 * An empty batch runs no query.
	 *
	 * @return void
	 */
	public function test_insert_many_empty(): void {
		global $wpdb;

		$before = $wpdb->num_queries;
		$this->assertSame( 0, ( new EventRepository() )->insert_many( array() ) );
		$this->assertSame( $before, $wpdb->num_queries );
	}
}
