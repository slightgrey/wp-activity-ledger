<?php
/**
 * Tests for the WooCommerce integration: orders.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Integrations;

use WPActivityLedger\Plugin;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * Requires WooCommerce to be active (HPOS on).
 *
 * @covers \WPActivityLedger\Integrations\WooCommerce
 */
final class WooCommerceOrdersTest extends IntegrationTestCase {

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
	 * Returns new events about an order, oldest first.
	 *
	 * @param int $order_id Order ID.
	 * @param int $after    ID boundary.
	 *
	 * @return array<int, array<string, mixed>> Events.
	 */
	private static function events_for( int $order_id, int $after ): array {
		Plugin::instance()->recorder()->flush();

		return self::newer_than(
			Plugin::instance()->repository()->query(
				array(
					'object_type' => 'shop_order',
					'object_id'   => (string) $order_id,
					'orderby'     => 'id',
					'order'       => 'asc',
				)
			)['items'],
			$after
		);
	}

	/**
	 * Status change, partial refund and a staff note are recorded with details.
	 *
	 * @return void
	 */
	public function test_status_refund_and_note(): void {
		$this->as_user( 'administrator' );
		$start = self::max_event_id();

		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->set_total( 50 );
		$order->save();

		$order->update_status( 'processing' );
		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 10,
				'reason'   => 'Damaged item',
			)
		);
		$order->add_order_note( 'Called the customer.', false, true );

		$events = self::events_for( $order->get_id(), $start );
		$types  = array_column( $events, 'event_type' );

		$this->assertContains( 'woocommerce.order_status_changed', $types );
		$this->assertContains( 'woocommerce.order_refunded', $types );
		$this->assertContains( 'woocommerce.order_note_added', $types );

		$by_type = array_column( $events, null, 'event_type' );
		$this->assertSame( 'Pending payment', $by_type['woocommerce.order_status_changed']['context']['from'] );
		$this->assertSame( 'Processing', $by_type['woocommerce.order_status_changed']['context']['to'] );
		$this->assertSame( 'Damaged item', $by_type['woocommerce.order_refunded']['context']['reason'] );
		$this->assertStringContainsString( '10', $by_type['woocommerce.order_refunded']['message'] );
		$this->assertSame( 'woocommerce', $by_type['woocommerce.order_refunded']['category'] );
	}

	/**
	 * HPOS compatibility is declared, and WooCommerce post types are kept out of content events.
	 *
	 * @return void
	 */
	public function test_hpos_declared_and_post_types_skipped(): void {
		$controller = wc_get_container()->get( \Automattic\WooCommerce\Internal\Features\FeaturesController::class );
		$compatible = $controller->get_compatible_plugins_for_feature( 'custom_order_tables' );

		$this->assertContains( plugin_basename( WPAL_FILE ), $compatible['compatible'] ?? array() );
		$this->assertContains( 'product', apply_filters( 'wpal_post_sensor_skip_types', array() ) );
	}
}
