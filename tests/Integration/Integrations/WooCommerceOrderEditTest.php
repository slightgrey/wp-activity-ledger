<?php
/**
 * Tests for the WooCommerce integration: manual order edits.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Integrations;

/**
 * @covers \WPActivityLedger\Integrations\WooCommerce
 * @covers \WPActivityLedger\Integrations\WooObjectTracker
 */
final class WooCommerceOrderEditTest extends WooCommerceTestCase {

	/**
	 * Creates a saved order with a billing address.
	 *
	 * @return \WC_Order Order.
	 */
	private static function order(): \WC_Order {
		$order = wc_create_order();
		self::assertInstanceOf( \WC_Order::class, $order );
		$order->set_billing_first_name( 'Ann' );
		$order->set_billing_city( 'Leeds' );
		$order->save();

		return $order;
	}

	/**
	 * A staff edit in a REST request records one event with the billing diff.
	 *
	 * @return void
	 */
	public function test_staff_edit_records_billing_diff(): void {
		$this->as_user( 'administrator' );
		$order = self::order();
		self::settle();
		$start = self::max_event_id();

		$this->as_rest_request(
			static function () use ( $order ): void {
				$fresh = wc_get_order( $order->get_id() );
				$fresh->set_billing_city( 'York' );
				$fresh->save();
				$fresh->set_billing_first_name( 'Anne' );
				$fresh->save();
			}
		);

		$events = self::events_for( 'shop_order', $order->get_id(), $start );

		$this->assertSame( array( 'woocommerce.order_updated' ), array_column( $events, 'event_type' ) );

		$fields = array_column( $events[0]['changes'], null, 'field' );
		$this->assertEqualsCanonicalizing( array( 'billing:city', 'billing:first_name' ), array_keys( $fields ) );
		$this->assertSame( 'Leeds', $fields['billing:city']['old'] );
		$this->assertSame( 'York', $fields['billing:city']['new'] );
		$this->assertSame( 'Edited order #' . $order->get_order_number(), $events[0]['message'] );
	}

	/**
	 * Saves outside a staff backend request (checkout, gateways) are not recorded as edits.
	 *
	 * @return void
	 */
	public function test_front_end_save_is_not_an_edit(): void {
		wp_set_current_user( 0 );
		$order = self::order();
		$start = self::max_event_id();

		$order->set_billing_city( 'Hull' );
		$order->save();

		$this->assertSame( array(), self::events_for( 'shop_order', $order->get_id(), $start ) );
	}

	/**
	 * Runs a callback as a staff backend request (the CLI is neither wp-admin nor REST).
	 *
	 * @param callable $callback Callback.
	 *
	 * @return void
	 */
	private function as_rest_request( callable $callback ): void {
		add_filter( 'wpal_woocommerce_staff_edit', '__return_true' );
		$callback();
		remove_filter( 'wpal_woocommerce_staff_edit', '__return_true' );
	}
}
