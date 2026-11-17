<?php
/**
 * Validation of filter results.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Support;

/**
 * Third-party filters may return anything; these helpers turn results back into the
 * expected shape, falling back to the unfiltered value.
 */
final class FilterValue {

	/**
	 * Returns a filter result as a list of strings.
	 *
	 * @param mixed              $value    Filter result.
	 * @param array<int, string> $fallback Used when the result is not an array.
	 *
	 * @return array<int, string> Strings (non-scalar items are dropped).
	 */
	public static function strings( mixed $value, array $fallback ): array {
		if ( ! is_array( $value ) ) {
			return $fallback;
		}

		return array_values( array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
	}

	/**
	 * Returns a filter result as a string => string map.
	 *
	 * @param mixed                 $value    Filter result.
	 * @param array<string, string> $fallback Used when the result is not an array.
	 *
	 * @return array<string, string> Map (non-scalar values are dropped).
	 */
	public static function string_map( mixed $value, array $fallback ): array {
		if ( ! is_array( $value ) ) {
			return $fallback;
		}

		$map = array();

		foreach ( $value as $key => $item ) {
			if ( is_scalar( $item ) ) {
				$map[ (string) $key ] = (string) $item;
			}
		}

		return $map;
	}
}
