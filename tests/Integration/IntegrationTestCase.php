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

		// Record and store what the test left pending (end-of-request work) inside the
		// transaction, so it is rolled back instead of being written at the real shutdown.
		self::settle_all();
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();

		// In-memory state the rollback can't reach: loaded settings and role capabilities.
		\WPActivityLedger\Plugin::instance()->settings()->reload();
		wp_roles()->for_site();
		wp_set_current_user( $this->previous_user_id );

		parent::tearDown();
	}

	/**
	 * Runs every sensor's and integration's end-of-request recording, then flushes.
	 *
	 * @return void
	 */
	protected static function settle_all(): void {
		$plugin = \WPActivityLedger\Plugin::instance();

		foreach ( $plugin->core_sensors() as $sensor ) {
			foreach ( array( 'record_pending', 'record_role_deltas' ) as $method ) {
				if ( method_exists( $sensor, $method ) ) {
					$sensor->$method();
				}
			}
		}

		foreach ( array_keys( $plugin->integrations()->active() ) as $slug ) {
			$integration = $plugin->integrations()->get( $slug );

			if ( null !== $integration && method_exists( $integration, 'record_pending' ) ) {
				$integration->record_pending();
			}
		}

		$plugin->recorder()->flush();
	}

	/**
	 * Returns the highest event ID so far, so tests can ignore rows from real site activity.
	 *
	 * @return int Highest ID (0 when empty).
	 */
	protected static function max_event_id(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB -- Test helper on the plugin's own table.
		return (int) $wpdb->get_var( 'SELECT IFNULL(MAX(id), 0) FROM ' . \WPActivityLedger\Database\Schema::events_table() );
	}

	/**
	 * Keeps only events newer than an ID.
	 *
	 * @param array<int, array<string, mixed>> $events Events.
	 * @param int                              $after  ID boundary.
	 *
	 * @return array<int, array<string, mixed>> Newer events, re-indexed.
	 */
	protected static function newer_than( array $events, int $after ): array {
		return array_values( array_filter( $events, static fn( array $e ): bool => (int) $e['id'] > $after ) );
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
