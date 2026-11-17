<?php
/**
 * Tests for the UpgraderSensor.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Sensors;

use WPActivityLedger\Plugin;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * Drives the upgrader hooks with stand-in upgraders; nothing is downloaded or installed.
 *
 * @covers \WPActivityLedger\Sensors\UpgraderSensor
 */
final class UpgraderSensorTest extends IntegrationTestCase {

	/**
	 * Loads the upgrader classes, as a real update request does (core listens on these hooks too).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}

	/**
	 * Returns the newest events of a type, newest first.
	 *
	 * @param string $type Event type.
	 *
	 * @return array<int, array<string, mixed>> Events.
	 */
	private static function latest( string $type ): array {
		Plugin::instance()->recorder()->flush();

		return Plugin::instance()->repository()->query( array( 'type' => $type ) )['items'];
	}

	/**
	 * A plugin install from an uploaded zip records name, version and source.
	 *
	 * @return void
	 */
	public function test_plugin_install_from_upload(): void {
		$this->as_user( 'administrator' );

		$upgrader       = new class() {
			/**
			 * Installer skin.
			 *
			 * @var object
			 */
			public object $skin;

			/**
			 * Sets the skin.
			 */
			public function __construct() {
				$this->skin = (object) array( 'type' => 'upload' );
			}

			/**
			 * Returns the installed plugin file.
			 *
			 * @return string File.
			 */
			public function plugin_info(): string {
				return 'hello.php';
			}
		};

		do_action(
			'upgrader_process_complete',
			$upgrader,
			array(
				'action' => 'install',
				'type'   => 'plugin',
			)
		);

		$event = self::latest( 'plugin.installed' )[0];
		$this->assertSame( 'Hello Dolly', $event['object_label'] );
		$this->assertSame( 'upload', $event['context']['source'] );
		$this->assertStringStartsWith( 'Installed plugin "Hello Dolly" ', $event['message'] );
	}

	/**
	 * A bulk plugin update records one event per plugin with from → to versions.
	 *
	 * @return void
	 */
	public function test_bulk_plugin_update(): void {
		$this->as_user( 'administrator' );
		$version = get_plugin_data( WP_PLUGIN_DIR . '/hello.php', false, false )['Version'];

		apply_filters( 'upgrader_pre_install', true, array( 'plugin' => 'hello.php' ) );
		apply_filters( 'upgrader_pre_install', true, array( 'plugin' => 'akismet/akismet.php' ) );
		do_action(
			'upgrader_process_complete',
			new \stdClass(),
			array(
				'action'  => 'update',
				'type'    => 'plugin',
				'bulk'    => true,
				'plugins' => array( 'hello.php', 'akismet/akismet.php' ),
			)
		);

		$events = self::latest( 'plugin.updated' );
		$this->assertSame( array( 'akismet/akismet.php', 'hello.php' ), array_column( array_slice( $events, 0, 2 ), 'object_id' ) );
		$this->assertSame( "Updated plugin \"Hello Dolly\" from $version to $version", $events[1]['message'] );
	}

	/**
	 * A theme update and a core update are recorded with versions.
	 *
	 * @return void
	 */
	public function test_theme_and_core_update(): void {
		global $wp_version;

		$this->as_user( 'administrator' );
		$theme = get_stylesheet();

		apply_filters( 'upgrader_pre_install', true, array( 'theme' => $theme ) );
		do_action(
			'upgrader_process_complete',
			new \stdClass(),
			array(
				'action' => 'update',
				'type'   => 'theme',
				'themes' => array( $theme ),
			)
		);
		// Core's own listener on _core_updated_successfully redirects and exits, so call ours directly.
		$sensor = Plugin::instance()->core_sensors()['upgrader'];
		$this->assertInstanceOf( \WPActivityLedger\Sensors\UpgraderSensor::class, $sensor );
		$sensor->on_core_updated( '99.0' );

		$this->assertSame( $theme, self::latest( 'theme.updated' )[0]['object_id'] );
		$this->assertSame( "Updated WordPress from $wp_version to 99.0", self::latest( 'core.updated' )[0]['message'] );
	}
}
