<?php
/**
 * Tests for the sessions routes.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Rest;

/**
 * @covers \WPActivityLedger\Rest\SessionsController
 * @covers \WPActivityLedger\Security\SessionStore
 */
final class SessionsControllerTest extends RestTestCase {

	/**
	 * Lists two sessions, ends one (recorded), ends the rest; editors get 403.
	 *
	 * @return void
	 */
	public function test_list_and_destroy(): void {
		$target = (int) wp_insert_user(
			array(
				'user_login' => 'wpal_sessions_' . wp_generate_password( 4, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'wpal-s-' . wp_generate_password( 4, false ) . '@example.test',
				'role'       => 'author',
			)
		);
		$manager = \WP_Session_Tokens::get_instance( $target );
		$manager->create( time() + HOUR_IN_SECONDS );
		$manager->create( time() + HOUR_IN_SECONDS );

		$this->as_user( 'editor' );
		$this->assertSame( 403, self::request( 'GET', "/users/$target/sessions" )->get_status() );

		$this->as_admin();
		$sessions = self::request( 'GET', "/users/$target/sessions" )->get_data();
		$this->assertCount( 2, $sessions );
		$this->assertSame( substr( $sessions[0]['verifier'], 0, 16 ), $sessions[0]['session_hash'] );
		$this->assertFalse( $sessions[0]['current'] );

		$start = self::max_event_id();
		$this->assertSame( 200, self::request( 'DELETE', "/users/$target/sessions/{$sessions[0]['verifier']}" )->get_status() );
		$this->assertCount( 1, $manager->get_all() );
		$this->assertSame( 404, self::request( 'DELETE', "/users/$target/sessions/{$sessions[0]['verifier']}" )->get_status() );

		$this->assertSame( array( 'destroyed' => 1 ), self::request( 'DELETE', "/users/$target/sessions" )->get_data() );
		$this->assertSame( array(), $manager->get_all() );

		self::flush();
		$events = self::newer_than(
			self::request(
				'GET',
				'/events',
				array(
					'type'      => 'user.session_destroyed',
					'object_id' => (string) $target,
				)
			)->get_data(),
			$start
		);
		$this->assertCount( 2, $events );
		$this->assertStringStartsWith( 'Ended 1 session(s) of', $events[0]['message'] );
	}

	/**
	 * Unknown users give 404.
	 *
	 * @return void
	 */
	public function test_unknown_user(): void {
		$this->as_admin();

		$this->assertSame( 404, self::request( 'GET', '/users/999999999/sessions' )->get_status() );
	}

	/**
	 * Acts as an administrator; on multisite a super admin (only they may manage other users).
	 *
	 * @return void
	 */
	private function as_admin(): void {
		$user = $this->as_user( 'administrator' );

		if ( is_multisite() ) {
			grant_super_admin( $user->ID );
		}
	}
}
