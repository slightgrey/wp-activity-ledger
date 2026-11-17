<?php
/**
 * Tests for the SelfSensor and the deactivation log.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Sensors;

use WPActivityLedger\Lifecycle\Deactivator;
use WPActivityLedger\Plugin;
use WPActivityLedger\Settings\Settings;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * @covers \WPActivityLedger\Sensors\SelfSensor
 * @covers \WPActivityLedger\Lifecycle\Deactivator
 */
final class SelfSensorTest extends IntegrationTestCase {

	/**
	 * Returns new events of a type with their changes.
	 *
	 * @param string $type  Event type.
	 * @param int    $after ID boundary.
	 *
	 * @return array<int, array<string, mixed>> Events, newest first.
	 */
	private static function latest( string $type, int $after ): array {
		Plugin::instance()->recorder()->flush();
		$items = self::newer_than( Plugin::instance()->repository()->query( array( 'type' => $type ) )['items'], $after );

		return array_map( static fn( array $i ): array => (array) Plugin::instance()->repository()->find( $i['id'] ), $items );
	}

	/**
	 * A settings change is recorded with a diff, even by an excluded user.
	 *
	 * @return void
	 */
	public function test_settings_change(): void {
		$admin    = $this->as_user( 'administrator' );
		$start    = self::max_event_id();
		$settings = array_merge( Settings::defaults(), (array) get_option( Settings::OPTION, array() ) );
		$new      = array_merge(
			$settings,
			array(
				'retention_days' => 30 === $settings['retention_days'] ? 31 : 30,
				'excluded_users' => array( $admin->ID ),
			)
		);

		update_option( Settings::OPTION, $new );

		$events = self::latest( 'system.settings_changed', $start );
		$this->assertCount( 1, $events );
		$this->assertEqualsCanonicalizing( array( 'retention_days', 'excluded_users' ), array_column( $events[0]['changes'], 'field' ) );
		$this->assertSame( 2, $events[0]['severity'] );
	}

	/**
	 * Alert channel changes record names and types, never the config secrets.
	 *
	 * @return void
	 */
	public function test_channels_change_hides_config(): void {
		$this->as_user( 'administrator' );
		$start = self::max_event_id();

		update_option(
			'wpal_alert_channels',
			array(
				array(
					'id'     => 'c1',
					'name'   => 'Ops Slack',
					'type'   => 'slack',
					'config' => array( 'url' => 'https://hooks.slack.com/services/SECRET/TOKEN' ),
				),
			)
		);

		$events = self::latest( 'system.alert_channels_changed', $start );
		$this->assertCount( 1, $events );
		$this->assertStringNotContainsString( 'SECRET', (string) wp_json_encode( $events[0] ) );
		$this->assertSame( 'Changed alert channel "Ops Slack"', $events[0]['message'] );
	}

	/**
	 * Deactivation is stored immediately as a critical event.
	 *
	 * @return void
	 */
	public function test_deactivation_is_logged_immediately(): void {
		$this->as_user( 'administrator' );
		$start = self::max_event_id();

		Deactivator::deactivate();

		$items = self::newer_than( Plugin::instance()->repository()->query( array( 'type' => 'system.deactivated' ) )['items'], $start );
		$this->assertCount( 1, $items, 'Stored without an explicit flush.' );
		$this->assertSame( 3, $items[0]['severity'] );
	}

	/**
	 * Deactivation clears wpal_* cron events and keeps tables, settings and other events.
	 *
	 * @return void
	 */
	public function test_deactivation_clears_cron_only(): void {
		$this->as_user( 'administrator' );
		wp_schedule_single_event( time() + 3600, 'wpal_test_task' );
		wp_schedule_single_event( time() + 3600, 'wpal_test_task', array( 'x' ) );
		wp_schedule_single_event( time() + 3600, 'other_plugin_task' );

		Deactivator::deactivate();

		$this->assertFalse( wp_next_scheduled( 'wpal_test_task' ) );
		$this->assertFalse( wp_next_scheduled( 'wpal_test_task', array( 'x' ) ) );
		$this->assertNotFalse( wp_next_scheduled( 'other_plugin_task' ) );
		$this->assertTrue( \WPActivityLedger\Database\Installer::tables_exist() );
		$this->assertIsArray( get_option( Settings::OPTION ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'wpal_view_logs' ) );

		wp_clear_scheduled_hook( 'other_plugin_task' );
	}
}
