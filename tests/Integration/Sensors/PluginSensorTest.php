<?php
/**
 * Tests for the PluginSensor.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Sensors;

use WPActivityLedger\Plugin;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * Fires the plugin hooks directly, so no real plugin is activated or deleted.
 *
 * @covers \WPActivityLedger\Sensors\PluginSensor
 */
final class PluginSensorTest extends IntegrationTestCase {

	/**
	 * Returns events about one plugin file, oldest first.
	 *
	 * @param string $file Plugin file.
	 *
	 * @return array<int, array<string, mixed>> Events.
	 */
	private static function events_for( string $file ): array {
		Plugin::instance()->recorder()->flush();

		return Plugin::instance()->repository()->query(
			array(
				'object_type' => 'plugin',
				'object_id'   => $file,
				'orderby'     => 'id',
				'order'       => 'asc',
			)
		)['items'];
	}

	/**
	 * Activation, deactivation and deletion carry the plugin's name and version.
	 *
	 * @return void
	 */
	public function test_activate_deactivate_delete(): void {
		$this->as_user( 'administrator' );
		$version = get_plugin_data( WP_PLUGIN_DIR . '/hello.php', false, false )['Version'];
		$start   = self::max_event_id();

		do_action( 'activated_plugin', 'hello.php', false );
		do_action( 'deactivated_plugin', 'hello.php', false );
		do_action( 'delete_plugin', 'hello.php' );
		do_action( 'deleted_plugin', 'hello.php', true );
		do_action( 'deleted_plugin', 'hello.php', false );

		$events = self::newer_than( self::events_for( 'hello.php' ), $start );

		$this->assertSame( array( 'plugin.activated', 'plugin.deactivated', 'plugin.deleted' ), array_column( $events, 'event_type' ), 'A failed deletion is not recorded.' );
		$this->assertSame( 'Activated plugin "Hello Dolly"', $events[0]['message'] );
		$this->assertSame( $version, $events[2]['context']['version'] );
		$this->assertSame( 2, $events[0]['severity'] );
	}

	/**
	 * This plugin's own deactivation is left to the self sensor.
	 *
	 * @return void
	 */
	public function test_own_deactivation_is_skipped(): void {
		$this->as_user( 'administrator' );
		$self  = plugin_basename( WPAL_FILE );
		$start = self::max_event_id();

		do_action( 'activated_plugin', $self, false );
		do_action( 'deactivated_plugin', $self, false );

		$this->assertSame( array(), self::newer_than( self::events_for( $self ), $start ) );
	}
}
