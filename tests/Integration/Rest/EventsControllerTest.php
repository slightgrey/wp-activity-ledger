<?php
/**
 * Tests for the base controller and the events routes.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Rest;

/**
 * @covers \WPActivityLedger\Rest\BaseController
 * @covers \WPActivityLedger\Rest\EventsController
 * @covers \WPActivityLedger\Rest\ObjectLinks
 * @covers \WPActivityLedger\Rest\Routes
 */
final class EventsControllerTest extends RestTestCase {

	/**
	 * The namespace index lists the routes.
	 *
	 * @return void
	 */
	public function test_namespace_index(): void {
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/wpal/v1' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'wpal/v1', $response->get_data()['namespace'] );
		$this->assertArrayHasKey( '/wpal/v1/events', $response->get_data()['routes'] );
	}

	/**
	 * Logged-out requests get 401 on every route; editors get 403.
	 *
	 * @return void
	 */
	public function test_permissions(): void {
		wp_set_current_user( 0 );

		foreach ( array( '/events', '/events/1', '/meta', '/stats', '/settings', '/users' ) as $route ) {
			$this->assertSame( 401, self::request( 'GET', $route )->get_status(), $route );
		}

		$this->as_user( 'editor' );
		$this->assertSame( 403, self::request( 'GET', '/events' )->get_status() );
	}

	/**
	 * Filters, total headers, the expanded actor, and edit links (null once the object is gone).
	 *
	 * @return void
	 */
	public function test_list_filters_and_links(): void {
		$admin = $this->as_user( 'administrator' )->ID;
		$kept  = self::factory_post( 'Kept' );
		$gone  = self::factory_post( 'Gone' );
		$this->settle_posts();
		wp_update_post(
			array(
				'ID'         => $kept,
				'post_title' => 'Kept v2',
			)
		);
		wp_update_post(
			array(
				'ID'         => $gone,
				'post_title' => 'Gone v2',
			)
		);
		$this->settle_posts();
		wp_delete_post( $gone, true );
		$this->settle_posts();

		$response = self::request(
			'GET',
			'/events',
			array(
				'type'     => 'post.updated',
				'user'     => array( $admin ),
				'per_page' => 1,
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );

		$items = self::request(
			'GET',
			'/events',
			array(
				'type' => 'post.updated',
				'user' => $admin,
			)
		)->get_data();
		$links = array_column( $items, 'edit_link', 'object_id' );

		$this->assertNull( $links[ (string) $gone ] );
		$this->assertStringContainsString( 'post.php?post=' . $kept, (string) $links[ (string) $kept ] );
		$this->assertSame( $admin, $items[0]['user']['id'] );
		$this->assertTrue( $items[0]['user']['exists'] );
	}

	/**
	 * Single event: changes included; unknown ID gives 404; invalid filters give 400.
	 *
	 * @return void
	 */
	public function test_single_event_and_validation(): void {
		$this->as_user( 'administrator' );
		$post = self::factory_post( 'One' );
		$this->settle_posts();
		wp_update_post(
			array(
				'ID'         => $post,
				'post_title' => 'One v2',
			)
		);
		$this->settle_posts();

		$id    = self::request(
			'GET',
			'/events',
			array(
				'object_id' => (string) $post,
				'type'      => 'post.updated',
			)
		)->get_data()[0]['id'];
		$event = self::request( 'GET', '/events/' . $id )->get_data();

		$this->assertSame( 'post_title', $event['changes'][0]['field'] );
		$this->assertSame( 404, self::request( 'GET', '/events/999999999' )->get_status() );
		$this->assertSame( 400, self::request( 'GET', '/events', array( 'per_page' => 500 ) )->get_status() );
		$this->assertSame( 400, self::request( 'GET', '/events', array( 'orderby' => 'message; DROP' ) )->get_status() );
	}

	/**
	 * Creates a published post.
	 *
	 * @param string $title Title.
	 *
	 * @return int Post ID.
	 */
	private static function factory_post( string $title ): int {
		return (int) wp_insert_post(
			array(
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Records pending post activity and flushes.
	 *
	 * @return void
	 */
	private function settle_posts(): void {
		$posts = \WPActivityLedger\Plugin::instance()->core_sensors()['posts'];
		$this->assertInstanceOf( \WPActivityLedger\Sensors\PostSensor::class, $posts );
		$posts->record_pending();
		self::flush();
	}
}
