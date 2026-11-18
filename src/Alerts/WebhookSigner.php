<?php
/**
 * Signs generic webhook requests.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Alerts;

/**
 * Signs a webhook body so receivers can verify it and reject replays:
 *
 *   X-WPAL-Timestamp: <unix time>
 *   X-WPAL-Signature: sha256=<hex HMAC-SHA256 of "<timestamp>.<body>" with the secret>
 *
 * Receivers recompute the HMAC over the timestamp header, a dot and the raw body, compare
 * in constant time, and reject old timestamps (e.g. more than 5 minutes).
 */
final class WebhookSigner {

	/**
	 * Returns the signature header value.
	 *
	 * @param string $body      Raw request body.
	 * @param string $secret    Channel secret.
	 * @param int    $timestamp Unix time sent in X-WPAL-Timestamp.
	 *
	 * @return string "sha256=<hex>".
	 */
	public static function sign( string $body, string $secret, int $timestamp ): string {
		return 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}

	/**
	 * Returns the signing headers.
	 *
	 * @param string $body      Raw request body.
	 * @param string $secret    Channel secret.
	 * @param int    $timestamp Unix time.
	 *
	 * @return array<string, string> Headers.
	 */
	public static function headers( string $body, string $secret, int $timestamp ): array {
		return array(
			'X-WPAL-Timestamp' => (string) $timestamp,
			'X-WPAL-Signature' => self::sign( $body, $secret, $timestamp ),
		);
	}

	/**
	 * Verifies a signature (for receivers and tests).
	 *
	 * @param string $body      Raw body.
	 * @param string $secret    Secret.
	 * @param int    $timestamp Timestamp header.
	 * @param string $signature Signature header.
	 *
	 * @return bool True when valid.
	 */
	public static function verify( string $body, string $secret, int $timestamp, string $signature ): bool {
		return hash_equals( self::sign( $body, $secret, $timestamp ), $signature );
	}
}
