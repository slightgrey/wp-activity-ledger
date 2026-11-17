<?php
/**
 * Client IP detection.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Support;

/**
 * Finds the client IP of the current request.
 *
 * REMOTE_ADDR is used unless the site owner names a trusted proxy header (e.g. behind
 * Cloudflare or a load balancer). Proxy headers are never read otherwise, because any
 * client can send them and spoof its address.
 */
final class IpResolver {

	/**
	 * Proxy headers the site owner may choose to trust ($_SERVER keys).
	 */
	public const ALLOWED_HEADERS = array(
		'HTTP_X_FORWARDED_FOR',
		'HTTP_X_REAL_IP',
		'HTTP_CF_CONNECTING_IP',
		'HTTP_TRUE_CLIENT_IP',
		'HTTP_X_CLIENT_IP',
		'HTTP_FORWARDED_FOR',
	);

	/**
	 * Creates a resolver.
	 *
	 * @param array<string, mixed> $server         Server variables (usually $_SERVER).
	 * @param string               $trusted_header Trusted proxy header key, or '' for none.
	 * @param bool                 $anonymize      Whether to mask the host part of the IP.
	 */
	public function __construct(
		private readonly array $server,
		private readonly string $trusted_header = '',
		private readonly bool $anonymize = false
	) {
	}

	/**
	 * Returns the client IP, or '' when none is valid.
	 *
	 * @return string IP address.
	 */
	public function resolve(): string {
		$ip = $this->from_trusted_header();

		if ( '' === $ip ) {
			$ip = self::valid_ip( $this->server['REMOTE_ADDR'] ?? '' );
		}

		return $this->anonymize && '' !== $ip ? self::anonymize( $ip ) : $ip;
	}

	/**
	 * Masks the host part of an IP: IPv4 keeps /24, IPv6 keeps /48.
	 *
	 * @param string $ip Valid IP address.
	 *
	 * @return string Masked IP, or '' when invalid.
	 */
	public static function anonymize( string $ip ): string {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid input returns false with a warning.

		if ( false === $packed ) {
			return '';
		}

		$keep   = 4 === strlen( $packed ) ? 3 : 6;
		$masked = substr( $packed, 0, $keep ) . str_repeat( "\0", strlen( $packed ) - $keep );

		return (string) inet_ntop( $masked );
	}

	/**
	 * Reads the first valid public IP from the trusted header, if one is configured.
	 *
	 * @return string IP, or '' when the header is unset, not allowed or holds no public IP.
	 */
	private function from_trusted_header(): string {
		if ( ! in_array( $this->trusted_header, self::ALLOWED_HEADERS, true ) ) {
			return '';
		}

		$header = $this->server[ $this->trusted_header ] ?? '';

		foreach ( explode( ',', is_string( $header ) ? $header : '' ) as $candidate ) {
			$ip = self::valid_ip( $candidate, true );

			if ( '' !== $ip ) {
				return $ip;
			}
		}

		return '';
	}

	/**
	 * Validates an IP string.
	 *
	 * @param mixed $candidate   Raw value.
	 * @param bool  $public_only Reject private and reserved ranges.
	 *
	 * @return string The trimmed IP, or '' when invalid.
	 */
	private static function valid_ip( mixed $candidate, bool $public_only = false ): string {
		if ( ! is_string( $candidate ) ) {
			return '';
		}

		$flags = $public_only ? FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE : 0;
		$ip    = filter_var( trim( $candidate ), FILTER_VALIDATE_IP, $flags );

		return is_string( $ip ) ? $ip : '';
	}
}
