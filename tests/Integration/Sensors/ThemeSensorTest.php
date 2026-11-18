<?php
/**
 * Tests for the ThemeSensor.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Sensors;

use WPActivityLedger\Plugin;
use WPActivityLedger\Sensors\ThemeSensor;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

// phpcs:disable WordPress.Security.NonceVerification.Missing -- Tests fill $_POST themselves.

/**
 * Drives the hooks directly; no theme is switched, deleted or edited for real.
 *
 * @covers \WPActivityLedger\Sensors\ThemeSensor
 */
final class ThemeSensorTest extends IntegrationTestCase {

	/**
	 * Clears $_POST.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * Returns the newest events of a type.
	 *
	 * @param string $type Event type.
	 * @param int    $after Only events with a higher ID.
	 *
	 * @return array<int, array<string, mixed>> Events, newest first.
	 */
	private static function latest( string $type, int $after ): array {
		Plugin::instance()->recorder()->flush();

		return self::newer_than( Plugin::instance()->repository()->query( array( 'type' => $type ) )['items'], $after );
	}

	/**
	 * Returns the theme sensor.
	 *
	 * @return ThemeSensor Sensor.
	 */
	private function sensor(): ThemeSensor {
		$sensor = Plugin::instance()->core_sensors()['themes'];
		$this->assertInstanceOf( ThemeSensor::class, $sensor );

		return $sensor;
	}

	/**
	 * Switch (warning) and delete are recorded with theme names.
	 *
	 * @return void
	 */
	public function test_switch_and_delete(): void {
		$this->as_user( 'administrator' );
		$start   = self::max_event_id();
		$current = wp_get_theme();

		do_action( 'switch_theme', 'New Theme', $current, wp_get_theme( 'twentytwentyfour' ) );
		do_action( 'delete_theme', $current->get_stylesheet() );
		do_action( 'deleted_theme', $current->get_stylesheet(), true );

		$switched = self::latest( 'theme.switched', $start );
		$this->assertCount( 1, $switched );
		$this->assertSame( 2, $switched[0]['severity'] );
		$this->assertCount( 1, self::latest( 'theme.deleted', $start ) );
		$this->assertSame( "Deleted theme \"{$current->get( 'Name' )}\"", self::latest( 'theme.deleted', $start )[0]['message'] );
	}

	/**
	 * A plugin file editor save with a valid nonce is critical; a bad nonce is ignored.
	 *
	 * @return void
	 */
	public function test_file_editor(): void {
		$this->single_site_only( 'On multisite only super admins can edit theme files.' );

		$this->as_user( 'administrator' );
		$start = self::max_event_id();

		$_POST = array(
			'file'   => 'hello.php',
			'plugin' => 'hello.php',
			'nonce'  => 'forged',
		);
		$this->sensor()->on_file_edit();

		$_POST['nonce'] = wp_create_nonce( 'edit-plugin_hello.php' );
		$this->sensor()->on_file_edit();

		$_POST = array(
			'file'  => 'style.css',
			'theme' => get_stylesheet(),
			'nonce' => wp_create_nonce( 'edit-theme_' . get_stylesheet() . '_style.css' ),
		);
		$this->sensor()->on_file_edit();

		$plugin_edits = self::latest( 'plugin.file_edited', $start );
		$this->assertCount( 1, $plugin_edits, 'The forged request is ignored.' );
		$this->assertSame( 3, $plugin_edits[0]['severity'] );
		$this->assertSame( 'Edited plugin file hello.php', $plugin_edits[0]['message'] );
		$this->assertSame( get_stylesheet() . '/style.css', self::latest( 'theme.file_edited', $start )[0]['context']['file'] );
	}
}
