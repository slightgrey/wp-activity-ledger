<?php
/**
 * Hides secret values before they are stored.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Diff;

use WPActivityLedger\Support\PatternMatcher;

/**
 * Replaces values whose key matches a redaction pattern (e.g. "*pass*", "*api_key*")
 * with a marker, recursively inside arrays and objects.
 */
final class Redactor {

	/**
	 * Marker stored instead of a secret value.
	 */
	public const MARKER = '[redacted]';

	/**
	 * Creates a redactor.
	 *
	 * @param PatternMatcher $sensitive_keys Matcher for keys whose values are secret.
	 */
	public function __construct( private readonly PatternMatcher $sensitive_keys ) {
	}

	/**
	 * Tells whether a key holds a secret.
	 *
	 * @param string $key Key (option name, meta key, array key).
	 *
	 * @return bool True when the key is sensitive.
	 */
	public function is_sensitive( string $key ): bool {
		return $this->sensitive_keys->matches( $key );
	}

	/**
	 * Redacts a value stored under a key.
	 *
	 * The whole value is replaced when the key is sensitive (null stays null so
	 * "added"/"removed" is still visible); otherwise nested keys are checked.
	 *
	 * @param string $key   Key the value is stored under.
	 * @param mixed  $value Value.
	 *
	 * @return mixed Redacted value.
	 */
	public function redact_value( string $key, mixed $value ): mixed {
		if ( null !== $value && $this->is_sensitive( $key ) ) {
			return self::MARKER;
		}

		return $this->redact_nested( $value );
	}

	/**
	 * Redacts the sensitive keys inside an array or object, at any depth.
	 *
	 * Objects are converted to arrays of their public properties.
	 *
	 * @param mixed $value Value.
	 *
	 * @return mixed Redacted value (scalars are returned unchanged).
	 */
	public function redact_nested( mixed $value ): mixed {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->redact_value( (string) $key, $item );
		}

		return $value;
	}
}
