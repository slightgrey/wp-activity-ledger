<?php
/**
 * Tests for POST /purge and the settings it sits next to.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Rest;

use WPActivityLedger\Domain\Event;
use WPActivityLedger\Plugin;
use WPActivityLedger\Security\Capabilities;

/**
 * @covers \WPActivityLedger\Rest\PurgeController
 */
final class PurgeControllerTest extends RestTestCase {

	/**
	 * Purging older than today leaves today's events plus the purge record.
	 *
	 * @return void
	 */
	public function test_purge_before_today(): void {
		global $wpdb;

		$this->as_user( 'administrator' );
		$recorder = Plugin::instance()->recorder();
		$recorder->record( new Event( 'post.updated', object_type: 'post', object_id: '1', created_at: '2020-01-01 10:00:00' ) );
		$recorder->record( new Event( 'post.updated', object_type: 'post', object_id: '2', created_at: gmdate( 'Y-m-d H:i:s' ) ) );
		self::flush();

		$today = wp_date( 'Y-m-d' );
		$bad   = self::request(
			'POST',
			'/purge',
			array(
				'mode'    => 'before',
				'before'  => $today,
				'confirm' => 'purge',
			)
		);
		$this->assertSame( 400, $bad->get_status() );

		$result = self::request(
			'POST',
			'/purge',
			array(
				'mode'    => 'before',
				'before'  => $today,
				'confirm' => 'PURGE',
			)
		);
		$this->assertSame( 200, $result->get_status() );
		$this->assertGreaterThanOrEqual( 1, $result->get_data()['deleted'] );

		$midnight = ( new \DateTimeImmutable( $today . ' 00:00:00', wp_timezone() ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$older    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpal_events WHERE created_at < %s", $midnight ) );
		$this->assertSame( 0, $older );

		$purged = Plugin::instance()->repository()->query( array( 'type' => 'system.logs_purged' ) )['items'];
		$this->assertSame( 'before', $purged[0]['context']['mode'] );
		$this->assertNotSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpal_events WHERE object_id = '2'" ) );
	}

	/**
	 * A viewer without the delete capability gets 403; filter mode needs a filter.
	 *
	 * @return void
	 */
	public function test_permissions_and_filter_mode(): void {
		Capabilities::sync_role_grants( array( 'editor' ), array() );
		$this->as_user( 'editor' );
		$this->assertSame( 200, self::request( 'GET', '/events' )->get_status() );
		$this->assertSame(
			403,
			self::request(
				'POST',
				'/purge',
				array(
					'mode'    => 'all',
					'confirm' => 'PURGE',
				)
			)->get_status()
		);

		$this->as_user( 'administrator' );
		$this->assertSame(
			400,
			self::request(
				'POST',
				'/purge',
				array(
					'mode'    => 'filter',
					'filters' => array(),
					'confirm' => 'PURGE',
				)
			)->get_status()
		);
	}

	/**
	 * Turning on "delete on uninstall" is recorded with its diff.
	 *
	 * @return void
	 */
	public function test_uninstall_toggle_is_recorded(): void {
		$this->as_user( 'administrator' );
		$start = self::max_event_id();
		$value = ! Plugin::instance()->settings()->get( 'delete_data_on_uninstall' );

		$this->assertSame( 200, self::request( 'POST', '/settings', array( 'delete_data_on_uninstall' => $value ) )->get_status() );
		self::flush();

		$events = self::newer_than( Plugin::instance()->repository()->query( array( 'type' => 'system.settings_changed' ) )['items'], $start );
		$event  = Plugin::instance()->repository()->find( $events[0]['id'] );
		$fields = array_column( $event['changes'], null, 'field' );

		$this->assertArrayHasKey( 'delete_data_on_uninstall', $fields );
	}

	/**
	 * Logging only some roles stops recording the others' actions.
	 *
	 * @return void
	 */
	public function test_excluding_administrators(): void {
		$this->as_user( 'administrator' );
		self::request( 'POST', '/settings', array( 'logged_roles' => array( 'editor' ) ) );
		$recorder = Plugin::instance()->recorder();
		$before   = count( $recorder->pending() );

		$recorder->record( new Event( 'post.updated', object_type: 'post', object_id: '9' ) );

		$this->assertCount( $before, $recorder->pending() );
	}
}
