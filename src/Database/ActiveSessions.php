<?php
/**
 * Counts active login sessions.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Database;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- One read of a core table; results are cached by the caller.

/**
 * Counts the unexpired login sessions of all users (WordPress keeps them in the
 * "session_tokens" user meta, one serialized list per user).
 */
final class ActiveSessions {

	/**
	 * Returns the number of unexpired sessions.
	 *
	 * @param int|null $now Current time (default: now).
	 *
	 * @return int Sessions.
	 */
	public static function count( ?int $now = null ): int {
		global $wpdb;

		$now    = $now ?? time();
		$values = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", 'session_tokens' ) );
		$count  = 0;

		foreach ( (array) $values as $value ) {
			$sessions = maybe_unserialize( (string) $value );

			foreach ( is_array( $sessions ) ? $sessions : array() as $session ) {
				if ( is_array( $session ) && (int) ( $session['expiration'] ?? 0 ) >= $now ) {
					++$count;
				}
			}
		}

		return $count;
	}
}
