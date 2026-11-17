<?php
/**
 * Smoke test for the integration runner.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration;

/**
 * @coversNothing
 */
final class SmokeTest extends IntegrationTestCase {

	/**
	 * WordPress is loaded and the plugin is active.
	 *
	 * @return void
	 */
	public function test_wordpress_is_loaded(): void {
		$this->assertNotSame( '', get_bloginfo( 'name' ) );
		$this->assertTrue( defined( 'WPAL_VERSION' ) );
	}

	/**
	 * Rows written inside a test are rolled back.
	 *
	 * @return void
	 */
	public function test_as_user_creates_a_logged_in_user(): void {
		$user = $this->as_user( 'editor' );

		$this->assertSame( $user->ID, get_current_user_id() );
		$this->assertContains( 'editor', $user->roles );
		$GLOBALS['wpal_smoke_user_id'] = $user->ID;
	}

	/**
	 * The user from the previous test no longer exists.
	 *
	 * @depends test_as_user_creates_a_logged_in_user
	 *
	 * @return void
	 */
	public function test_previous_test_was_rolled_back(): void {
		$this->assertFalse( get_userdata( (int) $GLOBALS['wpal_smoke_user_id'] ) );
	}
}
