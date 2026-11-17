<?php
/**
 * Tests for PostSensor status transitions and deletion.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Sensors;

/**
 * @covers \WPActivityLedger\Sensors\PostSensor
 * @covers \WPActivityLedger\Sensors\PostActivity
 */
final class PostStatusTest extends PostSensorTestCase {

	/**
	 * Publish, trash, restore and delete give four distinct events.
	 *
	 * @return void
	 */
	public function test_lifecycle(): void {
		$this->as_user( 'editor' );
		$id = self::create_post();

		wp_publish_post( $id );
		self::settle();
		wp_trash_post( $id );
		self::settle();
		wp_untrash_post( $id );
		self::settle();
		wp_delete_post( $id, true );

		$events = self::events_for( $id );

		$this->assertSame( array( 'post.created', 'post.published', 'post.trashed', 'post.restored', 'post.deleted' ), array_column( $events, 'event_type' ) );
		$this->assertSame( 'Permanently deleted post "Original title"', $events[4]['message'] );
		$this->assertSame( array(), $events[4]['changes'] );
		$this->assertNotContains( 'post_name', array_column( $events[2]['changes'], 'field' ), 'Trash slug rename is not a change.' );
	}

	/**
	 * Publishing and editing in one save is a single "published" event with the edits.
	 *
	 * @return void
	 */
	public function test_publish_and_edit_merge(): void {
		$this->as_user( 'editor' );
		$id = self::create_post();

		wp_update_post(
			array(
				'ID'          => $id,
				'post_title'  => 'Edited on publish',
				'post_status' => 'publish',
			)
		);

		$events = self::events_for( $id );

		$this->assertSame( array( 'post.created', 'post.published' ), array_column( $events, 'event_type' ) );
		$this->assertEqualsCanonicalizing( array( 'post_title', 'post_status', 'post_name' ), array_column( $events[1]['changes'], 'field' ), 'Publishing also generates the slug.' );
		$this->assertSame( 'Published post "Edited on publish"', $events[1]['message'] );
	}

	/**
	 * Scheduling and unpublishing have their own types and context.
	 *
	 * @return void
	 */
	public function test_schedule_and_unpublish(): void {
		$this->as_user( 'editor' );
		$id = self::create_post();

		wp_update_post(
			array(
				'ID'            => $id,
				'post_status'   => 'future',
				'post_date'     => '2099-01-01 10:00:00',
				'post_date_gmt' => '2099-01-01 10:00:00',
				'edit_date'     => true, // Without it, wp_update_post() resets a draft's date to now.
			)
		);
		self::settle();
		wp_publish_post( $id ); // What cron does when a scheduled post's time comes.
		self::settle();
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);

		$events = self::events_for( $id );

		$this->assertSame( array( 'post.created', 'post.scheduled', 'post.published', 'post.unpublished' ), array_column( $events, 'event_type' ) );
		$this->assertSame( 'Scheduled post "Original title" for 2099-01-01 10:00:00', $events[1]['message'] );
		$this->assertSame( 'Unpublished post "Original title" (now draft)', $events[3]['message'] );
	}
}
