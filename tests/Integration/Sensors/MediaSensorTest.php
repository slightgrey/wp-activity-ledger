<?php
/**
 * Tests for the MediaSensor.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Sensors;

use WPActivityLedger\Plugin;
use WPActivityLedger\Sensors\MediaSensor;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * Attachments are created without files, so nothing is written to the uploads folder.
 *
 * @covers \WPActivityLedger\Sensors\MediaSensor
 */
final class MediaSensorTest extends IntegrationTestCase {

	/**
	 * Records what the media and post sensors collected and flushes.
	 *
	 * @return void
	 */
	private static function settle(): void {
		$sensor = Plugin::instance()->core_sensors()['media'];
		self::assertInstanceOf( MediaSensor::class, $sensor );
		$sensor->record_pending();
		Plugin::instance()->recorder()->flush();
	}

	/**
	 * Returns events about an attachment with their changes, oldest first.
	 *
	 * @param int $id Attachment ID.
	 *
	 * @return array<int, array<string, mixed>> Events.
	 */
	private static function events_for( int $id ): array {
		self::settle();

		$items = Plugin::instance()->repository()->query(
			array(
				'object_type' => 'attachment',
				'object_id'   => (string) $id,
				'orderby'     => 'id',
				'order'       => 'asc',
			)
		)['items'];

		return array_map( static fn( array $i ): array => (array) Plugin::instance()->repository()->find( $i['id'] ), $items );
	}

	/**
	 * Upload, edit title + alt (one event), image edit and delete.
	 *
	 * @return void
	 */
	public function test_lifecycle(): void {
		$this->as_user( 'author' );

		$id = wp_insert_attachment(
			array(
				'post_title'     => 'Beach',
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			)
		);
		$this->assertIsInt( $id );
		self::settle();

		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Sunny beach',
			)
		);
		update_post_meta( $id, '_wp_attachment_image_alt', 'Sand and sea' );
		self::settle();

		apply_filters( 'wp_save_image_editor_file', null, 'beach.jpg', null, 'image/jpeg', $id );
		self::settle();

		wp_delete_attachment( $id, true );

		$events = self::events_for( $id );

		$this->assertSame( array( 'media.uploaded', 'media.updated', 'media.edited', 'media.deleted' ), array_column( $events, 'event_type' ) );
		$this->assertSame( 'Uploaded "Beach"', $events[0]['message'] );

		$fields = array_column( $events[1]['changes'], null, 'field' );
		$this->assertEqualsCanonicalizing( array( 'title', 'alt' ), array_keys( $fields ) );
		$this->assertNull( $fields['alt']['old'] );
		$this->assertSame( 'Sand and sea', $fields['alt']['new'] );
		$this->assertSame( 'Deleted media "Sunny beach"', $events[3]['message'] );
	}

	/**
	 * Attachments don't produce content (post.*) events.
	 *
	 * @return void
	 */
	public function test_no_post_events_for_attachments(): void {
		$this->as_user( 'author' );

		$id = wp_insert_attachment(
			array(
				'post_title'     => 'Doc',
				'post_mime_type' => 'application/pdf',
				'post_status'    => 'inherit',
			)
		);
		$this->assertIsInt( $id );

		$posts = Plugin::instance()->core_sensors()['posts'];
		self::assertInstanceOf( \WPActivityLedger\Sensors\PostSensor::class, $posts );
		$posts->record_pending();

		$this->assertSame( array( 'media.uploaded' ), array_column( self::events_for( $id ), 'event_type' ) );
	}
}
