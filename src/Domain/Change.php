<?php
/**
 * One changed field of an event.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Domain;

/**
 * Immutable before/after pair for a single field.
 */
final class Change {

	/**
	 * Creates a change.
	 *
	 * @param string $field     Field name, e.g. "post_title" or "meta:_price".
	 * @param mixed  $old_value Value before the change (null when added).
	 * @param mixed  $new_value Value after the change (null when removed).
	 * @param bool   $truncated Whether a value was shortened to the size limit.
	 */
	public function __construct(
		public readonly string $field,
		public readonly mixed $old_value,
		public readonly mixed $new_value,
		public readonly bool $truncated = false
	) {
	}

	/**
	 * Returns the database row for the wpal_event_changes table.
	 *
	 * @param int $event_id ID of the event row this change belongs to.
	 *
	 * @return array{event_id: int, field: string, old_value: string|null, new_value: string|null, truncated: int} Row.
	 */
	public function to_row( int $event_id ): array {
		return array(
			'event_id'  => $event_id,
			'field'     => mb_substr( $this->field, 0, 191 ),
			'old_value' => self::stringify( $this->old_value ),
			'new_value' => self::stringify( $this->new_value ),
			'truncated' => $this->truncated ? 1 : 0,
		);
	}

	/**
	 * Returns the change as a plain array (for REST and export).
	 *
	 * @return array{field: string, old: mixed, new: mixed, truncated: bool} Change.
	 */
	public function to_array(): array {
		return array(
			'field'     => $this->field,
			'old'       => $this->old_value,
			'new'       => $this->new_value,
			'truncated' => $this->truncated,
		);
	}

	/**
	 * Turns a value into the string stored in the database.
	 *
	 * Strings are stored as-is, null stays NULL, everything else is JSON.
	 *
	 * @param mixed $value Value.
	 *
	 * @return string|null Stored form.
	 */
	public static function stringify( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		if ( is_string( $value ) ) {
			return $value;
		}

		$json = wp_json_encode( $value );

		return false === $json ? '' : $json;
	}
}
