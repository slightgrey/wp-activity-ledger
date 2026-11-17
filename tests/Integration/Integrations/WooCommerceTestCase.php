<?php
/**
 * Shared helpers for WooCommerce integration tests.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Integrations;

use WPActivityLedger\Plugin;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * Skips when WooCommerce isn't active; settles the integration's pending changes.
 */
abstract class WooCommerceTestCase extends IntegrationTestCase {

	/**
	 * Skips when WooCommerce isn't active.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active.' );
		}
	}

	/**
	 * Records pending WooCommerce changes and flushes.
	 *
	 * @return void
	 */
	protected static function settle(): void {
		self::integration()->record_pending();
		Plugin::instance()->recorder()->flush();
	}

	/**
	 * Returns the registered WooCommerce integration.
	 *
	 * @return \WPActivityLedger\Integrations\WooCommerce Integration.
	 */
	protected static function integration(): \WPActivityLedger\Integrations\WooCommerce {
		$integration = Plugin::instance()->integrations()->get( 'woocommerce' );
		self::assertInstanceOf( \WPActivityLedger\Integrations\WooCommerce::class, $integration );

		return $integration;
	}

	/**
	 * Settles, then returns new events about an object with their changes, oldest first.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param int    $after       ID boundary.
	 *
	 * @return array<int, array<string, mixed>> Events.
	 */
	protected static function events_for( string $object_type, int $object_id, int $after ): array {
		static::settle();

		$items = self::newer_than(
			Plugin::instance()->repository()->query(
				array(
					'object_type' => $object_type,
					'object_id'   => (string) $object_id,
					'orderby'     => 'id',
					'order'       => 'asc',
				)
			)['items'],
			$after
		);

		return array_map( static fn( array $i ): array => (array) Plugin::instance()->repository()->find( $i['id'] ), $items );
	}
}
