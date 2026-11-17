<?php
/**
 * Tests for the WooCommerce integration: settings and payment gateways.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Integrations;

use WPActivityLedger\Plugin;

/**
 * @covers \WPActivityLedger\Integrations\WooSettingsWatcher
 */
final class WooCommerceSettingsTest extends WooCommerceTestCase {

	/**
	 * Request query vars set by a test.
	 *
	 * @var array<string, string>
	 */
	private array $saved_get = array();

	/**
	 * Acts as an administrator and settles earlier changes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->as_user( 'administrator' );
		$this->saved_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		self::settle();
	}

	/**
	 * Restores the query vars.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_GET = $this->saved_get;
		parent::tearDown();
	}

	/**
	 * Records pending WooCommerce changes, including settings, and flushes.
	 *
	 * @return void
	 */
	protected static function settle(): void {
		self::integration()->record_pending();
		$options = Plugin::instance()->core_sensors()['options'];
		self::assertInstanceOf( \WPActivityLedger\Sensors\OptionSensor::class, $options );
		$options->record_pending();
		Plugin::instance()->recorder()->flush();
	}

	/**
	 * Changing the store address records one settings event for the tab, not an option.updated.
	 *
	 * @return void
	 */
	public function test_store_address_change_is_one_settings_event(): void {
		$_GET = array(
			'page' => 'wc-settings',
			'tab'  => 'general',
		);

		update_option( 'woocommerce_store_address', 'Old Road 1' );
		update_option( 'woocommerce_store_city', 'Leeds' );
		self::settle();
		$start = self::max_event_id();

		update_option( 'woocommerce_store_address', 'New Street 2' );
		update_option( 'woocommerce_store_city', 'York' );

		$events = self::settings_events( $start );

		$this->assertSame( array( 'woocommerce.settings_changed' ), array_column( $events, 'event_type' ) );
		$this->assertSame( 'wc-settings:general', $events[0]['object_id'] );
		$this->assertSame( 'Changed 2 WooCommerce setting(s)', $events[0]['message'] );

		$fields = array_column( $events[0]['changes'], null, 'field' );
		$this->assertSame( 'Old Road 1', $fields['woocommerce_store_address']['old'] );
		$this->assertSame( 'New Street 2', $fields['woocommerce_store_address']['new'] );
	}

	/**
	 * Switching a payment gateway on records a toggle; its other settings go to the settings event.
	 *
	 * @return void
	 */
	public function test_gateway_toggle(): void {
		$_GET     = array(
			'page'    => 'wc-settings',
			'tab'     => 'checkout',
			'section' => 'bacs',
		);
		$settings = (array) get_option( 'woocommerce_bacs_settings', array() );
		update_option( 'woocommerce_bacs_settings', array_merge( $settings, array( 'enabled' => 'no', 'title' => 'Bank' ) ) );
		self::settle();
		$start = self::max_event_id();

		update_option( 'woocommerce_bacs_settings', array_merge( $settings, array( 'enabled' => 'yes', 'title' => 'Bank transfer' ) ) );

		$events = self::settings_events( $start );

		$this->assertSame( array( 'woocommerce.gateway_toggled', 'woocommerce.settings_changed' ), array_column( $events, 'event_type' ) );
		$this->assertSame( 'bacs', $events[0]['object_id'] );
		$this->assertStringEndsWith( '" on', $events[0]['message'] );
		$this->assertSame( 'no', $events[0]['changes'][0]['old'] );
		$this->assertSame( 'yes', $events[0]['changes'][0]['new'] );

		$this->assertSame( array( 'woocommerce_bacs_settings:title' ), array_column( $events[1]['changes'], 'field' ) );
		$this->assertSame( 'wc-settings:checkout:bacs', $events[1]['object_id'] );
	}

	/**
	 * WooCommerce's self-maintained options are not recorded.
	 *
	 * @return void
	 */
	public function test_noise_options_are_ignored(): void {
		$start = self::max_event_id();

		update_option( 'woocommerce_admin_notices', array( 'x' . wp_rand() ) );

		$this->assertSame( array(), self::settings_events( $start ) );
	}

	/**
	 * WooCommerce's own user meta (saved cart flag on login) is not a profile change.
	 *
	 * @return void
	 */
	public function test_noise_user_meta_is_ignored(): void {
		// A user from before this run (the profile sensor skips users registered in the request).
		$user_id = (int) get_users(
			array(
				'number'  => 1,
				'orderby' => 'ID',
				'fields'  => 'ID',
			)
		)[0];
		$start   = self::max_event_id();

		update_user_meta( $user_id, '_woocommerce_load_saved_cart_after_login', 'x' . wp_rand() );
		$sensor = Plugin::instance()->core_sensors()['profile'];
		self::assertInstanceOf( \WPActivityLedger\Sensors\UserProfileSensor::class, $sensor );
		$sensor->record_pending();
		Plugin::instance()->recorder()->flush();

		$items = self::newer_than(
			Plugin::instance()->repository()->query(
				array(
					'type'  => 'user.updated',
					'order' => 'desc',
				)
			)['items'],
			$start
		);

		$this->assertSame( array(), $items );
	}

	/**
	 * Settles, then returns new WooCommerce settings/gateway and core option events, oldest first.
	 *
	 * @param int $after ID boundary.
	 *
	 * @return array<int, array<string, mixed>> Events with changes.
	 */
	private static function settings_events( int $after ): array {
		self::settle();

		$items = array_reverse(
			self::newer_than(
				Plugin::instance()->repository()->query(
					array(
						'type'    => array( 'woocommerce.settings_changed', 'woocommerce.gateway_toggled', 'option.updated' ),
						'orderby' => 'id',
						'order'   => 'desc',
					)
				)['items'],
				$after
			)
		);

		return array_map( static fn( array $i ): array => (array) Plugin::instance()->repository()->find( $i['id'] ), $items );
	}
}
