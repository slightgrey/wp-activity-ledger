<?php
/**
 * Tests for hiding other admin notices on the plugin page.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Admin;

use WPActivityLedger\Admin\Menu;
use WPActivityLedger\Admin\NoticeSuppressor;
use WPActivityLedger\Database\Installer;
use WPActivityLedger\Settings\Settings;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * @covers \WPActivityLedger\Admin\NoticeSuppressor
 */
final class NoticeSuppressorTest extends IntegrationTestCase {

	/**
	 * Notice hooks as they were before the test.
	 *
	 * @var array<string, \WP_Hook|null>
	 */
	private array $saved = array();

	/**
	 * Admin menu with the plugin page added.
	 *
	 * @var Menu
	 */
	private Menu $menu;

	/**
	 * Saves the notice hooks, adds the plugin page and a foreign notice.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		global $wp_filter;

		parent::setUp();

		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';

		foreach ( NoticeSuppressor::HOOKS as $hook ) {
			$this->saved[ $hook ] = isset( $wp_filter[ $hook ] ) ? clone $wp_filter[ $hook ] : null;
		}

		$this->as_user( 'administrator' );
		$this->menu = new Menu();
		$this->menu->add_menu();

		// update_nag is a WordPress core function: a callback from outside the plugin.
		add_action( 'admin_notices', array( Installer::class, 'error_notice' ) );
		add_action( 'all_admin_notices', 'update_nag', 3 );
	}

	/**
	 * Restores the notice hooks and leaves the admin screen.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		global $wp_filter;

		foreach ( $this->saved as $hook => $saved ) {
			if ( null === $saved ) {
				unset( $wp_filter[ $hook ] );
			} else {
				$wp_filter[ $hook ] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring test state.
			}
		}

		$GLOBALS['current_screen'] = null;

		parent::tearDown();
	}

	/**
	 * Builds a suppressor with the given settings.
	 *
	 * @param array<string, mixed> $stored Stored settings.
	 *
	 * @return NoticeSuppressor Suppressor.
	 */
	private function suppressor( array $stored = array() ): NoticeSuppressor {
		return new NoticeSuppressor( $this->menu, new Settings( $stored ) );
	}

	/**
	 * On the plugin page, foreign notices are removed and the plugin's own are kept.
	 *
	 * @return void
	 */
	public function test_hides_foreign_notices_on_plugin_page(): void {
		set_current_screen( $this->menu->hook_suffix() );

		$suppressor = $this->suppressor();
		$suppressor->suppress();

		$this->assertFalse( has_action( 'all_admin_notices', 'update_nag' ) );
		$this->assertNotFalse( has_action( 'admin_notices', array( Installer::class, 'error_notice' ) ) );
		$this->assertStringContainsString( NoticeSuppressor::BODY_CLASS, $suppressor->body_class( 'foo' ) );
	}

	/**
	 * Other admin screens keep every notice.
	 *
	 * @return void
	 */
	public function test_other_screens_untouched(): void {
		set_current_screen( 'dashboard' );

		$suppressor = $this->suppressor();
		$suppressor->suppress();

		$this->assertNotFalse( has_action( 'all_admin_notices', 'update_nag' ) );
		$this->assertSame( 'foo', $suppressor->body_class( 'foo' ) );
	}

	/**
	 * Turning the setting off keeps every notice on the plugin page.
	 *
	 * @return void
	 */
	public function test_setting_off(): void {
		set_current_screen( $this->menu->hook_suffix() );

		$suppressor = $this->suppressor( array( 'hide_admin_notices' => false ) );
		$suppressor->suppress();

		$this->assertNotFalse( has_action( 'all_admin_notices', 'update_nag' ) );
		$this->assertSame( 'foo', $suppressor->body_class( 'foo' ) );
	}

	/**
	 * Callbacks are told apart by the file they are defined in.
	 *
	 * @return void
	 */
	public function test_is_own(): void {
		$this->assertTrue( NoticeSuppressor::is_own( array( Installer::class, 'error_notice' ) ) );
		$this->assertTrue( NoticeSuppressor::is_own( Installer::class . '::error_notice' ) );
		$this->assertTrue( NoticeSuppressor::is_own( static function (): void {} ) );
		$this->assertFalse( NoticeSuppressor::is_own( 'update_nag' ) );
		$this->assertFalse( NoticeSuppressor::is_own( 'not_a_function' ) );
	}
}
