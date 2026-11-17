<?php
/**
 * Tests for the Recorder buffer.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Recording;

use WPActivityLedger\Database\EventRepository;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Plugin;
use WPActivityLedger\Recording\Recorder;
use WPActivityLedger\Settings\Settings;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * @covers \WPActivityLedger\Recording\Recorder
 */
final class RecorderTest extends IntegrationTestCase {

	/**
	 * Builds a fresh recorder with default settings.
	 *
	 * @return Recorder Recorder.
	 */
	private function recorder(): Recorder {
		return new Recorder( new EventRepository(), Plugin::instance()->types(), new Settings( array() ) );
	}

	/**
	 * Counts events with the given label.
	 *
	 * @param string $label Object label.
	 *
	 * @return int Count.
	 */
	private static function count_label( string $label ): int {
		return ( new EventRepository() )->query( array( 'search' => $label ) )['total'];
	}

	/**
	 * Nothing is stored before flush(); everything after, enriched with actor and message.
	 *
	 * @return void
	 */
	public function test_buffer_and_flush(): void {
		$user     = $this->as_user( 'editor' );
		$label    = 'wpalrec' . strtolower( wp_generate_password( 8, false ) );
		$recorder = $this->recorder();

		$recorder->record( new Event( 'post.updated', object_type: 'page', object_label: $label ) );
		$recorder->record( new Event( 'post.trashed', object_type: 'page', object_label: $label ) );

		$this->assertCount( 2, $recorder->pending() );
		$this->assertSame( 0, self::count_label( $label ) );

		$this->assertSame( 2, $recorder->flush() );
		$this->assertSame( array(), $recorder->pending() );

		$items = ( new EventRepository() )->query( array( 'search' => $label, 'orderby' => 'id', 'order' => 'asc' ) )['items'];
		$this->assertCount( 2, $items );
		$this->assertSame( $user->ID, $items[0]['user_id'] );
		$this->assertSame( $user->user_login, $items[0]['user_login'] );
		$this->assertSame( array( 'editor' ), $items[0]['user_roles'] );
		$this->assertSame( 'content', $items[0]['category'] );
		$this->assertSame( "Updated page \"$label\"", $items[0]['message'] );
		$this->assertSame( 1, $items[1]['severity'], 'post.trashed defaults to notice.' );
		$this->assertSame( '127.0.0.1', $items[0]['ip'] );
		$this->assertArrayHasKey( 'request', $items[0]['context'] );
		$this->assertSame( 0, $recorder->flush(), 'Second flush has nothing to do.' );
	}

	/**
	 * An explicit actor on the event wins over the current user; explicit severity wins too.
	 *
	 * @return void
	 */
	public function test_event_actor_and_severity_are_kept(): void {
		$this->as_user( 'editor' );
		$label    = 'wpalrec' . strtolower( wp_generate_password( 8, false ) );
		$recorder = $this->recorder();

		$recorder->record( new Event( 'auth.login', severity: 0, object_label: $label, user_id: 1 ) );
		$recorder->flush();

		$item = ( new EventRepository() )->query( array( 'search' => $label ) )['items'][0];
		$this->assertSame( 1, $item['user_id'] );
		$this->assertSame( 0, $item['severity'] );
	}

	/**
	 * Excluded roles are not buffered, and wpal_event_before_save can drop or change events.
	 *
	 * @return void
	 */
	public function test_policy_and_before_save_filter(): void {
		$this->as_user( 'editor' );
		$recorder = new Recorder( new EventRepository(), Plugin::instance()->types(), new Settings( array( 'logged_roles' => array( 'administrator' ) ) ) );
		$recorder->record( new Event( 'post.updated' ) );
		$this->assertSame( array(), $recorder->pending(), 'Editors are not logged.' );

		$drop = static fn( Event $e ) => 'post.trashed' === $e->event_type ? false : $e->with( array( 'object_label' => 'filtered' ) );
		add_filter( 'wpal_event_before_save', $drop );

		$recorder = $this->recorder();
		$recorder->record( new Event( 'post.trashed' ) );
		$recorder->record( new Event( 'post.updated' ) );

		remove_filter( 'wpal_event_before_save', $drop );

		$this->assertCount( 1, $recorder->pending() );
		$this->assertSame( 'filtered', $recorder->pending()[0]->object_label );
	}

	/**
	 * record_now() stores immediately, together with earlier buffered events.
	 *
	 * @return void
	 */
	public function test_record_now_stores_immediately(): void {
		$this->as_user( 'administrator' );
		$label    = 'wpalnow' . strtolower( wp_generate_password( 8, false ) );
		$recorder = $this->recorder();

		$recorder->record( new Event( 'post.updated', object_label: $label ) );
		$recorder->record_now( new Event( 'system.logs_purged', object_label: $label ) );

		$this->assertSame( array(), $recorder->pending() );
		$this->assertSame( 2, self::count_label( $label ) );
	}

	/**
	 * The plugin's recorder flushes on shutdown.
	 *
	 * @return void
	 */
	public function test_shutdown_hook_is_registered(): void {
		$this->assertSame( 1, has_action( 'shutdown', array( Plugin::instance()->recorder(), 'flush' ) ) );
	}
}
