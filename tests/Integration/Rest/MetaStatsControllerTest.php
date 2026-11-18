<?php
/**
 * Tests for /meta, /stats and /me/prefs.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Rest;

use WPActivityLedger\Rest\UiPrefs;

/**
 * @covers \WPActivityLedger\Rest\MetaController
 * @covers \WPActivityLedger\Rest\StatsController
 * @covers \WPActivityLedger\Rest\UiPrefs
 */
final class MetaStatsControllerTest extends RestTestCase {

	/**
	 * Acts as an administrator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->as_user( 'administrator' );
	}

	/**
	 * /meta lists core and active-integration types, categories, severities, roles and caps.
	 *
	 * @return void
	 */
	public function test_meta(): void {
		$data = self::request( 'GET', '/meta' )->get_data();
		$keys = array_column( $data['event_types'], 'key' );

		$this->assertContains( 'post.updated', $keys );
		$this->assertContains( 'auth.login', $keys );

		if ( class_exists( 'WooCommerce' ) ) {
			$this->assertContains( 'woocommerce.order_status_changed', $keys );
			$this->assertContains( 'woocommerce', array_column( $data['integrations'], 'slug' ) );
		}

		$this->assertContains( 'content', array_column( $data['categories'], 'slug' ) );
		$this->assertSame( array( 'info', 'notice', 'warning', 'critical' ), array_column( $data['severities'], 'slug' ) );
		$this->assertContains( 'administrator', array_column( $data['roles'], 'slug' ) );
		$this->assertTrue( $data['caps']['manage_settings'] );
		$this->assertSame( UiPrefs::defaults(), $data['prefs'] );
	}

	/**
	 * /stats returns the KPI keys and serves the second call from the cache.
	 *
	 * @return void
	 */
	public function test_stats_cached(): void {
		$args = array(
			'from' => '2026-01-01',
			'to'   => '2026-01-07',
		);
		delete_transient( \WPActivityLedger\Rest\StatsController::CACHE_PREFIX . md5( '2026-01-01|2026-01-07|' . wp_timezone_string() ) );

		$first  = self::request( 'GET', '/stats', $args );
		$second = self::request( 'GET', '/stats', $args );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 'miss', $first->get_headers()['X-WPAL-Cache'] );
		$this->assertSame( 'hit', $second->get_headers()['X-WPAL-Cache'] );

		foreach ( array( 'range', 'totals', 'by_day', 'top_users', 'top_types', 'recent_critical' ) as $key ) {
			$this->assertArrayHasKey( $key, $first->get_data() );
		}

		$this->assertSame( array( 'events', 'failed_logins', 'critical', 'users' ), array_keys( $first->get_data()['totals'] ) );
		$this->assertSame( 400, self::request( 'GET', '/stats', array( 'from' => '2026-02-01', 'to' => '2026-01-01' ) )->get_status() );
	}

	/**
	 * PUT /me/prefs saves valid prefs, drops unknown keys and values, and /meta returns them.
	 *
	 * @return void
	 */
	public function test_prefs(): void {
		$saved = self::request(
			'PUT',
			'/me/prefs',
			array(
				'columns'  => array( 'time', 'ip', 'bogus' ),
				'per_page' => 50,
				'filters'  => array(
					array(
						'name'    => 'Admins',
						'filters' => array(
							'role'  => 'administrator',
							'evil'  => 'x',
							'type'  => array( 'post.updated' ),
						),
					),
					array( 'name' => '' ),
				),
				'unknown'  => 'x',
			)
		)->get_data();

		$expected = array(
			'columns'  => array( 'time', 'ip' ),
			'per_page' => 50,
			'filters'  => array(
				array(
					'name'    => 'Admins',
					'filters' => array(
						'role' => 'administrator',
						'type' => array( 'post.updated' ),
					),
				),
			),
		);

		$this->assertSame( $expected, $saved );
		$this->assertSame( $expected, self::request( 'GET', '/meta' )->get_data()['prefs'] );

		self::request( 'PUT', '/me/prefs', array( 'per_page' => 7 ) );
		$this->assertSame( 50, self::request( 'GET', '/meta' )->get_data()['prefs']['per_page'] );
	}
}
