<?php
/**
 * WooCommerce catalog activity: products, variations, stock and coupons.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Integrations;

use WPActivityLedger\Diff\Differ;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Loader;
use WPActivityLedger\Recording\Recorder;

/**
 * Records staff changes to products, variations, stock and coupons.
 *
 * Saves go through the shared WooObjectTracker (one event per object and request). wp-admin
 * publishes the post before the product/coupon meta box saves the object, so "created" is
 * detected from the post leaving the auto-draft state. Stand-alone stock updates
 * (wc_update_product_stock) are recorded as stock changes; a stock change made while
 * editing a product is part of that product's diff.
 */
final class WooCatalogWatcher {

	/**
	 * Post type => [object type, event prefix, capability].
	 */
	private const TYPES = array(
		'product'           => array( 'product', 'woocommerce.product', 'edit_products' ),
		'product_variation' => array( 'product_variation', 'woocommerce.product', 'edit_products' ),
		'shop_coupon'       => array( 'shop_coupon', 'woocommerce.coupon', 'edit_shop_coupons' ),
	);

	/**
	 * Counters WooCommerce maintains on its own.
	 */
	private const SKIP = array( 'total_sales', 'rating_counts', 'average_rating', 'review_count', 'usage_count', 'used_by' );

	/**
	 * Stock before a stand-alone stock update, by product ID.
	 *
	 * @var array<int, int|float|null>
	 */
	private array $stock_before = array();

	/**
	 * Products being deleted in this request (their variations go with them).
	 *
	 * @var array<int, true>
	 */
	private array $deleting = array();

	/**
	 * Creates the watcher.
	 *
	 * @param Recorder         $recorder Recorder.
	 * @param Differ           $differ   Differ.
	 * @param WooObjectTracker $tracker  Shared object tracker.
	 */
	public function __construct(
		private readonly Recorder $recorder,
		private readonly Differ $differ,
		private readonly WooObjectTracker $tracker
	) {
	}

	/**
	 * Queues the hooks.
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'woocommerce_before_product_object_save', array( $this, 'on_object_save' ), 10, 1 );
		$loader->add_action( 'woocommerce_before_product_variation_object_save', array( $this, 'on_object_save' ), 10, 1 );
		// WC_Coupon keeps the default object type "data".
		$loader->add_action( 'woocommerce_before_data_object_save', array( $this, 'on_object_save' ), 10, 1 );
		$loader->add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		$loader->add_action( 'woocommerce_product_before_set_stock', array( $this, 'before_set_stock' ), 10, 1 );
		$loader->add_action( 'woocommerce_variation_before_set_stock', array( $this, 'before_set_stock' ), 10, 1 );
		$loader->add_action( 'woocommerce_product_set_stock', array( $this, 'on_set_stock' ), 10, 1 );
		$loader->add_action( 'woocommerce_variation_set_stock', array( $this, 'on_set_stock' ), 10, 1 );
		$loader->add_action( 'trashed_post', array( $this, 'on_trashed' ), 10, 1 );
		$loader->add_action( 'untrashed_post', array( $this, 'on_untrashed' ), 10, 1 );
		$loader->add_action( 'before_delete_post', array( $this, 'on_delete' ), 10, 2 );
	}

	/**
	 * Collects the changes of a product, variation or coupon saved by staff.
	 *
	 * @param mixed $data_object Object about to be saved.
	 *
	 * @return void
	 */
	public function on_object_save( mixed $data_object = null ): void {
		$type = self::post_type_of( $data_object );

		if ( null === $type || ! $data_object instanceof \WC_Data || isset( $this->stock_before[ $data_object->get_id() ] ) ) {
			return;
		}

		[ $object_type, $prefix, $cap ] = self::TYPES[ $type ];

		if ( ! WooCommerce::staff_backend_request( $cap ) ) {
			return;
		}

		$kind = $prefix . ( 0 === $data_object->get_id() ? '_created' : '_updated' );
		$this->tracker->collect( $data_object, $object_type, $kind, self::SKIP );
	}

	/**
	 * Marks a product or coupon created when its post leaves the auto-draft state.
	 *
	 * @param string $new_status New status.
	 * @param string $old_status Old status.
	 * @param mixed  $post       Post.
	 *
	 * @return void
	 */
	public function on_transition( $new_status, $old_status, mixed $post = null ): void {
		$is_creation = in_array( $old_status, array( 'new', 'auto-draft' ), true ) && ! in_array( $new_status, array( 'new', 'auto-draft', 'trash' ), true );

		if ( ! $is_creation || ! $post instanceof \WP_Post || ! isset( self::TYPES[ $post->post_type ] ) ) {
			return;
		}

		[ $object_type, $prefix, $cap ] = self::TYPES[ $post->post_type ];
		$data_object                    = self::load( $post );

		if ( null !== $data_object && WooCommerce::staff_backend_request( $cap ) ) {
			$this->tracker->mark( $data_object, $object_type, $prefix . '_created' );
		}
	}

	/**
	 * Remembers the stock before a stand-alone stock update by staff.
	 *
	 * Inside a product save the stock change is still pending on the object and becomes part
	 * of the product diff instead.
	 *
	 * @param mixed $product Product whose stock is about to change.
	 *
	 * @return void
	 */
	public function before_set_stock( mixed $product = null ): void {
		if ( ! $product instanceof \WC_Product || array_key_exists( 'stock_quantity', $product->get_changes() ) || ! WooCommerce::staff_backend_request( 'edit_products' ) ) {
			return;
		}

		$this->stock_before[ $product->get_id() ] ??= $product->get_stock_quantity( 'edit' );
	}

	/**
	 * Records a stand-alone stock change with its from → to values.
	 *
	 * @param mixed $product Product whose stock changed.
	 *
	 * @return void
	 */
	public function on_set_stock( mixed $product = null ): void {
		if ( ! $product instanceof \WC_Product || ! array_key_exists( $product->get_id(), $this->stock_before ) ) {
			return;
		}

		$old = $this->stock_before[ $product->get_id() ];
		$new = $product->get_stock_quantity( 'edit' );
		unset( $this->stock_before[ $product->get_id() ] );

		$change = $this->differ->compare( 'stock_quantity', $old, $new );

		if ( null === $change ) {
			return;
		}

		$this->recorder->record(
			new Event(
				'woocommerce.stock_changed',
				object_type: $product->is_type( 'variation' ) ? 'product_variation' : 'product',
				object_id: (string) $product->get_id(),
				object_label: WooCommerce::label( $product ),
				context: array(
					'from'  => $old,
					'stock' => $new,
				),
				changes: array( $change )
			)
		);
	}

	/**
	 * Records a product or coupon moved to the trash.
	 *
	 * Variations are trashed and restored only along with their product, so they are skipped.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	public function on_trashed( $post_id ): void {
		if ( 'product_variation' !== get_post_type( (int) $post_id ) ) {
			$this->record_post_event( (int) $post_id, '_trashed' );
		}
	}

	/**
	 * Records a product or coupon restored from the trash.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	public function on_untrashed( $post_id ): void {
		if ( 'product_variation' !== get_post_type( (int) $post_id ) ) {
			$this->record_post_event( (int) $post_id, '_restored' );
		}
	}

	/**
	 * Records a permanently deleted product, variation or coupon.
	 *
	 * Auto-drafts and the variations of a product deleted in the same request are skipped.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $post    Post.
	 *
	 * @return void
	 */
	public function on_delete( $post_id, mixed $post = null ): void {
		$post = $post instanceof \WP_Post ? $post : get_post( (int) $post_id );

		if ( ! $post instanceof \WP_Post || 'auto-draft' === $post->post_status || isset( $this->deleting[ $post->post_parent ] ) ) {
			return;
		}

		if ( 'product' === $post->post_type ) {
			$this->deleting[ $post->ID ] = true;
		}

		$this->record_post_event( $post->ID, '_deleted' );
	}

	/**
	 * Records a trash/restore/delete event about a catalog post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $suffix  Event suffix, e.g. "_deleted".
	 *
	 * @return void
	 */
	private function record_post_event( int $post_id, string $suffix ): void {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! isset( self::TYPES[ $post->post_type ] ) ) {
			return;
		}

		[ $object_type, $prefix ] = self::TYPES[ $post->post_type ];
		$data_object              = self::load( $post );

		$this->recorder->record(
			new Event(
				$prefix . $suffix,
				object_type: $object_type,
				object_id: (string) $post->ID,
				object_label: null === $data_object ? $post->post_title : WooCommerce::label( $data_object ),
				context: array( 'type_label' => self::type_label( $object_type ) )
			)
		);
	}

	/**
	 * Returns the post type a WooCommerce object is stored as, if it is a catalog object.
	 *
	 * @param mixed $data_object Object.
	 *
	 * @return string|null Post type, or null for other objects.
	 */
	private static function post_type_of( mixed $data_object ): ?string {
		return match ( true ) {
			$data_object instanceof \WC_Product_Variation => 'product_variation',
			$data_object instanceof \WC_Product           => 'product',
			$data_object instanceof \WC_Coupon            => 'shop_coupon',
			default                                       => null,
		};
	}

	/**
	 * Loads the WooCommerce object of a catalog post.
	 *
	 * @param \WP_Post $post Post.
	 *
	 * @return \WC_Data|null Object, or null when it can't be loaded.
	 */
	private static function load( \WP_Post $post ): ?\WC_Data {
		if ( 'shop_coupon' === $post->post_type ) {
			$coupon = new \WC_Coupon( $post->ID );

			return 0 === $coupon->get_id() ? null : $coupon;
		}

		$product = wc_get_product( $post->ID );

		return $product instanceof \WC_Product ? $product : null;
	}

	/**
	 * Returns a readable name for a catalog object type.
	 *
	 * @param string $object_type Object type.
	 *
	 * @return string Label.
	 */
	private static function type_label( string $object_type ): string {
		return match ( $object_type ) {
			'product_variation' => __( 'variation', 'wp-activity-ledger' ),
			'shop_coupon'       => __( 'coupon', 'wp-activity-ledger' ),
			default             => __( 'product', 'wp-activity-ledger' ),
		};
	}
}
