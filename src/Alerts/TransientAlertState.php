<?php
/**
 * Alert state in transients.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Alerts;

/**
 * Keeps threshold timestamps and cooldowns in transients ("wpal_alert_*"), which expire
 * on their own and use the object cache when one is installed.
 */
final class TransientAlertState implements AlertState {

	/**
	 * Adds a match and returns the matches within the window.
	 *
	 * @param string $key    Counter key.
	 * @param int    $now    Current Unix time.
	 * @param int    $window Window in seconds.
	 *
	 * @return int Matches in the window.
	 */
	public function hit( string $key, int $now, int $window ): int {
		$name  = self::name( 'hits', $key );
		$times = get_transient( $name );
		$times = array_values( array_filter( is_array( $times ) ? $times : array(), static fn( $time ): bool => (int) $time > $now - $window ) );

		$times[] = $now;
		set_transient( $name, array_slice( $times, -1000 ), $window );

		return count( $times );
	}

	/**
	 * Tells whether a cooldown is running.
	 *
	 * @param string $key Cooldown key.
	 * @param int    $now Current Unix time.
	 *
	 * @return bool True while cooling down.
	 */
	public function cooling( string $key, int $now ): bool {
		return (int) get_transient( self::name( 'cool', $key ) ) > $now;
	}

	/**
	 * Starts a cooldown.
	 *
	 * @param string $key   Cooldown key.
	 * @param int    $until Unix time when it ends.
	 *
	 * @return void
	 */
	public function cool_until( string $key, int $until ): void {
		set_transient( self::name( 'cool', $key ), $until, max( 1, $until - time() ) );
	}

	/**
	 * Returns a transient name (hashed: keys contain IPs).
	 *
	 * @param string $kind "hits" or "cool".
	 * @param string $key  Key.
	 *
	 * @return string Name.
	 */
	private static function name( string $kind, string $key ): string {
		return 'wpal_alert_' . $kind . '_' . substr( md5( $key ), 0, 20 );
	}
}
