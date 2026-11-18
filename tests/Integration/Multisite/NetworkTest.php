<?php
/**
 * Multisite tests (run on a network: WPAL_TEST_ROOT pointing at one, from a sub-site).
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Multisite;

use WPActivityLedger\Domain\Event;
use WPActivityLedger\Lifecycle\Network;
use WPActivityLedger\Plugin;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * @covers \WPActivityLedger\Lifecycle\Network
 * @covers \WPActivityLedger\Recording\Recorder
 * @covers \WPActivityLedger\Sensors\MultisiteSensor
 */
final class NetworkTest extends IntegrationTestCase {

	/**
	 * Skips on single sites.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Not a multisite install.' );
		}
	}

	/**
	 * Counts rows of an event type in a site's log.
	 *
	 * @param int    $site_id Site ID.
	 * @param string $type    Event type.
	 *
	 * @return int Rows.
	 */
	private static function count_in( int $site_id, string $type ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->get_blog_prefix( $site_id ) . 'wpal_events WHERE event_type = %s', $type ) );
	}

	/**
	 * Network events recorded on a sub-site go to the main site's log; others stay local.
	 *
	 * @return void
	 */
	public function test_network_events_go_to_main_site(): void {
		if ( is_main_site() ) {
			$this->markTestSkipped( 'Run from a sub-site (WPAL_TEST_PATH=plugin-ms/site2/).' );
		}

		$main     = get_main_site_id();
		$here     = get_current_blog_id();
		$before   = array( self::count_in( $main, 'multisite.site_archived' ), self::count_in( $here, 'multisite.site_archived' ) );
		$local    = self::count_in( $here, 'system.settings_changed' );
		$recorder = Plugin::instance()->recorder();

		$recorder->record( new Event( 'multisite.site_archived', object_type: 'site', object_id: '99', context: array( 'network' => true ) ) );
		$recorder->record( new Event( 'system.settings_changed', object_type: 'settings' ) );
		$recorder->flush();

		$this->assertSame( $before[0] + 1, self::count_in( $main, 'multisite.site_archived' ) );
		$this->assertSame( $before[1], self::count_in( $here, 'multisite.site_archived' ) );
		$this->assertSame( $local + 1, self::count_in( $here, 'system.settings_changed' ) );
		$this->assertSame( $here, get_current_blog_id(), 'The blog is restored.' );
	}

	/**
	 * Deleting a site drops its log tables too.
	 *
	 * @return void
	 */
	public function test_drop_tables_lists_the_site_tables(): void {
		global $wpdb;

		$tables = apply_filters( 'wpmu_drop_tables', array( 'x' ), 7 );
		$prefix = $wpdb->get_blog_prefix( 7 );

		$this->assertContains( $prefix . 'wpal_events', $tables );
		$this->assertContains( $prefix . 'wpal_event_changes', $tables );
		$this->assertTrue( Network::network_active() );
	}

	/**
	 * Network option changes are grouped into one network event.
	 *
	 * @return void
	 */
	public function test_network_option_changes(): void {
		$main   = get_main_site_id();
		$before = self::count_in( $main, 'multisite.network_settings_changed' );

		update_site_option( 'wpal_test_network_flag', 'a' );
		update_site_option( 'acme_network_mode', 'x' . wp_rand() );
		update_site_option( 'acme_network_mode', 'y' . wp_rand() );

		$sensor = Plugin::instance()->core_sensors()['multisite'];
		$this->assertTrue( method_exists( $sensor, 'record_pending' ) );
		$sensor->record_pending();
		Plugin::instance()->recorder()->flush();

		$this->assertSame( $before + 1, self::count_in( $main, 'multisite.network_settings_changed' ) );
	}
}
