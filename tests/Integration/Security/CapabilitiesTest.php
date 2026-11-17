<?php
/**
 * Tests for Capabilities.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Security;

use WPActivityLedger\Security\Capabilities;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * Role changes are written to the roles option (rolled back), but WP_Roles keeps them in
 * memory, so each test restores the grants it changed.
 *
 * @covers \WPActivityLedger\Security\Capabilities
 */
final class CapabilitiesTest extends IntegrationTestCase {

	/**
	 * Removes test grants from memory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Capabilities::sync_role_grants( array(), array() );
		parent::tearDown();
	}

	/**
	 * Administrators get every capability; editors get none by default.
	 *
	 * @return void
	 */
	public function test_defaults(): void {
		Capabilities::grant_defaults();

		$this->as_user( 'administrator' );
		foreach ( Capabilities::ALL as $cap ) {
			$this->assertTrue( current_user_can( $cap ), $cap );
		}

		$this->as_user( 'editor' );
		foreach ( Capabilities::ALL as $cap ) {
			$this->assertFalse( current_user_can( $cap ), $cap );
		}
	}

	/**
	 * Granting view to editors works and can be taken back.
	 *
	 * @return void
	 */
	public function test_sync_role_grants(): void {
		Capabilities::sync_role_grants( array( 'editor' ), array() );

		$editor = $this->as_user( 'editor' );
		$this->assertTrue( current_user_can( Capabilities::VIEW ) );
		$this->assertFalse( current_user_can( Capabilities::EXPORT ) );

		Capabilities::sync_role_grants( array(), array() );
		$editor->get_role_caps();
		$this->assertFalse( user_can( $editor->ID, Capabilities::VIEW ) );
	}

	/**
	 * The network lock only applies to the lockable caps, and only on multisite.
	 *
	 * @return void
	 */
	public function test_network_lock_filter(): void {
		update_site_option( Capabilities::NETWORK_OPTION, array( 'lock' => true ) );

		$expected = is_multisite() ? array( 'manage_network_options' ) : array( Capabilities::MANAGE_SETTINGS );
		$this->assertSame( $expected, Capabilities::apply_network_lock( array( Capabilities::MANAGE_SETTINGS ), Capabilities::MANAGE_SETTINGS ) );
		$this->assertSame( array( Capabilities::VIEW ), Capabilities::apply_network_lock( array( Capabilities::VIEW ), Capabilities::VIEW ) );
	}
}
