<?php
/**
 * WooCommerce integration.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Integrations;

use WPActivityLedger\Diff\Differ;
use WPActivityLedger\Domain\Category;
use WPActivityLedger\Domain\Change;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Domain\EventType;
use WPActivityLedger\Domain\Severity;
use WPActivityLedger\Loader;
use WPActivityLedger\Recording\Recorder;
use WPActivityLedger\Sensors\OptionFilter;

/**
 * Records WooCommerce activity: orders (status changes, refunds, staff notes and edits),
 * the catalog (products, stock, coupons) and settings (including payment gateway toggles).
 *
 * Declares compatibility with High-Performance Order Storage (orders are read through
 * the WooCommerce CRUD API only). WooCommerce post types are left out of the content
 * sensor; the WooCommerce APIs describe them better.
 */
final class WooCommerce implements IntegrationInterface {

	/**
	 * WooCommerce post types the content sensor skips.
	 */
	public const POST_TYPES = array( 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'product', 'product_variation', 'shop_coupon' );

	/**
	 * WooCommerce options it rewrites on its own (never recorded).
	 */
	public const NOISE_OPTIONS = array(
		'woocommerce_maybe_regenerate_images_hash',
		'*wc_regenerate_images*',
		'woocommerce_admin_notices',
		'woocommerce_meta_box_errors',
		'woocommerce_queue_flush_rewrite_rules',
		'woocommerce_marketplace_suggestions',
		'woocommerce_admin_last_orders_milestone',
		'woocommerce_tracker_last_send',
		'woocommerce_inbox_variant_assignment',
		'wc_remote_inbox_notifications_*',
		'woocommerce_schema_version',
		'woocommerce_db_version',
		'woocommerce_version',
		'_wc_*',
	);

	/**
	 * Diffs orders, products and coupons on save.
	 *
	 * @var WooObjectTracker
	 */
	private WooObjectTracker $tracker;

	/**
	 * Records products, variations, stock and coupons.
	 *
	 * @var WooCatalogWatcher
	 */
	private WooCatalogWatcher $catalog;

	/**
	 * Records settings and payment gateway toggles.
	 *
	 * @var WooSettingsWatcher
	 */
	private WooSettingsWatcher $settings;

	/**
	 * Creates the integration.
	 *
	 * @param Recorder     $recorder Recorder.
	 * @param Differ       $differ   Differ.
	 * @param OptionFilter $noise    Option noise filter.
	 */
	public function __construct(
		private readonly Recorder $recorder,
		private readonly Differ $differ,
		OptionFilter $noise
	) {
		$this->tracker  = new WooObjectTracker( $recorder, $differ );
		$this->catalog  = new WooCatalogWatcher( $recorder, $differ, $this->tracker );
		$this->settings = new WooSettingsWatcher( $recorder, $differ, $noise );
	}

	/**
	 * Returns the slug.
	 *
	 * @return string Slug.
	 */
	public function slug(): string {
		return 'woocommerce';
	}

	/**
	 * Returns the name.
	 *
	 * @return string Name.
	 */
	public function name(): string {
		return 'WooCommerce';
	}

	/**
	 * Tells whether WooCommerce is active.
	 *
	 * @return bool True when active.
	 */
	public function is_available(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Queues the hooks.
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ), 10, 0 );
		$loader->add_filter( 'wpal_post_sensor_skip_types', array( $this, 'skip_post_types' ), 10, 1 );
		$loader->add_filter( 'wpal_ignored_option_patterns', array( $this, 'ignore_noise_options' ), 10, 1 );
		$loader->add_action( 'woocommerce_before_order_object_save', array( $this, 'on_order_save' ), 10, 1 );
		$loader->add_action( 'shutdown', array( $this, 'record_pending' ), 0, 0 );
		$loader->add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 4 );
		$loader->add_action( 'woocommerce_order_refunded', array( $this, 'on_refunded' ), 10, 2 );
		$loader->add_action( 'woocommerce_order_note_added', array( $this, 'on_note_added' ), 10, 2 );
		$this->catalog->register( $loader );
		$this->settings->register( $loader );
	}

	/**
	 * Returns the WooCommerce event types.
	 *
	 * @return array<int, EventType> Types.
	 */
	public function event_types(): array {
		$c = Category::WooCommerce;

		return array(
			new EventType( 'woocommerce.order_status_changed', $c, Severity::Notice, __( 'Order status changed', 'wp-activity-ledger' ), __( 'Order {object} changed from {from} to {to}', 'wp-activity-ledger' ) ),
			new EventType( 'woocommerce.order_refunded', $c, Severity::Warning, __( 'Order refunded', 'wp-activity-ledger' ), __( 'Refunded {amount} on order {object}', 'wp-activity-ledger' ) ),
			new EventType( 'woocommerce.order_note_added', $c, Severity::Info, __( 'Order note added', 'wp-activity-ledger' ), __( 'Added a note to order {object}', 'wp-activity-ledger' ) ),
			new EventType( 'woocommerce.order_updated', $c, Severity::Notice, __( 'Order edited', 'wp-activity-ledger' ), __( 'Edited order {object}', 'wp-activity-ledger' ) ),
			new EventType( 'woocommerce.product_created', $c, Severity::Info, __( 'Product created', 'wp-activity-ledger' ), __( 'Created product "{object}"', 'wp-activity-ledger' ) ),
			new EventType( 'woocommerce.product_updated', $c, Severity::Info, __( 'Product updated', 'wp-activity-ledger' ), __( 'Updated product "{object}"', 'wp-activity-ledger' ) ),
			new EventType( 'woocommerce.product_trashed', $c, Severity::Notice, __( 'Product trashed', 'wp-activity-ledger' ), __( 'Moved product "{object}" to the trash', 'wp-activity-ledger' ) ),
			new EventType( 'woocommerce.product_restored', $c, Severity::Info, __( 'Product restored', 'wp-activity-ledger' ), __( 'Restored product "{object}" from the trash', 'wp-activity-ledger' ) ),
			new EventType( 'woocommerce.product_deleted', $c, Severity::Warning, __( 'Product deleted', 'wp-activity-ledger' ), __( 'Deleted product "{object}"', 'wp-activity-ledger' ) ),
			new EventType( 'woocommerce.stock_changed', $c, Severity::Info, __( 'Stock changed', 'wp-activity-ledger' ), __( 'Stock of "{object}" changed to {stock}', 'wp-activity-ledger' ) ),
			new EventType( 'woocommerce.coupon_created', $c, Severity::Notice, __( 'Coupon created', 'wp-activity-ledger' ), __( 'Created coupon "{object}"', 'wp-activity-ledger' ) ),
			new EventType( 'woocommerce.coupon_updated', $c, Severity::Notice, __( 'Coupon updated', 'wp-activity-ledger' ), __( 'Updated coupon "{object}"', 'wp-activity-ledger' ) ),
			new EventType( 'woocommerce.coupon_trashed', $c, Severity::Notice, __( 'Coupon trashed', 'wp-activity-ledger' ), __( 'Moved coupon "{object}" to the trash', 'wp-activity-ledger' ) ),
			new EventType( 'woocommerce.coupon_restored', $c, Severity::Info, __( 'Coupon restored', 'wp-activity-ledger' ), __( 'Restored coupon "{object}" from the trash', 'wp-activity-ledger' ) ),
			new EventType( 'woocommerce.coupon_deleted', $c, Severity::Warning, __( 'Coupon deleted', 'wp-activity-ledger' ), __( 'Deleted coupon "{object}"', 'wp-activity-ledger' ) ),
			new EventType( 'woocommerce.settings_changed', $c, Severity::Notice, __( 'WooCommerce settings changed', 'wp-activity-ledger' ), __( 'Changed {count} WooCommerce setting(s)', 'wp-activity-ledger' ) ),
			new EventType( 'woocommerce.gateway_toggled', $c, Severity::Warning, __( 'Payment method switched', 'wp-activity-ledger' ), __( 'Turned payment method "{object}" {state}', 'wp-activity-ledger' ) ),
		);
	}

	/**
	 * Declares compatibility with High-Performance Order Storage.
	 *
	 * @return void
	 */
	public function declare_compatibility(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WPAL_FILE, true );
		}
	}

	/**
	 * Adds the WooCommerce post types to the content sensor's skip list.
	 *
	 * @param mixed $types Skipped post types.
	 *
	 * @return array<int, string> Skipped post types.
	 */
	public function skip_post_types( $types ): array {
		return array_values( array_unique( array_merge( array_map( 'strval', (array) $types ), self::POST_TYPES ) ) );
	}

	/**
	 * Collects field changes of an existing order edited by staff in wp-admin or via REST.
	 *
	 * Front-end checkout and gateway callbacks are left to the status change event. The
	 * status itself is skipped here (it has its own event).
	 *
	 * @param mixed $order Order about to be saved.
	 *
	 * @return void
	 */
	public function on_order_save( mixed $order = null ): void {
		if ( ! $order instanceof \WC_Order || 0 === $order->get_id() || ! self::staff_backend_request( 'edit_shop_orders' ) ) {
			return;
		}

		$this->tracker->collect( $order, 'shop_order', 'woocommerce.order_updated', array( 'status' ) );
	}

	/**
	 * Records the collected object and settings changes. Runs on shutdown (priority 0).
	 *
	 * @return void
	 */
	public function record_pending(): void {
		$this->tracker->record_pending( array( self::class, 'label' ) );
		$this->settings->record_pending();
	}

	/**
	 * Returns the label of a WooCommerce object ("#123" for orders, the name otherwise).
	 *
	 * @param \WC_Data $data_object Object.
	 *
	 * @return string Label.
	 */
	public static function label( \WC_Data $data_object ): string {
		if ( $data_object instanceof \WC_Order ) {
			return '#' . $data_object->get_order_number();
		}

		$name = method_exists( $data_object, 'get_name' ) ? (string) $data_object->get_name() : '';
		$name = '' === $name && method_exists( $data_object, 'get_code' ) ? (string) $data_object->get_code() : $name;

		return '' === $name ? '#' . $data_object->get_id() : $name;
	}

	/**
	 * Tells whether the request is a staff edit from wp-admin or the REST API.
	 *
	 * @param string $capability WooCommerce capability the user needs, e.g. "edit_products".
	 *
	 * @return bool True for staff edits.
	 */
	public static function staff_backend_request( string $capability ): bool {
		$backend = is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		$staff   = $backend && is_user_logged_in() && current_user_can( $capability ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- WooCommerce capability.

		/**
		 * Filters whether WooCommerce object saves in this request count as staff edits.
		 *
		 * @param bool   $staff      True for logged-in shop staff in wp-admin or the REST API.
		 * @param string $capability Capability that was checked.
		 */
		return (bool) apply_filters( 'wpal_woocommerce_staff_edit', $staff, $capability );
	}

	/**
	 * Adds WooCommerce's self-maintained options to the ignored option patterns.
	 *
	 * @param mixed $patterns Ignored patterns.
	 *
	 * @return array<int, string> Ignored patterns.
	 */
	public function ignore_noise_options( $patterns ): array {
		return array_values( array_unique( array_merge( array_map( 'strval', (array) $patterns ), self::NOISE_OPTIONS ) ) );
	}

	/**
	 * Records an order status change.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $from     Old status.
	 * @param string $to       New status.
	 * @param mixed  $order    Order.
	 *
	 * @return void
	 */
	public function on_status_changed( $order_id, $from, $to, mixed $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$change = $this->differ->compare( 'status', wc_get_order_status_name( (string) $from ), wc_get_order_status_name( (string) $to ) );

		$this->record_order(
			'woocommerce.order_status_changed',
			$order,
			array(
				'from' => wc_get_order_status_name( (string) $from ),
				'to'   => wc_get_order_status_name( (string) $to ),
			),
			null === $change ? array() : array( $change )
		);
	}

	/**
	 * Records a refund with its amount and reason.
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 *
	 * @return void
	 */
	public function on_refunded( $order_id, $refund_id ): void {
		$order  = wc_get_order( (int) $order_id );
		$refund = wc_get_order( (int) $refund_id );

		if ( ! $order instanceof \WC_Order || ! $refund instanceof \WC_Order_Refund ) {
			return;
		}

		$this->record_order(
			'woocommerce.order_refunded',
			$order,
			array(
				'amount'   => html_entity_decode( wp_strip_all_tags( wc_price( (float) $refund->get_amount(), array( 'currency' => $order->get_currency() ) ) ) ),
				'reason'   => (string) $refund->get_reason(),
				'refund'   => (int) $refund_id,
				'currency' => $order->get_currency(),
			)
		);
	}

	/**
	 * Records an order note written by a staff member (not WooCommerce's automatic notes).
	 *
	 * WooCommerce doesn't set a user ID on notes; a staff note carries the author's email,
	 * an automatic one "woocommerce@<host>".
	 *
	 * @param int   $note_id Note (comment) ID.
	 * @param mixed $order   Order.
	 *
	 * @return void
	 */
	public function on_note_added( $note_id, mixed $order = null ): void {
		$note = get_comment( (int) $note_id );

		if ( ! $order instanceof \WC_Order || ! $note instanceof \WP_Comment || ! self::is_staff_note( $note ) ) {
			return;
		}

		$this->record_order(
			'woocommerce.order_note_added',
			$order,
			array(
				'note'          => wp_trim_words( wp_strip_all_tags( $note->comment_content ), 30 ),
				'customer_note' => (bool) get_comment_meta( $note->comment_ID, 'is_customer_note', true ),
			)
		);
	}

	/**
	 * Records an event about an order.
	 *
	 * @param string               $type    Event type.
	 * @param \WC_Order            $order   Order.
	 * @param array<string, mixed> $context Context.
	 * @param array<int, Change>   $changes Changes.
	 *
	 * @return void
	 */
	private function record_order( string $type, \WC_Order $order, array $context, array $changes = array() ): void {
		$this->recorder->record(
			new Event(
				$type,
				object_type: 'shop_order',
				object_id: (string) $order->get_id(),
				object_label: '#' . $order->get_order_number(),
				context: $context,
				changes: $changes
			)
		);
	}

	/**
	 * Tells whether a note was written by the logged-in user (not generated by WooCommerce).
	 *
	 * @param \WP_Comment $note Note.
	 *
	 * @return bool True for a staff note.
	 */
	private static function is_staff_note( \WP_Comment $note ): bool {
		$user = wp_get_current_user();

		return $user->exists() && '' !== $user->user_email && strtolower( $note->comment_author_email ) === strtolower( $user->user_email );
	}
}
