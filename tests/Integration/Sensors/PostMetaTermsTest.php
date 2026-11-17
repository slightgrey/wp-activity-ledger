<?php
/**
 * Tests for PostSensor meta, terms, featured image and sticky changes.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Sensors;

/**
 * @covers \WPActivityLedger\Sensors\PostSensor
 * @covers \WPActivityLedger\Sensors\MetaWatcher
 */
final class PostMetaTermsTest extends PostSensorTestCase {

	/**
	 * Categories, tags, featured image, sticky and custom meta changed in one request
	 * end up in one event; private and ignored meta are skipped.
	 *
	 * @return void
	 */
	public function test_everything_in_one_event(): void {
		$this->as_user( 'editor' );

		$category = wp_insert_term( 'WPAL Cat ' . wp_generate_password( 4, false ), 'category' );
		$this->assertIsArray( $category );
		$image = wp_insert_attachment(
			array(
				'post_title'     => 'Hero image',
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			)
		);
		$this->assertIsInt( $image );

		$id = self::create_post();

		wp_set_post_terms( $id, array( (int) $category['term_id'] ), 'category' );
		wp_set_post_tags( $id, array( 'alpha', 'beta' ) );
		// set_post_thumbnail() refuses attachments without an image file, so set the meta directly.
		update_post_meta( $id, '_thumbnail_id', $image );
		stick_post( $id );
		update_post_meta( $id, 'subtitle', 'Hello' );
		update_post_meta( $id, '_private_plugin_data', 'x' );
		update_post_meta( $id, '_edit_lock', time() . ':1' );

		$events = self::events_for( $id );
		$this->assertSame( array( 'post.created', 'post.updated' ), array_column( $events, 'event_type' ) );

		$fields = array_column( $events[1]['changes'], null, 'field' );
		$this->assertEqualsCanonicalizing( array( 'tax:category', 'tax:post_tag', 'featured_image', 'sticky', 'meta:subtitle' ), array_keys( $fields ) );
		$this->assertSame( array( 'Uncategorized' ), $fields['tax:category']['old'] );
		$this->assertSame( array( 'alpha', 'beta' ), $fields['tax:post_tag']['new'] );
		$this->assertSame( "Hero image (#$image)", $fields['featured_image']['new'] );
		$this->assertSame( 'true', $fields['sticky']['new'] );
		$this->assertNull( $fields['meta:subtitle']['old'] );
	}

	/**
	 * Setting the same terms again is not a change.
	 *
	 * @return void
	 */
	public function test_same_terms_no_event(): void {
		$this->as_user( 'editor' );
		$id = self::create_post();

		wp_set_post_tags( $id, array( 'same' ) );
		self::settle();
		wp_set_post_tags( $id, array( 'same' ) );

		$this->assertSame( array( 'post.created', 'post.updated' ), array_column( self::events_for( $id ), 'event_type' ) );
	}
}
