<?php
/**
 * Tests for the WordPress dashboard widget.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Admin;

use WPActivityLedger\Admin\DashboardWidget;
use WPActivityLedger\Database\EventRepository;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Plugin;
use WPActivityLedger\Rest\UiPrefs;
use WPActivityLedger\Settings\Settings;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * @covers \WPActivityLedger\Admin\DashboardWidget
 */
final class DashboardWidgetTest extends IntegrationTestCase {

	/**
	 * Loads the dashboard API on the dashboard screen.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		set_current_screen( 'dashboard' );

		$GLOBALS['wp_meta_boxes'] = array();
	}

	/**
	 * Leaves the admin screen again (it makes is_admin() true for later tests).
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['current_screen'] = null;
		$GLOBALS['wp_meta_boxes']  = array();

		parent::tearDown();
	}

	/**
	 * Tells whether the widget was added.
	 *
	 * @return bool True when registered.
	 */
	private static function added(): bool {
		return isset( $GLOBALS['wp_meta_boxes']['dashboard']['normal']['core'][ DashboardWidget::ID ] );
	}

	/**
	 * Builds a widget with the given settings.
	 *
	 * @param array<string, mixed> $stored Stored settings.
	 *
	 * @return DashboardWidget Widget.
	 */
	private static function widget( array $stored = array() ): DashboardWidget {
		return new DashboardWidget( Plugin::instance()->repository(), new Settings( $stored ) );
	}

	/**
	 * Admins get the widget, with the latest events and a link to the log.
	 *
	 * @return void
	 */
	public function test_admin_sees_widget(): void {
		$this->as_user( 'administrator' );
		Plugin::instance()->recorder()->record( new Event( 'system.settings_changed', object_type: 'settings', object_label: 'Widget check' ) );
		Plugin::instance()->recorder()->flush();

		self::widget()->add();
		$this->assertTrue( self::added() );

		ob_start();
		self::widget()->render();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/\d+ events? in the last 24 hours/', $html );
		$this->assertStringContainsString( 'page=wp-activity-ledger#/log', $html );
		$this->assertStringContainsString( '<li', $html );
	}

	/**
	 * Routine System events are left out unless the viewer turned on "Show system events".
	 *
	 * @return void
	 */
	public function test_follows_show_system_pref(): void {
		$admin  = $this->as_user( 'administrator' );
		$marker = 'Routine system event ' . wp_generate_password( 6, false );
		( new EventRepository() )->insert_many( array( new Event( 'media.uploaded', category: 'media', severity: 0, message: $marker, created_at: gmdate( 'Y-m-d H:i:s', time() + 60 ) ) ) );

		$render = static function (): string {
			ob_start();
			self::widget()->render();

			return (string) ob_get_clean();
		};

		$this->assertStringNotContainsString( $marker, $render() );

		UiPrefs::save( $admin->ID, array( 'show_system' => true ) );
		$this->assertStringContainsString( $marker, $render() );
	}

	/**
	 * Editors don't get it, and nobody does when it is turned off.
	 *
	 * @return void
	 */
	public function test_hidden_for_editor_and_when_disabled(): void {
		$this->as_user( 'editor' );
		self::widget()->add();
		$this->assertFalse( self::added() );

		$this->as_user( 'administrator' );
		self::widget( array( 'dashboard_widget' => false ) )->add();
		$this->assertFalse( self::added() );
	}
}
