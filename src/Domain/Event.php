<?php
/**
 * One recorded activity.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Domain;

/**
 * Immutable activity record. Sensors create it with what they know (type, object,
 * changes); the Recorder fills in the actor, request and message with with().
 */
final class Event {

	/**
	 * Maximum lengths of the string columns in wpal_events.
	 */
	private const COLUMN_LENGTHS = array(
		'user_login'   => 60,
		'user_roles'   => 255,
		'ip'           => 45,
		'user_agent'   => 255,
		'category'     => 32,
		'event_type'   => 64,
		'object_type'  => 32,
		'object_id'    => 64,
		'object_label' => 255,
		'session_hash' => 16,
	);

	/**
	 * Creates an event.
	 *
	 * @param string               $event_type   Registered type key, e.g. "post.updated".
	 * @param string               $category     Category slug (filled from the registry when empty).
	 * @param int                  $severity     0 info, 1 notice, 2 warning, 3 critical.
	 * @param string               $object_type  Object kind: post, user, plugin, option, etc.
	 * @param string               $object_id    Object identifier (string so plugin files and option names fit).
	 * @param string               $object_label Human label snapshot (title, login, plugin name).
	 * @param string               $message      Rendered summary.
	 * @param array<string, mixed> $context      Extra metadata, stored as JSON.
	 * @param array<int, Change>   $changes      Field-level changes.
	 * @param int                  $user_id      Acting user (0 = system or anonymous).
	 * @param string               $user_login   Login snapshot.
	 * @param array<int, string>   $user_roles   Role snapshot.
	 * @param string               $ip           Client IP (possibly anonymised).
	 * @param string               $user_agent   User agent.
	 * @param string               $session_hash Short hash of the login session token.
	 * @param string               $created_at   UTC time as "Y-m-d H:i:s" (empty = now, set by the Recorder).
	 */
	public function __construct(
		public readonly string $event_type,
		public readonly string $category = '',
		public readonly int $severity = 0,
		public readonly string $object_type = '',
		public readonly string $object_id = '',
		public readonly string $object_label = '',
		public readonly string $message = '',
		public readonly array $context = array(),
		public readonly array $changes = array(),
		public readonly int $user_id = 0,
		public readonly string $user_login = '',
		public readonly array $user_roles = array(),
		public readonly string $ip = '',
		public readonly string $user_agent = '',
		public readonly string $session_hash = '',
		public readonly string $created_at = ''
	) {
	}

	/**
	 * Returns a copy with some properties replaced.
	 *
	 * @param array<string, mixed> $fields Property name => new value.
	 *
	 * @return Event The copy.
	 *
	 * @throws \InvalidArgumentException When a field name is not an Event property.
	 */
	public function with( array $fields ): Event {
		$args    = $this->to_args();
		$unknown = array_diff_key( $fields, $args );

		if ( array() !== $unknown ) {
			throw new \InvalidArgumentException( 'Unknown Event field(s): ' . esc_html( implode( ', ', array_keys( $unknown ) ) ) );
		}

		return new Event( ...array_merge( $args, $fields ) );
	}

	/**
	 * Returns a copy with more changes appended.
	 *
	 * @param Change ...$changes Changes to add.
	 *
	 * @return Event The copy.
	 */
	public function with_changes( Change ...$changes ): Event {
		return $this->with( array( 'changes' => array_merge( $this->changes, $changes ) ) );
	}

	/**
	 * Returns the database row for the wpal_events table (without the ID).
	 *
	 * @return array<string, int|string> Column => value.
	 */
	public function to_row(): array {
		$row = array(
			'created_at'   => $this->created_at,
			'user_id'      => $this->user_id,
			'user_login'   => $this->user_login,
			'user_roles'   => implode( ',', $this->user_roles ),
			'ip'           => $this->ip,
			'user_agent'   => $this->user_agent,
			'category'     => $this->category,
			'event_type'   => $this->event_type,
			'severity'     => $this->severity,
			'object_type'  => $this->object_type,
			'object_id'    => $this->object_id,
			'object_label' => $this->object_label,
			'message'      => $this->message,
			'context'      => self::encode_context( $this->context ),
			'session_hash' => $this->session_hash,
		);

		foreach ( self::COLUMN_LENGTHS as $column => $length ) {
			$row[ $column ] = mb_substr( (string) $row[ $column ], 0, $length );
		}

		return $row;
	}

	/**
	 * Returns the constructor arguments of this event.
	 *
	 * @return array<string, mixed> Property name => value.
	 */
	private function to_args(): array {
		return get_object_vars( $this );
	}

	/**
	 * Encodes the context as JSON ("{}" when empty or not encodable).
	 *
	 * @param array<string, mixed> $context Context.
	 *
	 * @return string JSON object.
	 */
	private static function encode_context( array $context ): string {
		if ( array() === $context ) {
			return '{}';
		}

		$json = wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return false === $json ? '{}' : $json;
	}
}
