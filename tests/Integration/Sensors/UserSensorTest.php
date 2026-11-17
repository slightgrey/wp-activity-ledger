<?php
/**
 * Tests for the UserSensor.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Sensors;

use WPActivityLedger\Plugin;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * @covers \WPActivityLedger\Sensors\UserSensor
 */
final class UserSensorTest extends IntegrationTestCase {

	/**
	 * Returns stored events about one user account.
	 *
	 * @param int    $user_id User ID.
	 * @param string $type    Optional type filter.
	 *
	 * @return array<int, array<string, mixed>> Events, oldest first.
	 */
	private static function events( int $user_id, string $type = '' ): array {
		$sensor = Plugin::instance()->core_sensors()['users'];
		self::assertInstanceOf( \WPActivityLedger\Sensors\UserSensor::class, $sensor );
		$sensor->record_role_deltas();
		Plugin::instance()->recorder()->flush();

		return Plugin::instance()->repository()->query(
			array(
				'object_type' => 'user',
				'object_id'   => (string) $user_id,
				'type'        => $type,
				'orderby'     => 'id',
				'order'       => 'asc',
			)
		)['items'];
	}

	/**
	 * Creates a user and returns its ID.
	 *
	 * @param string $role Role.
	 *
	 * @return int User ID.
	 */
	private static function create_user( string $role ): int {
		$id = wp_insert_user(
			array(
				'user_login' => 'wpal_target_' . strtolower( wp_generate_password( 6, false ) ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'wpal-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test',
				'role'       => $role,
			)
		);

		return is_int( $id ) ? $id : 0;
	}

	/**
	 * Creating an admin is critical and doesn't also log a bogus role change.
	 *
	 * @return void
	 */
	public function test_create_admin_is_critical(): void {
		$actor   = $this->as_user( 'administrator' );
		$user_id = self::create_user( 'administrator' );
		$events  = self::events( $user_id );

		$this->assertSame( array( 'user.created' ), array_column( $events, 'event_type' ) );
		$this->assertSame( 3, $events[0]['severity'] );
		$this->assertSame( $actor->ID, $events[0]['user_id'] );
		$this->assertSame( 'administrator', $events[0]['context']['role'] );
	}

	/**
	 * A role change records old → new as a diff; a promotion to admin is critical.
	 *
	 * @return void
	 */
	public function test_role_change_diff(): void {
		$this->as_user( 'administrator' );
		$user_id = self::create_user( 'subscriber' );
		$user    = new \WP_User( $user_id );

		$user->set_role( 'editor' );
		$user->set_role( 'administrator' );

		$changes = self::events( $user_id, 'user.role_changed' );

		$this->assertCount( 2, $changes );
		$this->assertSame( 1, $changes[0]['change_count'] );
		$this->assertSame( 'Changed role of "' . $user->user_login . '" from subscriber to editor', $changes[0]['message'] );
		$this->assertSame( 3, $changes[1]['severity'] );

		$detail = Plugin::instance()->repository()->find( $changes[0]['id'] );
		$this->assertSame( array( 'subscriber' ), $detail['changes'][0]['old'] ?? null );
		$this->assertSame( array( 'editor' ), $detail['changes'][0]['new'] ?? null );
	}

	/**
	 * Adding and removing extra roles are recorded.
	 *
	 * @return void
	 */
	public function test_add_and_remove_role(): void {
		$this->as_user( 'administrator' );
		$user_id = self::create_user( 'author' );
		$user    = new \WP_User( $user_id );

		$user->add_role( 'editor' );
		$user->remove_role( 'editor' );

		$this->assertSame( array( 'user.created', 'user.role_added', 'user.role_removed' ), array_column( self::events( $user_id ), 'event_type' ) );
	}

	/**
	 * Deletion keeps the login snapshot and the reassign target.
	 *
	 * @return void
	 */
	public function test_delete_with_reassign(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$actor   = $this->as_user( 'administrator' );
		$user_id = self::create_user( 'author' );
		$login   = get_userdata( $user_id )->user_login;

		wp_delete_user( $user_id, $actor->ID );

		$deleted = self::events( $user_id, 'user.deleted' );

		$this->assertCount( 1, $deleted );
		$this->assertSame( $login, $deleted[0]['object_label'] );
		$this->assertSame( $actor->user_login, $deleted[0]['context']['reassign_to'] );
		$this->assertSame( "Deleted user \"$login\"", $deleted[0]['message'] );
		$this->assertFalse( get_userdata( $user_id ) );
	}
}
