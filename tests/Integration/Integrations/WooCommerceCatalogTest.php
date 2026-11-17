<?php
/**
 * Tests for the WooCommerce integration: products, stock and coupons.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Integrations;

/**
 * @covers \WPActivityLedger\Integrations\WooCatalogWatcher
 * @covers \WPActivityLedger\Integrations\WooObjectTracker
 */
final class WooCommerceCatalogTest extends WooCommerceTestCase {

	/**
	 * Acts as an administrator in a staff backend request.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->as_user( 'administrator' );
		add_filter( 'wpal_woocommerce_staff_edit', '__return_true' );
	}

	/**
	 * Removes the staff filter.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		remove_filter( 'wpal_woocommerce_staff_edit', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Creates a saved simple product with managed stock and settles its events.
	 *
	 * @return \WC_Product_Simple Product.
	 */
	private static function product(): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Mug' );
		$product->set_regular_price( '10' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 5 );
		$product->save();
		self::settle();

		return $product;
	}

	/**
	 * Creating a product records one "created" event.
	 *
	 * @return void
	 */
	public function test_product_created(): void {
		$start   = self::max_event_id();
		$product = self::product();

		$events = self::events_for( 'product', $product->get_id(), $start );

		$this->assertSame( array( 'woocommerce.product_created' ), array_column( $events, 'event_type' ) );
		$this->assertSame( 'Created product "Mug"', $events[0]['message'] );
	}

	/**
	 * Editing price and stock records one update with both diffs and no separate stock event.
	 *
	 * @return void
	 */
	public function test_product_edit_records_price_and_stock_diff(): void {
		$product = self::product();
		$start   = self::max_event_id();

		$fresh = wc_get_product( $product->get_id() );
		$fresh->set_regular_price( '12' );
		$fresh->set_stock_quantity( 8 );
		$fresh->save();

		$events = self::events_for( 'product', $product->get_id(), $start );

		$this->assertSame( array( 'woocommerce.product_updated' ), array_column( $events, 'event_type' ) );

		$fields = array_column( $events[0]['changes'], null, 'field' );
		$this->assertSame( '10', $fields['regular_price']['old'] );
		$this->assertSame( '12', $fields['regular_price']['new'] );
		$this->assertSame( '5', $fields['stock_quantity']['old'] );
		$this->assertSame( '8', $fields['stock_quantity']['new'] );
		$this->assertArrayNotHasKey( 'total_sales', $fields );
	}

	/**
	 * A stand-alone stock update records the stock from → to.
	 *
	 * @return void
	 */
	public function test_stock_update_records_from_to(): void {
		$product = self::product();
		$start   = self::max_event_id();

		wc_update_product_stock( $product->get_id(), 2, 'decrease' );

		$events = self::events_for( 'product', $product->get_id(), $start );

		$this->assertSame( array( 'woocommerce.stock_changed' ), array_column( $events, 'event_type' ) );
		$this->assertSame( '5', $events[0]['changes'][0]['old'] );
		$this->assertSame( '3', $events[0]['changes'][0]['new'] );
		$this->assertSame( 'Stock of "Mug" changed to 3', $events[0]['message'] );
	}

	/**
	 * Saves outside a staff backend request (checkout) are not recorded.
	 *
	 * @return void
	 */
	public function test_non_staff_changes_are_ignored(): void {
		$product = self::product();
		remove_filter( 'wpal_woocommerce_staff_edit', '__return_true' );
		$start = self::max_event_id();

		wc_update_product_stock( $product->get_id(), 1, 'decrease' );
		$fresh = wc_get_product( $product->get_id() );
		$fresh->set_total_sales( 4 );
		$fresh->save();

		$this->assertSame( array(), self::events_for( 'product', $product->get_id(), $start ) );
	}

	/**
	 * A product published from an auto-draft (the wp-admin flow) counts as created.
	 *
	 * @return void
	 */
	public function test_published_auto_draft_is_created(): void {
		$start = self::max_event_id();
		$id    = wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_status' => 'auto-draft',
				'post_title'  => 'AUTO-DRAFT',
			)
		);
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
				'post_title'  => 'Lamp',
			)
		);
		$product = wc_get_product( $id );
		$product->set_regular_price( '30' );
		$product->save();

		$events = self::events_for( 'product', $id, $start );

		$this->assertSame( array( 'woocommerce.product_created' ), array_column( $events, 'event_type' ) );
		$this->assertSame( 'Created product "Lamp"', $events[0]['message'] );
	}

	/**
	 * Trashing, restoring and deleting a product are recorded; its variations go silently.
	 *
	 * @return void
	 */
	public function test_trash_restore_delete(): void {
		$product   = self::product();
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->save();
		self::settle();
		$start = self::max_event_id();

		wp_trash_post( $product->get_id() );
		wp_untrash_post( $product->get_id() );
		wp_delete_post( $product->get_id(), true );

		$this->assertSame(
			array( 'woocommerce.product_trashed', 'woocommerce.product_restored', 'woocommerce.product_deleted' ),
			array_column( self::events_for( 'product', $product->get_id(), $start ), 'event_type' )
		);
		$this->assertSame( array(), self::events_for( 'product_variation', $variation->get_id(), $start ) );
	}

	/**
	 * Coupons: created with its code, updated with a diff, deleted.
	 *
	 * @return void
	 */
	public function test_coupon_lifecycle(): void {
		$start  = self::max_event_id();
		$coupon = new \WC_Coupon();
		$coupon->set_code( 'save10' );
		$coupon->set_amount( 10 );
		$coupon->save();
		self::settle();

		$coupon->set_amount( 15 );
		$coupon->save();
		self::settle();

		wp_delete_post( $coupon->get_id(), true );

		$events = self::events_for( 'shop_coupon', $coupon->get_id(), $start );

		$this->assertSame( array( 'woocommerce.coupon_created', 'woocommerce.coupon_updated', 'woocommerce.coupon_deleted' ), array_column( $events, 'event_type' ) );
		$this->assertSame( 'Created coupon "save10"', $events[0]['message'] );

		$fields = array_column( $events[1]['changes'], null, 'field' );
		$this->assertSame( '10', $fields['amount']['old'] );
		$this->assertSame( '15', $fields['amount']['new'] );
	}
}
