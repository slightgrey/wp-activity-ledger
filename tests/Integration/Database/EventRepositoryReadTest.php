<?php
/**
 * Tests for EventRepository reads.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Database;

use WPActivityLedger\Database\EventRepository;
use WPActivityLedger\Domain\Change;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * Seeds 30 tagged events and reads them back. The tag search keeps other rows out.
 *
 * @covers \WPActivityLedger\Database\EventRepository
 * @covers \WPActivityLedger\Database\RowHydrator
 */
final class EventRepositoryReadTest extends IntegrationTestCase {

	/**
	 * Unique tag for this test's events.
	 *
	 * @var string
	 */
	private string $tag;

	/**
	 * Repository.
	 *
	 * @var EventRepository
	 */
	private EventRepository $repo;

	/**
	 * Seeds 30 events: 10 auth by user 1, 20 content by user 2 (every 5th with changes).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->tag  = 'wpalread' . strtolower( wp_generate_password( 8, false ) );
		$this->repo = new EventRepository();
		$events     = array();

		for ( $i = 0; $i < 30; $i++ ) {
			$auth     = $i < 10;
			$events[] = new Event(
				$auth ? 'auth.login' : 'post.updated',
				category: $auth ? 'auth' : 'content',
				severity: 0 === $i % 3 ? 2 : 0,
				object_type: $auth ? 'user' : 'post',
				object_id: (string) $i,
				object_label: "{$this->tag} item $i",
				message: "{$this->tag} message $i",
				context: array( 'n' => $i ),
				changes: ! $auth && 0 === $i % 5 ? array( new Change( 'post_title', "t$i", array( 'new' => $i ) ) ) : array(),
				user_id: $auth ? 1 : 2,
				user_roles: $auth ? array( 'administrator' ) : array( 'editor', 'author' ),
				created_at: gmdate( 'Y-m-d H:i:s', strtotime( '2026-01-01 00:00:00 UTC' ) + $i * 3600 )
			);
		}

		$this->repo->insert_many( $events );
	}

	/**
	 * Returns filters limited to this test's events.
	 *
	 * @param array<string, mixed> $extra Extra filters.
	 *
	 * @return array<string, mixed> Filters.
	 */
	private function filters( array $extra = array() ): array {
		return array_merge( array( 'search' => $this->tag ), $extra );
	}

	/**
	 * Paging returns the right slices and totals, newest first.
	 *
	 * @return void
	 */
	public function test_query_paginates(): void {
		$page1 = $this->repo->query( $this->filters( array( 'per_page' => 12 ) ) );
		$page3 = $this->repo->query( $this->filters( array( 'per_page' => 12, 'page' => 3 ) ) );

		$this->assertSame( 30, $page1['total'] );
		$this->assertCount( 12, $page1['items'] );
		$this->assertCount( 6, $page3['items'] );
		$this->assertSame( '29', $page1['items'][0]['object_id'] );
		$this->assertSame( '0', end( $page3['items'] )['object_id'] );
	}

	/**
	 * Filters narrow the results; rows are typed and carry change counts.
	 *
	 * @return void
	 */
	public function test_query_filters_and_typing(): void {
		$this->assertSame( 10, $this->repo->query( $this->filters( array( 'category' => 'auth' ) ) )['total'] );
		$this->assertSame( 20, $this->repo->query( $this->filters( array( 'user' => 2 ) ) )['total'] );
		$this->assertSame( 20, $this->repo->query( $this->filters( array( 'role' => 'author' ) ) )['total'] );
		$this->assertSame( 10, $this->repo->query( $this->filters( array( 'severity' => 'warning' ) ) )['total'] );
		$this->assertSame( 10, $this->repo->query( $this->filters( array( 'from' => '2026-01-01 20:00:00', 'to' => '2026-01-02' ) ) )['total'] );
		$this->assertSame( 2, $this->repo->query( $this->filters( array( 'from' => '2026-01-01 01:00:00', 'to' => '2026-01-01 02:00:00' ) ) )['total'] );
		$this->assertSame( 1, $this->repo->query( $this->filters( array( 'type' => 'post.updated', 'object_id' => '15' ) ) )['total'] );

		$item = $this->repo->query( $this->filters( array( 'object_id' => '15' ) ) )['items'][0];
		$this->assertSame( array( 'editor', 'author' ), $item['user_roles'] );
		$this->assertSame( array( 'n' => 15 ), $item['context'] );
		$this->assertSame( 1, $item['change_count'] );
		$this->assertIsInt( $item['id'] );
	}

	/**
	 * find() returns the event with decoded changes, or null.
	 *
	 * @return void
	 */
	public function test_find(): void {
		$id    = $this->repo->query( $this->filters( array( 'object_id' => '20' ) ) )['items'][0]['id'];
		$event = $this->repo->find( $id );

		$this->assertNotNull( $event );
		$this->assertSame(
			array(
				array(
					'field'     => 'post_title',
					'old'       => 't20',
					'new'       => array( 'new' => 20 ),
					'truncated' => false,
				),
			),
			$event['changes']
		);
		$this->assertNull( $this->repo->find( PHP_INT_MAX ) );
	}

	/**
	 * iterate() yields every row in batches, with changes.
	 *
	 * @return void
	 */
	public function test_iterate_yields_everything_in_batches(): void {
		$object_ids   = array();
		$row_ids      = array();
		$with_changes = 0;

		foreach ( $this->repo->iterate( $this->filters(), 7 ) as $event ) {
			$object_ids[]  = $event['object_id'];
			$row_ids[]     = $event['id'];
			$with_changes += count( $event['changes'] );
		}

		$sorted = $row_ids;
		rsort( $sorted );

		$this->assertCount( 30, $object_ids );
		$this->assertCount( 30, array_unique( $object_ids ) );
		$this->assertSame( $sorted, $row_ids, 'Rows must come newest (highest ID) first.' );
		$this->assertSame( 4, $with_changes );
		$this->assertCount( 30, iterator_to_array( $this->repo->iterate( $this->filters(), 7 ) ), 'Keys must not repeat across batches.' );
	}
}
