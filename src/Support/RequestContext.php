<?php
/**
 * Facts about the current request.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Support;

/**
 * Describes where an action came from: request source, method, URI, user agent and
 * login session. WordPress lookups are passed in, so it can be unit-tested.
 */
final class RequestContext {

	/**
	 * Query parameters whose values are never stored (reset keys, nonces, passwords).
	 */
	private const SECRET_QUERY_KEYS = array( 'key', 'pass', 'pwd', 'password', 'token', 'nonce', '_wpnonce', 'rp_key', 'access_token' );

	/**
	 * Creates the context.
	 *
	 * @param array<string, mixed> $server        Server variables (usually $_SERVER).
	 * @param array<string, bool>  $flags         Request flags: cli, cron, xmlrpc, rest, ajax, admin.
	 * @param string               $session_token Login session token of the current user ('' when none).
	 */
	public function __construct(
		private readonly array $server,
		private readonly array $flags,
		private readonly string $session_token = ''
	) {
	}

	/**
	 * Builds the context from the running WordPress request.
	 *
	 * @return RequestContext Context.
	 */
	public static function from_globals(): RequestContext {
		return new RequestContext(
			$_SERVER, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Each value is validated where it is read.
			array(
				'cli'    => defined( 'WP_CLI' ) && WP_CLI,
				'cron'   => wp_doing_cron(),
				'xmlrpc' => defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST,
				'rest'   => defined( 'REST_REQUEST' ) && REST_REQUEST,
				'ajax'   => wp_doing_ajax(),
				'admin'  => is_admin(),
			),
			function_exists( 'wp_get_session_token' ) ? (string) wp_get_session_token() : ''
		);
	}

	/**
	 * Returns where the request came from.
	 *
	 * @return string One of cli, cron, xmlrpc, rest, ajax, admin, web.
	 */
	public function source(): string {
		foreach ( array( 'cli', 'cron', 'xmlrpc', 'rest', 'ajax', 'admin' ) as $source ) {
			if ( ! empty( $this->flags[ $source ] ) ) {
				return $source;
			}
		}

		return 'web';
	}

	/**
	 * Returns the HTTP method ('' for CLI).
	 *
	 * @return string Upper-case method.
	 */
	public function method(): string {
		$method = $this->server['REQUEST_METHOD'] ?? '';

		return is_string( $method ) ? strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $method ) ?? '', 0, 10 ) ) : '';
	}

	/**
	 * Returns the request URI with control characters removed and secret query values masked.
	 *
	 * @return string URI (max 255 characters).
	 */
	public function uri(): string {
		$uri = $this->server['REQUEST_URI'] ?? '';

		if ( ! is_string( $uri ) || '' === $uri ) {
			return '';
		}

		$uri = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $uri );

		return mb_substr( self::mask_secret_query( $uri ), 0, 255 );
	}

	/**
	 * Returns the user agent, cut to 255 characters.
	 *
	 * @return string User agent.
	 */
	public function user_agent(): string {
		$agent = $this->server['HTTP_USER_AGENT'] ?? '';

		return is_string( $agent ) ? mb_substr( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $agent ), 0, 255 ) : '';
	}

	/**
	 * Returns a short, non-reversible hash of the login session token.
	 *
	 * @return string 16 hex characters, or '' without a session.
	 */
	public function session_hash(): string {
		return self::hash_token( $this->session_token );
	}

	/**
	 * Hashes a session token the way session_hash() does.
	 *
	 * @param string $token Session token.
	 *
	 * @return string 16 hex characters, or '' for an empty token.
	 */
	public static function hash_token( string $token ): string {
		return '' === $token ? '' : substr( hash( 'sha256', $token ), 0, 16 );
	}

	/**
	 * Returns the request facts stored in an event's context.
	 *
	 * @return array{source: string, method: string, uri: string} Context.
	 */
	public function to_context(): array {
		return array(
			'source' => $this->source(),
			'method' => $this->method(),
			'uri'    => $this->uri(),
		);
	}

	/**
	 * Replaces the values of secret query parameters with "***".
	 *
	 * @param string $uri URI.
	 *
	 * @return string URI with masked values.
	 */
	private static function mask_secret_query( string $uri ): string {
		$pattern = '/([?&](?:' . implode( '|', array_map( 'preg_quote', self::SECRET_QUERY_KEYS ) ) . ')=)[^&#]*/i';

		return (string) preg_replace( $pattern, '$1***', $uri );
	}
}
