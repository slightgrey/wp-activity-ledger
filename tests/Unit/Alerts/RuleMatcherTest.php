<?php
/**
 * Tests for RuleMatcher.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Unit\Alerts;

use PHPUnit\Framework\TestCase;
use WPActivityLedger\Alerts\AlertState;
use WPActivityLedger\Alerts\RuleMatcher;
use WPActivityLedger\Domain\Event;

/**
 * In-memory alert state for tests.
 */
final class MemoryAlertState implements AlertState {

	/**
	 * Hit times by key.
	 *
	 * @var array<string, array<int, int>>
	 */
	private array $hits = array();

	/**
	 * Cooldown ends by key.
	 *
	 * @var array<string, int>
	 */
	private array $cool = array();

	/**
	 * Adds a hit.
	 *
	 * @param string $key    Key.
	 * @param int    $now    Now.
	 * @param int    $window Window.
	 *
	 * @return int Hits in window.
	 */
	public function hit( string $key, int $now, int $window ): int {
		$this->hits[ $key ] = array_values( array_filter( $this->hits[ $key ] ?? array(), static fn( int $t ): bool => $t > $now - $window ) );

		$this->hits[ $key ][] = $now;

		return count( $this->hits[ $key ] );
	}

	/**
	 * Tells whether cooling.
	 *
	 * @param string $key Key.
	 * @param int    $now Now.
	 *
	 * @return bool Cooling.
	 */
	public function cooling( string $key, int $now ): bool {
		return ( $this->cool[ $key ] ?? 0 ) > $now;
	}

	/**
	 * Starts a cooldown.
	 *
	 * @param string $key   Key.
	 * @param int    $until Until.
	 *
	 * @return void
	 */
	public function cool_until( string $key, int $until ): void {
		$this->cool[ $key ] = $until;
	}
}

/**
 * @covers \WPActivityLedger\Alerts\RuleMatcher
 */
final class RuleMatcherTest extends TestCase {

	/**
	 * Builds an enriched-looking event.
	 *
	 * @param string             $type     Type.
	 * @param int                $severity Severity.
	 * @param string             $ip       IP.
	 * @param array<int, string> $roles    Roles.
	 *
	 * @return Event Event.
	 */
	private static function event( string $type = 'plugin.activated', int $severity = 1, string $ip = '1.2.3.4', array $roles = array( 'administrator' ) ): Event {
		return new Event( $type, category: explode( '.', $type )[0], severity: $severity, ip: $ip, user_roles: $roles, user_id: 1 );
	}

	/**
	 * Criteria: types, categories, minimum severity and roles must all match.
	 *
	 * @return void
	 */
	public function test_simple_match_and_no_match(): void {
		$matcher = new RuleMatcher( new MemoryAlertState() );
		$rule    = array(
			'id'    => 'r1',
			'match' => array(
				'event_types'  => array( 'plugin.activated', 'plugin.deleted' ),
				'min_severity' => 1,
				'roles'        => array( 'administrator' ),
			),
		);

		$this->assertTrue( $matcher->fires( $rule, self::event(), 1000 ) );
		$this->assertFalse( $matcher->fires( $rule, self::event( 'plugin.updated' ), 1000 ) );
		$this->assertFalse( $matcher->fires( $rule, self::event( 'plugin.activated', 0 ), 1000 ) );
		$this->assertFalse( $matcher->fires( $rule, self::event( 'plugin.activated', 1, '1.2.3.4', array( 'editor' ) ), 1000 ) );
		$this->assertTrue( RuleMatcher::matches( array( 'categories' => array( 'plugin' ) ), self::event() ) );
		$this->assertFalse( RuleMatcher::matches( array( 'categories' => array( 'auth' ) ), self::event() ) );
	}

	/**
	 * A threshold of 5 in 10 minutes fires on the 5th event; the cooldown suppresses the 6th.
	 *
	 * @return void
	 */
	public function test_threshold_and_cooldown(): void {
		$matcher = new RuleMatcher( new MemoryAlertState() );
		$rule    = array(
			'id'        => 'brute',
			'match'     => array( 'event_types' => array( 'auth.login_failed' ) ),
			'threshold' => array(
				'count'    => 5,
				'minutes'  => 10,
				'group_by' => 'ip',
			),
		);
		$results = array();

		for ( $i = 0; $i < 6; $i++ ) {
			$results[] = $matcher->fires( $rule, self::event( 'auth.login_failed', 2 ), 1000 + $i * 30 );
		}

		$this->assertSame( array( false, false, false, false, true, false ), $results );

		// Another IP has its own counter.
		$this->assertFalse( $matcher->fires( $rule, self::event( 'auth.login_failed', 2, '9.9.9.9' ), 1200 ) );

		// After the window and cooldown, five new attempts fire again.
		$later = array();

		for ( $i = 0; $i < 5; $i++ ) {
			$later[] = $matcher->fires( $rule, self::event( 'auth.login_failed', 2 ), 3000 + $i );
		}

		$this->assertSame( array( false, false, false, false, true ), $later );
	}

	/**
	 * An explicit cooldown applies to rules without a threshold.
	 *
	 * @return void
	 */
	public function test_cooldown_without_threshold(): void {
		$matcher = new RuleMatcher( new MemoryAlertState() );
		$rule    = array(
			'id'               => 'r2',
			'match'            => array( 'event_types' => array( 'plugin.activated' ) ),
			'cooldown_minutes' => 5,
		);

		$this->assertTrue( $matcher->fires( $rule, self::event(), 1000 ) );
		$this->assertFalse( $matcher->fires( $rule, self::event(), 1100 ) );
		$this->assertTrue( $matcher->fires( $rule, self::event(), 1301 ) );
	}
}
