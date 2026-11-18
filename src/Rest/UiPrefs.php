<?php
/**
 * Per-user admin app preferences.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Rest;

/**
 * Validates and stores the admin app preferences of a user (user meta "wpal_ui_prefs"):
 * visible log columns, rows per page and saved filters. Unknown keys are dropped.
 */
final class UiPrefs {

	/**
	 * User meta key.
	 */
	public const META = 'wpal_ui_prefs';

	/**
	 * Columns the log table can show.
	 */
	public const COLUMNS = array( 'time', 'user', 'event', 'severity', 'category', 'object', 'ip', 'source' );

	/**
	 * Allowed rows per page.
	 */
	public const PER_PAGE = array( 25, 50, 100 );

	/**
	 * Most saved filters kept.
	 */
	private const MAX_FILTERS = 20;

	/**
	 * Filter keys a saved filter may hold.
	 */
	private const FILTER_KEYS = array( 'from', 'to', 'range', 'user', 'role', 'category', 'type', 'severity', 'object_type', 'object_id', 'ip', 'session', 'search' );

	/**
	 * Returns the default preferences.
	 *
	 * @return array{columns: array<int, string>, per_page: int, filters: array<int, array{name: string, filters: array<string, mixed>}>} Defaults.
	 */
	public static function defaults(): array {
		return array(
			'columns'  => array( 'time', 'user', 'event', 'object', 'ip' ),
			'per_page' => 25,
			'filters'  => array(),
		);
	}

	/**
	 * Returns a user's preferences merged over the defaults.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return array<string, mixed> Preferences.
	 */
	public static function get( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META, true );

		return array_merge( self::defaults(), is_array( $stored ) ? self::sanitize( $stored ) : array() );
	}

	/**
	 * Validates and merges submitted preferences into a user's preferences, then saves them.
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $input   Submitted preferences.
	 *
	 * @return array<string, mixed> Saved preferences.
	 */
	public static function save( int $user_id, array $input ): array {
		$prefs = array_merge( self::get( $user_id ), self::sanitize( $input ) );
		update_user_meta( $user_id, self::META, $prefs );

		return $prefs;
	}

	/**
	 * Keeps the known, valid keys of submitted preferences.
	 *
	 * @param array<mixed> $input Submitted preferences.
	 *
	 * @return array<string, mixed> Valid preferences.
	 */
	public static function sanitize( array $input ): array {
		$prefs = array();

		if ( isset( $input['columns'] ) && is_array( $input['columns'] ) ) {
			$prefs['columns'] = array_values( array_intersect( self::COLUMNS, array_map( 'strval', $input['columns'] ) ) );
		}

		if ( isset( $input['per_page'] ) && in_array( (int) $input['per_page'], self::PER_PAGE, true ) ) {
			$prefs['per_page'] = (int) $input['per_page'];
		}

		if ( isset( $input['filters'] ) && is_array( $input['filters'] ) ) {
			$prefs['filters'] = self::saved_filters( $input['filters'] );
		}

		return $prefs;
	}

	/**
	 * Validates saved filters: a name and known filter keys with scalar or list values.
	 *
	 * @param array<mixed> $items Saved filters.
	 *
	 * @return array<int, array{name: string, filters: array<string, mixed>}> Valid saved filters.
	 */
	private static function saved_filters( array $items ): array {
		$valid = array();

		foreach ( $items as $item ) {
			$name = is_array( $item ) ? sanitize_text_field( (string) ( $item['name'] ?? '' ) ) : '';

			if ( '' === $name || ! is_array( $item['filters'] ?? null ) ) {
				continue;
			}

			$valid[] = array(
				'name'    => mb_substr( $name, 0, 60 ),
				'filters' => self::filter_values( $item['filters'] ),
			);
		}

		return array_slice( $valid, 0, self::MAX_FILTERS );
	}

	/**
	 * Keeps the known filter keys with plain text or list values.
	 *
	 * @param array<mixed> $filters Filters.
	 *
	 * @return array<string, mixed> Filters.
	 */
	private static function filter_values( array $filters ): array {
		$valid = array();

		foreach ( array_intersect_key( $filters, array_flip( self::FILTER_KEYS ) ) as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$valid[ $key ] = mb_substr( sanitize_text_field( (string) $value ), 0, 100 );
			} elseif ( is_array( $value ) ) {
				$valid[ $key ] = array_slice( array_map( static fn( $v ): string => sanitize_text_field( is_scalar( $v ) ? (string) $v : '' ), array_values( $value ) ), 0, 50 );
			}
		}

		return $valid;
	}
}
