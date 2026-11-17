<?php
/**
 * Shared helpers for content sensor tests.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Sensors;

use WPActivityLedger\Plugin;
use WPActivityLedger\Sensors\PostSensor;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * Settles the post sensor and reads back events about one object.
 */
abstract class PostSensorTestCase extends IntegrationTestCase {

	/**
	 * Records what the post sensor collected and flushes the recorder.
	 *
	 * @return void
	 */
	protected static function settle(): void {
		$sensor = Plugin::instance()->core_sensors()['posts'];
		self::assertInstanceOf( PostSensor::class, $sensor );
		$sensor->record_pending();
		Plugin::instance()->recorder()->flush();
	}

	/**
	 * Settles, then returns the events about one object with their changes, oldest first.
	 *
	 * @param int $object_id Object ID.
	 *
	 * @return array<int, array<string, mixed>> Events.
	 */
	protected static function events_for( int $object_id ): array {
		static::settle();

		$items = Plugin::instance()->repository()->query(
			array(
				'object_id' => (string) $object_id,
				'orderby'   => 'id',
				'order'     => 'asc',
				'per_page'  => 100,
			)
		)['items'];

		return array_map( static fn( array $i ): array => (array) Plugin::instance()->repository()->find( $i['id'] ), $items );
	}

	/**
	 * Creates a draft post and settles (so creation is a separate event).
	 *
	 * @param array<string, mixed> $args Extra post fields.
	 *
	 * @return int Post ID.
	 */
	protected static function create_post( array $args = array() ): int {
		$id = wp_insert_post(
			array_merge(
				array(
					'post_title'   => 'Original title',
					'post_content' => "Line one\nLine two",
					'post_status'  => 'draft',
					'post_type'    => 'post',
				),
				$args
			)
		);

		static::settle();

		return is_int( $id ) ? $id : 0;
	}
}
