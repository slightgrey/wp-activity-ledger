<?php
/**
 * Definition of one event type.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Domain;

/**
 * Describes an event type: its key, category, default severity, label and message template.
 */
final class EventType {

	/**
	 * Creates an event type.
	 *
	 * @param string   $key      Unique key, e.g. "post.updated" (lowercase, dot-separated, max 64 chars).
	 * @param Category $category Category.
	 * @param Severity $severity Default severity.
	 * @param string   $label    Translated label, e.g. "Post updated".
	 * @param string   $template Translated message template with {placeholders}, e.g. 'Updated {object_type} "{object}"'.
	 *
	 * @throws \InvalidArgumentException When the key is malformed.
	 */
	public function __construct(
		public readonly string $key,
		public readonly Category $category,
		public readonly Severity $severity,
		public readonly string $label,
		public readonly string $template
	) {
		if ( ! self::is_valid_key( $key ) ) {
			throw new \InvalidArgumentException( 'Invalid event type key: ' . esc_html( $key ) );
		}
	}

	/**
	 * Tells whether a key has the expected shape ("area.action", lowercase, max 64 chars).
	 *
	 * @param string $key Key.
	 *
	 * @return bool True when valid.
	 */
	public static function is_valid_key( string $key ): bool {
		return strlen( $key ) <= 64 && 1 === preg_match( '/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $key );
	}

	/**
	 * Returns the type as a plain array (for the admin app).
	 *
	 * @return array{key: string, category: string, severity: int, label: string} Type.
	 */
	public function to_array(): array {
		return array(
			'key'      => $this->key,
			'category' => $this->category->value,
			'severity' => $this->severity->value,
			'label'    => $this->label,
		);
	}
}
