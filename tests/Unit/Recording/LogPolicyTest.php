<?php
/**
 * Tests for the LogPolicy.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Unit\Recording;

use PHPUnit\Framework\TestCase;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Recording\LogPolicy;
use WPActivityLedger\Settings\Settings;

/**
 * @covers \WPActivityLedger\Recording\LogPolicy
 */
final class LogPolicyTest extends TestCase {

	/**
	 * Clears test filters.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpal_test_filters']['wpal_should_log_event'] );
	}

	/**
	 * Builds a policy with some settings changed.
	 *
	 * @param array<string, mixed> $settings Settings.
	 *
	 * @return LogPolicy Policy.
	 */
	private static function policy( array $settings = array() ): LogPolicy {
		return new LogPolicy( new Settings( $settings ) );
	}

	/**
	 * Builds an enriched-looking event.
	 *
	 * @param int                $user_id  User.
	 * @param array<int, string> $roles    Roles.
	 * @param string             $type     Type.
	 * @param string             $category Category.
	 *
	 * @return Event Event.
	 */
	private static function event( int $user_id = 5, array $roles = array( 'editor' ), string $type = 'post.updated', string $category = 'content' ): Event {
		return new Event( $type, category: $category, user_id: $user_id, user_roles: $roles );
	}

	/**
	 * Defaults log everyone.
	 *
	 * @return void
	 */
	public function test_defaults_log_everything(): void {
		$this->assertTrue( self::policy()->allows( self::event(), 'admin' ) );
		$this->assertTrue( self::policy()->allows( self::event( 0, array() ), 'web' ) );
	}

	/**
	 * Only the chosen roles are logged.
	 *
	 * @return void
	 */
	public function test_logged_roles(): void {
		$policy = self::policy( array( 'logged_roles' => array( 'administrator' ) ) );

		$this->assertFalse( $policy->allows( self::event(), 'admin' ) );
		$this->assertTrue( $policy->allows( self::event( 5, array( 'subscriber', 'administrator' ) ), 'admin' ) );
		$this->assertFalse( $policy->allows( self::event( 5, array() ), 'admin' ), 'No role on this site.' );
	}

	/**
	 * Excluded users are skipped.
	 *
	 * @return void
	 */
	public function test_excluded_user(): void {
		$this->assertFalse( self::policy( array( 'excluded_users' => array( 5 ) ) )->allows( self::event(), 'admin' ) );
		$this->assertTrue( self::policy( array( 'excluded_users' => array( 6 ) ) )->allows( self::event(), 'admin' ) );
	}

	/**
	 * Anonymous web actions follow log_anonymous; anonymous cron/CLI work is always logged.
	 *
	 * @return void
	 */
	public function test_anonymous(): void {
		$policy = self::policy( array( 'log_anonymous' => false ) );

		$this->assertFalse( $policy->allows( self::event( 0, array(), 'auth.login_failed', 'auth' ), 'web' ) );
		$this->assertTrue( $policy->allows( self::event( 0, array(), 'plugin.updated', 'plugin' ), 'cron' ) );
		$this->assertTrue( $policy->allows( self::event( 0, array(), 'plugin.updated', 'plugin' ), 'cli' ) );
	}

	/**
	 * Disabled types are skipped.
	 *
	 * @return void
	 */
	public function test_disabled_type(): void {
		$this->assertFalse( self::policy( array( 'disabled_event_types' => array( 'post.updated' ) ) )->allows( self::event(), 'admin' ) );
	}

	/**
	 * System events are kept even when every rule would skip them.
	 *
	 * @return void
	 */
	public function test_system_events_always_logged(): void {
		$policy = self::policy(
			array(
				'excluded_users'       => array( 5 ),
				'logged_roles'         => array(),
				'disabled_event_types' => array( 'system.logs_purged' ),
			)
		);

		$this->assertTrue( $policy->allows( self::event( 5, array( 'administrator' ), 'system.logs_purged', 'system' ), 'admin' ) );
	}

	/**
	 * The wpal_should_log_event filter has the final say.
	 *
	 * @return void
	 */
	public function test_filter_can_veto_and_allow(): void {
		$GLOBALS['wpal_test_filters']['wpal_should_log_event'] = static fn( bool $allowed, Event $event ): bool => 'post.updated' !== $event->event_type && ( $allowed || 'auth.login' === $event->event_type );

		$this->assertFalse( self::policy()->allows( self::event(), 'admin' ) );
		$this->assertTrue( self::policy( array( 'logged_roles' => array() ) )->allows( self::event( 5, array( 'editor' ), 'auth.login', 'auth' ), 'web' ) );
	}
}
