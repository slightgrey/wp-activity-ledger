<?php
/**
 * Base class for integration tests against a real WordPress database.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Wraps every test in a database transaction that is rolled back afterwards, so
 * tests can insert users, posts and events without leaving anything behind.
 *
 * Caveat: DDL statements (CREATE/ALTER/DROP TABLE, which dbDelta runs) commit the
 * open transaction implicitly in MySQL. Tests that run DDL must clean up themselves.
 */
abstract class IntegrationTestCase extends TestCase {

	/**
	 * User ID that was current before the test.
	 *
	 * @var int
	 */
	private int $previous_user_id = 0;

	/**
	 * Starts a transaction and remembers the current user.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		global $wpdb;

		parent::setUp();

		$this->previous_user_id = get_current_user_id();
		$wpdb->query( 'START TRANSACTION' );
	}

	/**
	 * Rolls the transaction back, restores the user and clears stale caches.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		global $wpdb;

		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();
		wp_set_current_user( $this->previous_user_id );

		parent::tearDown();
	}

	/**
	 * Creates a user with the given role (rolled back after the test) and logs in as them.
	 *
	 * @param string $role Role slug.
	 *
	 * @return \WP_User The new current user.
	 */
	protected function as_user( string $role ): \WP_User {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'wpal_test_' . $role . '_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'wpal-' . wp_generate_password( 6, false ) . '@example.test',
				'role'       => $role,
			)
		);

		$this->assertIsInt( $user_id, 'Test user could not be created.' );
		wp_set_current_user( $user_id );

		return wp_get_current_user();
	}
}
