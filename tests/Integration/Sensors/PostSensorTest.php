<?php
/**
 * Tests for the PostSensor: create and update.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Sensors;

/**
 * @covers \WPActivityLedger\Sensors\PostSensor
 * @covers \WPActivityLedger\Sensors\PostActivity
 */
final class PostSensorTest extends PostSensorTestCase {

	/**
	 * Creating a post records post.created with the type label in the message.
	 *
	 * @return void
	 */
	public function test_create(): void {
		$this->as_user( 'editor' );
		$id     = self::create_post( array( 'post_type' => 'page' ) );
		$events = self::events_for( $id );

		$this->assertSame( array( 'post.created' ), array_column( $events, 'event_type' ) );
		$this->assertSame( 'Created page "Original title"', $events[0]['message'] );
		$this->assertSame( 'page', $events[0]['object_type'] );
	}

	/**
	 * An edit records one post.updated with the title and content changes only.
	 *
	 * @return void
	 */
	public function test_update_diff(): void {
		$this->as_user( 'editor' );
		$id = self::create_post();

		wp_update_post(
			array(
				'ID'           => $id,
				'post_title'   => 'New title',
				'post_content' => "Line one\nLine 2",
			)
		);

		$events  = self::events_for( $id );
		$updated = array_values( array_filter( $events, static fn( array $e ): bool => 'post.updated' === $e['event_type'] ) );

		$this->assertCount( 1, $updated );
		$this->assertSame(
			array(
				array(
					'field'     => 'post_title',
					'old'       => 'Original title',
					'new'       => 'New title',
					'truncated' => false,
				),
				array(
					'field'     => 'post_content',
					'old'       => "Line one\nLine two",
					'new'       => "Line one\nLine 2",
					'truncated' => false,
				),
			),
			$updated[0]['changes']
		);
		$this->assertSame( 'Updated post "New title"', $updated[0]['message'] );
	}

	/**
	 * A block editor save (REST PUT, which also stores a revision) records one update.
	 *
	 * @return void
	 */
	public function test_block_editor_rest_save(): void {
		$this->as_user( 'editor' );
		$id = self::create_post();

		$request = new \WP_REST_Request( 'PUT', "/wp/v2/posts/$id" );
		$request->set_body_params(
			array(
				'title'   => 'Saved from Gutenberg',
				'content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
			)
		);
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		$events = self::events_for( $id );
		$this->assertSame( array( 'post.created', 'post.updated' ), array_column( $events, 'event_type' ) );
		$this->assertSame( array( 'post_title', 'post_content' ), array_column( $events[1]['changes'], 'field' ) );
	}

	/**
	 * Saving without changes, autosaves and revisions record nothing.
	 *
	 * @return void
	 */
	public function test_no_noise(): void {
		$this->as_user( 'editor' );
		$id = self::create_post();

		wp_update_post( array( 'ID' => $id ) );
		wp_save_post_revision( $id );
		wp_create_post_autosave(
			array(
				'post_ID'      => $id,
				'post_title'   => 'Autosaved title',
				'post_content' => 'x',
				'post_type'    => 'post',
			)
		);

		$this->assertSame( array( 'post.created' ), array_column( self::events_for( $id ), 'event_type' ) );
	}

	/**
	 * Two updates in one request merge into one event (first old, last new).
	 *
	 * @return void
	 */
	public function test_updates_in_one_request_merge(): void {
		$this->as_user( 'editor' );
		$id = self::create_post();

		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Step one',
			)
		);
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Step two',
			)
		);

		$updated = array_values( array_filter( self::events_for( $id ), static fn( array $e ): bool => 'post.updated' === $e['event_type'] ) );

		$this->assertCount( 1, $updated );
		$this->assertSame( 'Original title', $updated[0]['changes'][0]['old'] );
		$this->assertSame( 'Step two', $updated[0]['changes'][0]['new'] );
	}

	/**
	 * An auto-draft's first real save counts as creation.
	 *
	 * @return void
	 */
	public function test_auto_draft_first_save_is_creation(): void {
		$this->as_user( 'editor' );
		$id = self::create_post(
			array(
				'post_status' => 'auto-draft',
				'post_title'  => 'Auto Draft',
			)
		);

		$this->assertSame( array(), self::events_for( $id ) );

		wp_update_post(
			array(
				'ID'          => $id,
				'post_title'  => 'Real post',
				'post_status' => 'draft',
			)
		);

		$this->assertSame( array( 'post.created' ), array_column( self::events_for( $id ), 'event_type' ) );
	}
}
