<?php
/**
 * Tests for the AuthSensor.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Sensors;

use WPActivityLedger\Plugin;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * Fires the real WordPress hooks; the plugin's own sensor and recorder respond.
 *
 * @covers \WPActivityLedger\Sensors\AuthSensor
 */
final class AuthSensorTest extends IntegrationTestCase {

	/**
	 * Returns stored events of a type for an object label.
	 *
	 * @param string $type  Type.
	 * @param string $label Label.
	 *
	 * @return array<int, array<string, mixed>> Events.
	 */
	private static function events( string $type, string $label ): array {
		Plugin::instance()->recorder()->flush();

		return Plugin::instance()->repository()->query(
			array(
				'type'   => $type,
				'search' => $label,
			)
		)['items'];
	}

	/**
	 * Login and logout are recorded for the right user, with a session hash on login.
	 *
	 * @return void
	 */
	public function test_login_and_logout(): void {
		$user = $this->as_user( 'author' );
		wp_set_current_user( 0 );

		do_action( 'set_logged_in_cookie', 'cookie', 0, 0, $user->ID, 'logged_in', 'test-token-123' );
		do_action( 'wp_login', $user->user_login, $user );
		do_action( 'wp_logout', $user->ID );

		$login  = self::events( 'auth.login', $user->user_login );
		$logout = self::events( 'auth.logout', $user->user_login );

		$this->assertCount( 1, $login );
		$this->assertSame( $user->ID, $login[0]['user_id'] );
		$this->assertSame( array( 'author' ), $login[0]['user_roles'] );
		$this->assertSame( substr( hash( 'sha256', 'test-token-123' ), 0, 16 ), $login[0]['session_hash'] );
		$this->assertSame( "{$user->user_login} logged in", $login[0]['message'] );
		$this->assertCount( 1, $logout );
		$this->assertSame( $user->ID, $logout[0]['user_id'] );
	}

	/**
	 * Reset request, reset completion and application password create/revoke are recorded.
	 *
	 * @return void
	 */
	public function test_password_reset_and_app_passwords(): void {
		$owner = $this->as_user( 'editor' );
		wp_set_current_user( 0 );

		do_action( 'retrieve_password', $owner->user_login );
		do_action( 'after_password_reset', $owner, 'new-secret' );

		$this->as_user( 'administrator' );
		$created = \WP_Application_Passwords::create_new_application_password( $owner->ID, array( 'name' => 'CI deploy' ) );
		$this->assertIsArray( $created );
		\WP_Application_Passwords::delete_application_password( $owner->ID, $created[1]['uuid'] );

		$this->assertCount( 1, self::events( 'auth.password_reset_requested', $owner->user_login ) );

		$reset = self::events( 'auth.password_reset', $owner->user_login );
		$this->assertCount( 1, $reset );
		$this->assertSame( $owner->ID, $reset[0]['user_id'], 'The resetting user is the actor.' );

		$app = self::events( 'auth.app_password_created', $owner->user_login );
		$this->assertCount( 1, $app );
		$this->assertSame( 'CI deploy', $app[0]['context']['app_name'] );
		$this->assertStringNotContainsString( $created[0], wp_json_encode( $app[0] ), 'The password itself is never stored.' );
		$this->assertSame( "Application password \"CI deploy\" created for \"{$owner->user_login}\"", $app[0]['message'] );
		$this->assertCount( 1, self::events( 'auth.app_password_revoked', $owner->user_login ) );
	}

	/**
	 * Three failures (separate requests) fold into one row with count 3.
	 *
	 * @return void
	 */
	public function test_failed_logins_are_folded(): void {
		wp_set_current_user( 0 );
		$username = 'wpalnobody' . strtolower( wp_generate_password( 6, false ) );

		for ( $i = 0; $i < 3; $i++ ) {
			do_action( 'wp_login_failed', $username, new \WP_Error() );
			Plugin::instance()->recorder()->flush();
		}

		$failed = self::events( 'auth.login_failed', $username );

		$this->assertCount( 1, $failed );
		$this->assertSame( 3, $failed[0]['context']['count'] );
		$this->assertFalse( $failed[0]['context']['user_exists'] );
		$this->assertSame( 0, $failed[0]['user_id'] );
		$this->assertSame( 2, $failed[0]['severity'] );
		$this->assertSame( "Failed login for \"$username\" (3 attempts)", $failed[0]['message'] );
	}
}
