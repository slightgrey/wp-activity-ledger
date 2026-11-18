<?php
/**
 * Reads and ends a user's login sessions.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Security;

/**
 * Lists and destroys a user's sessions by verifier (the SHA-256 of the session token).
 *
 * Goes through the site's session manager (WP_Session_Tokens::get_instance, which other
 * plugins may replace) instead of the user meta, using its verifier-keyed methods, which
 * the public API does not expose.
 */
final class SessionStore {

	/**
	 * The user's session manager.
	 *
	 * @var \WP_Session_Tokens
	 */
	private \WP_Session_Tokens $manager;

	/**
	 * Creates the store.
	 *
	 * @param int $user_id User ID.
	 */
	public function __construct( private readonly int $user_id ) {
		$this->manager = \WP_Session_Tokens::get_instance( $user_id );
	}

	/**
	 * Returns the unexpired sessions keyed by verifier.
	 *
	 * @return array<string, array<string, mixed>> Sessions.
	 */
	public function all(): array {
		$sessions = $this->call( 'get_sessions' );
		$now      = time();

		if ( ! is_array( $sessions ) ) {
			return array();
		}

		return array_filter(
			$sessions,
			static fn( $session ): bool => is_array( $session ) && (int) ( $session['expiration'] ?? 0 ) >= $now
		);
	}

	/**
	 * Ends one session.
	 *
	 * @param string $verifier Verifier.
	 *
	 * @return bool True when the session existed.
	 */
	public function destroy( string $verifier ): bool {
		if ( ! array_key_exists( $verifier, $this->all() ) ) {
			return false;
		}

		$this->call( 'update_session', $verifier, null );

		return true;
	}

	/**
	 * Ends every session, or every other session when a verifier to keep is given.
	 *
	 * @param string $keep Verifier of the session to keep ('' for none).
	 *
	 * @return int Sessions ended.
	 */
	public function destroy_all( string $keep = '' ): int {
		$count = 0;

		foreach ( array_keys( $this->all() ) as $verifier ) {
			if ( $verifier !== $keep ) {
				$this->call( 'update_session', $verifier, null );
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Returns the verifier of the current request's session ('' when it isn't this user's).
	 *
	 * @return string Verifier.
	 */
	public function current_verifier(): string {
		$token = get_current_user_id() === $this->user_id ? wp_get_session_token() : '';

		return '' === $token ? '' : hash( 'sha256', $token );
	}

	/**
	 * Calls a protected method of the session manager.
	 *
	 * @param string $method  Method name.
	 * @param mixed  ...$args Arguments.
	 *
	 * @return mixed Result.
	 */
	private function call( string $method, mixed ...$args ): mixed {
		return ( new \ReflectionMethod( $this->manager, $method ) )->invoke( $this->manager, ...$args );
	}
}
