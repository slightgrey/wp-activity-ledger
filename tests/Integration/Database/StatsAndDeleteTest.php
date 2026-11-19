<?php
/**
 * Tests for EventStats and EventRepository deletes.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Database;

use WPActivityLedger\Database\EventRepository;
use WPActivityLedger\Database\EventStats;
use WPActivityLedger\Database\Schema;
use WPActivityLedger\Domain\Change;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

// phpcs:disable WordPress.DB -- Tests inspect the custom tables directly.

/**
 * Works on an emptied table (inside the rolled-back transaction) so totals are exact.
 *
 * @covers \WPActivityLedger\Database\EventStats
 * @covers \WPActivityLedger\Database\EventRepository
 */
final class StatsAndDeleteTest extends IntegrationTestCase {

	/**
	 * Repository.
	 *
	 * @var EventRepository
	 */
	private EventRepository $repo;

	/**
	 * Empties the tables (rolled back later) and seeds a known set of events.
	 *
	 * Day 1 (2030-03-01): 3 logins by user 1, 2 failed logins (user 0), 1 critical by user 2.
	 * Day 2 (2030-03-02): 4 post updates by user 2, each with one change.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		global $wpdb;

		parent::setUp();

		$wpdb->query( 'DELETE FROM ' . Schema::changes_table() );
		$wpdb->query( 'DELETE FROM ' . Schema::events_table() );

		$this->repo = new EventRepository();
		$events     = array();

		for ( $i = 0; $i < 3; $i++ ) {
			$events[] = self::event( 'auth.login', 'auth', 0, 1, "2030-03-01 0$i:00:00" );
		}

		$events[] = self::event( 'auth.login_failed', 'auth', 2, 0, '2030-03-01 05:00:00' );
		$events[] = self::event( 'auth.login_failed', 'auth', 2, 0, '2030-03-01 06:00:00' );
		$events[] = self::event( 'plugin.file_edited', 'plugin', 3, 2, '2030-03-01 23:30:00' );

		for ( $i = 0; $i < 4; $i++ ) {
			$events[] = self::event( 'post.updated', 'content', 0, 2, "2030-03-02 1$i:00:00", array( new Change( 'post_title', 'a', 'b' ) ) );
		}

		$this->repo->insert_many( $events );
	}

	/**
	 * Builds an event.
	 *
	 * @param string             $type     Type.
	 * @param string             $category Category.
	 * @param int                $severity Severity.
	 * @param int                $user_id  User.
	 * @param string             $time     UTC time.
	 * @param array<int, Change> $changes  Changes.
	 *
	 * @return Event Event.
	 */
	private static function event( string $type, string $category, int $severity, int $user_id, string $time, array $changes = array() ): Event {
		return new Event( $type, category: $category, severity: $severity, user_id: $user_id, user_login: 0 === $user_id ? '' : "user$user_id", changes: $changes, created_at: $time );
	}

	/**
	 * The summary counts match the seeded data.
	 *
	 * @return void
	 */
	public function test_summary(): void {
		$stats = ( new EventStats() )->summary( '2030-03-01 00:00:00', '2030-03-02 23:59:59' );

		$this->assertSame(
			array(
				'events'        => 10,
				'failed_logins' => 2,
				'critical'      => 1,
				'users'         => 2,
			),
			$stats['totals']
		);
		$this->assertSame(
			array(
				'user_id'    => 2,
				'user_login' => 'user2',
				'count'      => 5,
			),
			$stats['top_users'][0]
		);
		$this->assertSame(
			array(
				'event_type' => 'post.updated',
				'count'      => 4,
			),
			$stats['top_types'][0]
		);
		$this->assertCount( 1, $stats['recent_critical'] );
		$this->assertSame( 'plugin.file_edited', $stats['recent_critical'][0]['event_type'] );
	}

	/**
	 * Hiding System events drops every user-0 event, failed logins included.
	 *
	 * @return void
	 */
	public function test_summary_hides_system_events(): void {
		$this->repo->insert_many(
			array(
				self::event( 'media.uploaded', 'media', 0, 0, '2030-03-02 08:00:00' ),
				self::event( 'option.updated', 'settings', 1, 0, '2030-03-02 09:00:00' ),
				self::event( 'option.updated', 'settings', 1, 0, '2030-03-02 09:30:00' ),
			)
		);

		$stats  = new EventStats();
		$all    = $stats->summary( '2030-03-01 00:00:00', '2030-03-02 23:59:59' );
		$hidden = $stats->summary( '2030-03-01 00:00:00', '2030-03-02 23:59:59', 0, 'day', true );

		$this->assertSame( 13, $all['totals']['events'] );
		$this->assertSame( 8, $hidden['totals']['events'] );
		$this->assertSame( 0, $hidden['totals']['failed_logins'] );
		$this->assertSame( 1, $hidden['totals']['critical'] );

		// System (user 0) no longer ranks at all.
		$this->assertNotContains( 0, array_column( $hidden['top_users'], 'user_id' ) );
		$this->assertNotContains( 'media.uploaded', array_column( $hidden['top_types'], 'event_type' ) );
		$this->assertSame( 8, array_sum( array_column( $hidden['by_day'], 'count' ) ) );
		$this->assertSame( 13, array_sum( array_column( $stats->by_day( '2030-03-01 00:00:00', '2030-03-02 23:59:59', 0 ), 'count' ) ) );
	}

	/**
	 * Days are grouped in the site's timezone.
	 *
	 * @return void
	 */
	public function test_by_day_uses_offset(): void {
		$stats = new EventStats();
		$utc   = $stats->by_day( '2030-03-01 00:00:00', '2030-03-02 23:59:59', 0 );
		$plus1 = $stats->by_day( '2030-03-01 00:00:00', '2030-03-02 23:59:59', 3600 );

		$this->assertContains(
			array(
				'day'      => '2030-03-01',
				'category' => 'plugin',
				'count'    => 1,
			),
			$utc
		);
		$this->assertContains(
			array(
				'day'      => '2030-03-02',
				'category' => 'plugin',
				'count'    => 1,
			),
			$plus1,
			'23:30 UTC is the next day at UTC+1.'
		);
	}

	/**
	 * delete_where() removes matching events and their changes only.
	 *
	 * @return void
	 */
	public function test_delete_where(): void {
		global $wpdb;

		$this->assertSame( 4, $this->repo->delete_where( array( 'category' => 'content' ) ) );
		$this->assertSame( 6, $this->repo->count() );
		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::changes_table() ) );
		$this->assertSame( 6, $this->repo->delete_where( array() ) );
		$this->assertSame( 0, $this->repo->count() );
	}

	/**
	 * delete_older_than() respects the limit and the cut-off, and takes changes along.
	 *
	 * @return void
	 */
	public function test_delete_older_than(): void {
		global $wpdb;

		$this->assertSame( 4, $this->repo->delete_older_than( '2030-03-02 00:00:00', 4 ) );
		$this->assertSame( 2, $this->repo->delete_older_than( '2030-03-02 00:00:00', 100 ) );
		$this->assertSame( 0, $this->repo->delete_older_than( '2030-03-02 00:00:00', 100 ) );
		$this->assertSame( 4, $this->repo->count() );
		$this->assertSame( 4, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::changes_table() ) );
	}

	/**
	 * delete_beyond() keeps the newest rows, and orphaned changes can be cleaned.
	 *
	 * @return void
	 */
	public function test_delete_beyond_and_orphans(): void {
		global $wpdb;

		$this->assertSame( 3, $this->repo->delete_beyond( 7, 100 ) );
		$this->assertSame( 7, $this->repo->count() );
		$this->assertSame( 0, $this->repo->delete_beyond( 7, 100 ) );

		$wpdb->query( 'DELETE FROM ' . Schema::events_table() . " WHERE event_type = 'post.updated'" );
		$this->assertSame( 4, $this->repo->delete_orphan_changes() );
	}
}
