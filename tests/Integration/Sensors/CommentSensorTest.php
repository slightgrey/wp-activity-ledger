<?php
/**
 * Tests for the CommentSensor.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Sensors;

use WPActivityLedger\Plugin;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * @covers \WPActivityLedger\Sensors\CommentSensor
 */
final class CommentSensorTest extends IntegrationTestCase {

	/**
	 * Returns events about one comment, oldest first.
	 *
	 * @param int $comment_id Comment ID.
	 *
	 * @return array<int, array<string, mixed>> Events.
	 */
	private static function events_for( int $comment_id ): array {
		Plugin::instance()->recorder()->flush();

		$items = Plugin::instance()->repository()->query(
			array(
				'object_type' => 'comment',
				'object_id'   => (string) $comment_id,
				'orderby'     => 'id',
				'order'       => 'asc',
			)
		)['items'];

		return array_map( static fn( array $i ): array => (array) Plugin::instance()->repository()->find( $i['id'] ), $items );
	}

	/**
	 * Creates a pending comment on a new post.
	 *
	 * @return int Comment ID.
	 */
	private static function comment(): int {
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Commented post',
				'post_status' => 'publish',
			)
		);

		return (int) wp_insert_comment(
			array(
				'comment_post_ID'  => $post_id,
				'comment_author'   => 'Visitor',
				'comment_content'  => 'Nice post',
				'comment_approved' => 0,
			)
		);
	}

	/**
	 * Staff approve, spam, trash, delete and edit actions are recorded.
	 *
	 * @return void
	 */
	public function test_moderation_by_staff(): void {
		$this->as_user( 'editor' );
		$id = self::comment();

		wp_set_comment_status( $id, 'approve' );
		wp_update_comment(
			array(
				'comment_ID'      => $id,
				'comment_content' => 'Nice post!',
			)
		);
		wp_spam_comment( $id );
		wp_trash_comment( $id );
		wp_delete_comment( $id, true );

		$events = self::events_for( $id );

		$this->assertSame( array( 'comment.approved', 'comment.edited', 'comment.spammed', 'comment.trashed', 'comment.deleted' ), array_column( $events, 'event_type' ) );
		$this->assertSame( 'Approved comment by Visitor on "Commented post"', $events[0]['message'] );
		$this->assertSame( 'content', $events[1]['changes'][0]['field'] );
		$this->assertSame( 'Nice post!', $events[1]['changes'][0]['new'] );
	}

	/**
	 * A logged-out visitor's actions are not recorded.
	 *
	 * @return void
	 */
	public function test_visitors_are_ignored(): void {
		$this->as_user( 'editor' );
		$id = self::comment();
		wp_set_current_user( 0 );

		wp_set_comment_status( $id, 'approve' );

		$this->assertSame( array(), self::events_for( $id ) );
	}
}
