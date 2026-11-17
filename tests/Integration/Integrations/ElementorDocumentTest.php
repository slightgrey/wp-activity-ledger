<?php
/**
 * Tests for the Elementor integration: document saves.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Integrations;

use WPActivityLedger\Integrations\Elementor;
use WPActivityLedger\Plugin;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * @covers \WPActivityLedger\Integrations\Elementor
 */
final class ElementorDocumentTest extends IntegrationTestCase {

	/**
	 * Skips when Elementor isn't active; acts as an administrator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			$this->markTestSkipped( 'Elementor is not active.' );
		}

		$this->as_user( 'administrator' );
	}

	/**
	 * Saving a page in Elementor records one Elementor event with the element delta and the title change.
	 *
	 * @return void
	 */
	public function test_document_save_is_one_event(): void {
		$page_id = self::page();
		$start   = self::max_event_id();

		$document = \Elementor\Plugin::$instance->documents->get( $page_id, false );
		$document->save(
			array(
				'settings' => array( 'post_title' => 'Landing v2' ),
				'elements' => array( self::container( 2 ), self::container( 1 ) ),
			)
		);

		$events = self::events_for( $page_id, $start );

		$this->assertSame( array( 'elementor.document_saved' ), array_column( $events, 'event_type' ) );
		$this->assertSame( 'elementor', $events[0]['category'] );
		$this->assertSame( 'Edited page "Landing v2" with Elementor', $events[0]['message'] );

		$fields = array_column( $events[0]['changes'], null, 'field' );
		$this->assertSame( '2', $fields['elements']['old'] );
		$this->assertSame( '5', $fields['elements']['new'] );
		$this->assertSame( 'Landing', $fields['post_title']['old'] );
		$this->assertSame( 'Landing v2', $fields['post_title']['new'] );
	}

	/**
	 * Other updates of the same post in a request without an Elementor save stay post.updated.
	 *
	 * @return void
	 */
	public function test_plain_update_is_not_absorbed(): void {
		$page_id = self::page();
		$start   = self::max_event_id();

		wp_update_post(
			array(
				'ID'         => $page_id,
				'post_title' => 'Renamed',
			)
		);

		$this->assertSame( array( 'post.updated' ), array_column( self::events_for( $page_id, $start ), 'event_type' ) );
	}

	/**
	 * Library items (templates, kits) aren't recorded by the content sensor.
	 *
	 * @return void
	 */
	public function test_library_items_are_skipped_by_content_sensor(): void {
		$start = self::max_event_id();
		$id    = wp_insert_post(
			array(
				'post_type'   => Elementor::LIBRARY_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Hero',
			)
		);

		$types = array_column( self::events_for( (int) $id, $start ), 'event_type' );

		$this->assertSame( array(), array_values( array_filter( $types, static fn( string $type ): bool => str_starts_with( $type, 'post.' ) ) ) );
	}

	/**
	 * Creates a published page with one container holding one widget, and settles its events.
	 *
	 * @return int Page ID.
	 */
	private static function page(): int {
		$page_id = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Landing',
			)
		);
		update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $page_id, '_elementor_data', wp_slash( (string) wp_json_encode( array( self::container( 1 ) ) ) ) );
		self::settle();

		return $page_id;
	}

	/**
	 * Builds a container element with some heading widgets.
	 *
	 * @param int $widgets Widget count.
	 *
	 * @return array<string, mixed> Element.
	 */
	private static function container( int $widgets ): array {
		$children = array();

		for ( $i = 0; $i < $widgets; $i++ ) {
			$children[] = array(
				'id'         => substr( md5( uniqid( '', true ) ), 0, 7 ),
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => array( 'title' => 'Hi' ),
				'elements'   => array(),
			);
		}

		return array(
			'id'       => substr( md5( uniqid( '', true ) ), 0, 7 ),
			'elType'   => 'container',
			'settings' => array(),
			'elements' => $children,
		);
	}

	/**
	 * Records pending post and Elementor activity (in shutdown order) and flushes.
	 *
	 * @return void
	 */
	private static function settle(): void {
		$posts = Plugin::instance()->core_sensors()['posts'];
		self::assertInstanceOf( \WPActivityLedger\Sensors\PostSensor::class, $posts );
		$posts->record_pending();

		$elementor = Plugin::instance()->integrations()->get( 'elementor' );
		self::assertInstanceOf( Elementor::class, $elementor );
		$elementor->record_pending();

		Plugin::instance()->recorder()->flush();
	}

	/**
	 * Settles, then returns new events about a post with their changes, oldest first.
	 *
	 * @param int $post_id Post ID.
	 * @param int $after   ID boundary.
	 *
	 * @return array<int, array<string, mixed>> Events.
	 */
	private static function events_for( int $post_id, int $after ): array {
		self::settle();

		$items = self::newer_than(
			Plugin::instance()->repository()->query(
				array(
					'object_id' => (string) $post_id,
					'orderby'   => 'id',
					'order'     => 'desc',
				)
			)['items'],
			$after
		);

		return array_map( static fn( array $i ): array => (array) Plugin::instance()->repository()->find( $i['id'] ), array_reverse( $items ) );
	}
}
