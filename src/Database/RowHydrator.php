<?php
/**
 * Turns database rows into typed arrays.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Database;

/**
 * Converts raw wpal_events / wpal_event_changes rows (all strings) into typed arrays
 * for the REST API, export and CLI.
 *
 * @phpstan-type EventRow array{id: int, created_at: string, user_id: int, user_login: string, user_roles: array<int, string>, ip: string, user_agent: string, category: string, event_type: string, severity: int, object_type: string, object_id: string, object_label: string, message: string, context: array<string, mixed>, session_hash: string}
 * @phpstan-type ChangeRow array{field: string, old: mixed, new: mixed, truncated: bool}
 */
final class RowHydrator {

	/**
	 * Types an event row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 *
	 * @return array<string, mixed> Typed event.
	 */
	public static function event( array $row ): array {
		$context = json_decode( (string) ( $row['context'] ?? '{}' ), true );
		$roles   = (string) ( $row['user_roles'] ?? '' );

		return array(
			'id'           => (int) ( $row['id'] ?? 0 ),
			'created_at'   => (string) ( $row['created_at'] ?? '' ),
			'user_id'      => (int) ( $row['user_id'] ?? 0 ),
			'user_login'   => (string) ( $row['user_login'] ?? '' ),
			'user_roles'   => '' === $roles ? array() : explode( ',', $roles ),
			'ip'           => (string) ( $row['ip'] ?? '' ),
			'user_agent'   => (string) ( $row['user_agent'] ?? '' ),
			'category'     => (string) ( $row['category'] ?? '' ),
			'event_type'   => (string) ( $row['event_type'] ?? '' ),
			'severity'     => (int) ( $row['severity'] ?? 0 ),
			'object_type'  => (string) ( $row['object_type'] ?? '' ),
			'object_id'    => (string) ( $row['object_id'] ?? '' ),
			'object_label' => (string) ( $row['object_label'] ?? '' ),
			'message'      => (string) ( $row['message'] ?? '' ),
			'context'      => is_array( $context ) ? $context : array(),
			'session_hash' => (string) ( $row['session_hash'] ?? '' ),
		);
	}

	/**
	 * Types a change row. JSON-encoded arrays are decoded back into arrays.
	 *
	 * @param array<string, mixed> $row Raw row.
	 *
	 * @return array{field: string, old: mixed, new: mixed, truncated: bool} Typed change.
	 */
	public static function change( array $row ): array {
		return array(
			'field'     => (string) ( $row['field'] ?? '' ),
			'old'       => self::value( $row['old_value'] ?? null ),
			'new'       => self::value( $row['new_value'] ?? null ),
			'truncated' => ! empty( $row['truncated'] ),
		);
	}

	/**
	 * Decodes a stored value: JSON arrays/objects become arrays, everything else stays a string.
	 *
	 * @param mixed $stored Stored value (string or null).
	 *
	 * @return mixed Value.
	 */
	private static function value( mixed $stored ): mixed {
		if ( ! is_string( $stored ) || '' === $stored || ( '[' !== $stored[0] && '{' !== $stored[0] ) ) {
			return $stored;
		}

		$decoded = json_decode( $stored, true );

		return is_array( $decoded ) ? $decoded : $stored;
	}
}
