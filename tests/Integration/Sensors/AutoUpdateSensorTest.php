<?php
/**
 * Tests for the AutoUpdateSensor.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Sensors;

use WPActivityLedger\Plugin;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * @covers \WPActivityLedger\Sensors\AutoUpdateSensor
 */
final class AutoUpdateSensorTest extends IntegrationTestCase {

	/**
	 * Returns events of a type created after an ID, newest first.
	 *
	 * @param string $type  Event type.
	 * @param int    $after ID boundary.
	 *
	 * @return array<int, array<string, mixed>> Events.
	 */
	private static function latest( string $type, int $after ): array {
		Plugin::instance()->recorder()->flush();

		return self::newer_than( Plugin::instance()->repository()->query( array( 'type' => $type ) )['items'], $after );
	}

	/**
	 * Turning plugin auto-updates on and off gives one event per change with the state.
	 *
	 * @return void
	 */
	public function test_plugin_toggle(): void {
		$this->requires_hello_dolly();

		$this->as_user( 'administrator' );
		$start  = self::max_event_id();
		$before = (array) get_site_option( 'auto_update_plugins', array() );

		update_site_option( 'auto_update_plugins', array_values( array_unique( array_merge( $before, array( 'hello.php' ) ) ) ) );
		update_site_option( 'auto_update_plugins', array_values( array_diff( $before, array( 'hello.php' ) ) ) );

		$events = self::latest( 'plugin.auto_update_changed', $start );

		$this->assertCount( 2, $events );
		$this->assertSame( 'Turned auto-updates off for plugin "Hello Dolly"', $events[0]['message'] );
		$this->assertSame( 'Turned auto-updates on for plugin "Hello Dolly"', $events[1]['message'] );
		$this->assertTrue( apply_filters( 'wpal_option_is_claimed', false, 'auto_update_plugins' ) );
	}

	/**
	 * Core auto-update settings changes are recorded with a diff.
	 *
	 * @return void
	 */
	public function test_core_setting(): void {
		$this->as_user( 'administrator' );
		$start = self::max_event_id();

		update_site_option( 'auto_update_core_major', 'enabled' === get_site_option( 'auto_update_core_major' ) ? 'unset' : 'enabled' );

		$this->assertCount( 1, self::latest( 'core.auto_update_changed', $start ) );
	}
}
