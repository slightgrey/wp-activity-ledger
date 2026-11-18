<?php
/**
 * Tests for /settings and /users.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Rest;

use WPActivityLedger\Domain\Event;
use WPActivityLedger\Plugin;

/**
 * @covers \WPActivityLedger\Rest\SettingsController
 * @covers \WPActivityLedger\Rest\UsersController
 * @covers \WPActivityLedger\Database\UserActivity
 */
final class SettingsUsersControllerTest extends RestTestCase {

	/**
	 * Invalid settings give 400 with field errors; valid ones persist and are recorded.
	 *
	 * @return void
	 */
	public function test_settings_update(): void {
		$this->as_user( 'administrator' );
		$before = (int) Plugin::instance()->settings()->get( 'retention_days' );

		$invalid = self::request( 'POST', '/settings', array( 'retention_days' => 'forever' ) );
		$this->assertSame( 400, $invalid->get_status() );
		$this->assertArrayHasKey( 'retention_days', $invalid->get_data()['data']['errors'] );

		$start = self::max_event_id();
		$value = 120 === $before ? 121 : 120;
		$valid = self::request( 'POST', '/settings', array( 'retention_days' => $value ) );

		$this->assertSame( 200, $valid->get_status() );
		$this->assertSame( $value, $valid->get_data()['retention_days'] );
		$this->assertSame( $value, (int) get_option( 'wpal_settings' )['retention_days'] );

		self::flush();
		$self = self::request( 'GET', '/events', array( 'type' => 'system.settings_changed' ) )->get_data();
		$this->assertNotSame( array(), self::newer_than( $self, $start ) );
		$this->assertSame( 403, $this->editor_request()->get_status() );
	}

	/**
	 * /users counts events, logins and failed logins per user, and searches.
	 *
	 * @return void
	 */
	public function test_users_activity_and_search(): void {
		$this->as_user( 'administrator' );
		$alice = self::user( 'wpal_alice' );
		$bob   = self::user( 'wpal_bob' );

		$recorder = Plugin::instance()->recorder();
		$recorder->record( new Event( 'auth.login', user_id: $alice, user_login: 'wpal_alice' ) );
		$recorder->record( new Event( 'post.updated', user_id: $alice, user_login: 'wpal_alice', object_type: 'post', object_id: '1' ) );
		$recorder->record( new Event( 'post.updated', user_id: $bob, user_login: 'wpal_bob', object_type: 'post', object_id: '1' ) );
		$recorder->record( new Event( 'auth.login_failed', object_type: 'user', object_id: (string) $bob, context: array( 'count' => 3 ) ) );
		self::flush();

		$items = array_column( self::request( 'GET', '/users', array( 'search' => 'wpal_' ) )->get_data(), null, 'login' );

		$this->assertSame( 2, $items['wpal_alice']['events'] );
		$this->assertNotNull( $items['wpal_alice']['last_login'] );
		$this->assertSame( 1, $items['wpal_bob']['events'] );
		$this->assertNull( $items['wpal_bob']['last_login'] );
		$this->assertSame( 3, $items['wpal_bob']['failed_logins'] );
		$this->assertSame( array( 'subscriber' ), $items['wpal_bob']['roles'] );

		$this->assertSame( array( 'wpal_bob' ), array_column( self::request( 'GET', '/users', array( 'search' => 'wpal_bo' ) )->get_data(), 'login' ) );
	}

	/**
	 * Creates a subscriber.
	 *
	 * @param string $login Login.
	 *
	 * @return int User ID.
	 */
	private static function user( string $login ): int {
		return (int) wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password(),
				'user_email' => $login . '@example.test',
				'role'       => 'subscriber',
			)
		);
	}

	/**
	 * Sends a settings read as an editor.
	 *
	 * @return \WP_REST_Response Response.
	 */
	private function editor_request(): \WP_REST_Response {
		$this->as_user( 'editor' );

		return self::request( 'GET', '/settings' );
	}
}
