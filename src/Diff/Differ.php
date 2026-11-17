<?php
/**
 * Field-level comparison of before/after data.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Diff;

use WPActivityLedger\Domain\Change;
use WPActivityLedger\Support\PatternMatcher;

/**
 * Compares two snapshots of an object and returns one Change per changed field.
 *
 * Values are normalised before comparing: serialized and JSON strings are decoded,
 * and scalars are compared as strings so "1", 1 and true, or null and "", count as
 * equal. Stored values are redacted and cut to the size limit.
 */
final class Differ {

	/**
	 * Creates a differ.
	 *
	 * @param Redactor $redactor       Hides secret values.
	 * @param int      $max_value_size Maximum stored size of one value, in bytes.
	 */
	public function __construct(
		private readonly Redactor $redactor,
		private readonly int $max_value_size = 10240
	) {
	}

	/**
	 * Compares two snapshots field by field.
	 *
	 * @param array<string|int, mixed> $before Old values by field.
	 * @param array<string|int, mixed> $after  New values by field.
	 * @param PatternMatcher|null      $ignore Fields to skip.
	 * @param string                   $prefix Prefix added to field names, e.g. "meta:".
	 *
	 * @return array<int, Change> Changes, in field order (old fields first, then new ones).
	 */
	public function diff( array $before, array $after, ?PatternMatcher $ignore = null, string $prefix = '' ): array {
		$changes = array();

		foreach ( array_keys( $before + $after ) as $field ) {
			$field = (string) $field;

			if ( null !== $ignore && $ignore->matches( $field ) ) {
				continue;
			}

			$change = $this->compare( $prefix . $field, $before[ $field ] ?? null, $after[ $field ] ?? null, $field );

			if ( null !== $change ) {
				$changes[] = $change;
			}
		}

		return $changes;
	}

	/**
	 * Compares one value.
	 *
	 * @param string      $field        Field name stored on the change.
	 * @param mixed       $old_value    Old value.
	 * @param mixed       $new_value    New value.
	 * @param string|null $redact_key   Key checked against the redaction patterns (default: $field).
	 *
	 * @return Change|null The change, or null when the values are equivalent.
	 */
	public function compare( string $field, mixed $old_value, mixed $new_value, ?string $redact_key = null ): ?Change {
		$old_value = self::decode( $old_value );
		$new_value = self::decode( $new_value );

		if ( self::canonical( $old_value ) === self::canonical( $new_value ) ) {
			return null;
		}

		$key       = $redact_key ?? $field;
		$truncated = false;
		$old_value = $this->limit( $this->redactor->redact_value( $key, $old_value ), $truncated );
		$new_value = $this->limit( $this->redactor->redact_value( $key, $new_value ), $truncated );

		return new Change( $field, $old_value, $new_value, $truncated );
	}

	/**
	 * Decodes serialized and JSON strings into arrays; other values pass through.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return mixed Decoded value.
	 */
	public static function decode( mixed $value ): mixed {
		if ( ! is_string( $value ) ) {
			return $value;
		}

		if ( is_serialized( $value ) ) {
			// Objects are disabled, and malformed data is expected (hence the @).
			$decoded = @unserialize( trim( $value ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged

			return false === $decoded && 'b:0;' !== trim( $value ) ? $value : $decoded;
		}

		return self::decode_json( $value );
	}

	/**
	 * Decodes a JSON object or array string; anything else is returned unchanged.
	 *
	 * @param string $value String.
	 *
	 * @return mixed Decoded array, or the original string.
	 */
	private static function decode_json( string $value ): mixed {
		$trimmed = trim( $value );

		if ( '' === $trimmed || ( '{' !== $trimmed[0] && '[' !== $trimmed[0] ) ) {
			return $value;
		}

		$decoded = json_decode( $trimmed, true );

		return is_array( $decoded ) ? $decoded : $value;
	}

	/**
	 * Builds a comparison form: scalars become strings, arrays are converted recursively.
	 *
	 * @param mixed $value Decoded value.
	 *
	 * @return mixed Canonical form.
	 */
	private static function canonical( mixed $value ): mixed {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'canonical' ), $value );
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}

		if ( is_float( $value ) && floor( $value ) === $value ) {
			return (string) (int) $value;
		}

		return null === $value ? '' : (string) $value;
	}

	/**
	 * Cuts a value to the size limit, turning large arrays into a shortened JSON string.
	 *
	 * @param mixed $value     Value.
	 * @param bool  $truncated Set to true when the value was cut.
	 *
	 * @return mixed The value, or a shortened string.
	 */
	private function limit( mixed $value, bool &$truncated ): mixed {
		$stored = Change::stringify( $value );

		if ( null === $stored || strlen( $stored ) <= $this->max_value_size ) {
			return $value;
		}

		$truncated = true;

		return mb_strcut( $stored, 0, $this->max_value_size );
	}
}
