<?php
/**
 * The content of one alert.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Alerts;

/**
 * Everything a channel formatter needs, already resolved (labels, links), so formatters
 * stay pure. Stored as an array in the delivery job.
 */
final class AlertMessage {

	/**
	 * Creates the message.
	 *
	 * @param string $title        Event type label, e.g. "Plugin activated".
	 * @param string $message      Event message.
	 * @param string $event_type   Event type key.
	 * @param string $severity     Severity slug.
	 * @param string $category     Category label.
	 * @param string $user         Acting user's login ('' for the system).
	 * @param string $ip           IP address.
	 * @param string $object_label Object label.
	 * @param string $created_at   Time, UTC "Y-m-d H:i:s".
	 * @param string $site_name    Site name.
	 * @param string $site_url     Site URL.
	 * @param string $log_url      Link to the event in the log.
	 * @param string $rule         Rule name.
	 * @param int    $event_id     Event ID (0 for samples).
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $message,
		public readonly string $event_type,
		public readonly string $severity,
		public readonly string $category,
		public readonly string $user,
		public readonly string $ip,
		public readonly string $object_label,
		public readonly string $created_at,
		public readonly string $site_name,
		public readonly string $site_url,
		public readonly string $log_url,
		public readonly string $rule,
		public readonly int $event_id = 0
	) {
	}

	/**
	 * Returns the message as an array (for job arguments).
	 *
	 * @return array<string, string|int> Fields.
	 */
	public function to_array(): array {
		return get_object_vars( $this );
	}

	/**
	 * Rebuilds a message from its array form (unknown keys ignored, missing ones empty).
	 *
	 * @param array<string, mixed> $data Fields.
	 *
	 * @return AlertMessage Message.
	 */
	public static function from_array( array $data ): AlertMessage {
		$text = static fn( string $key ): string => is_scalar( $data[ $key ] ?? null ) ? (string) $data[ $key ] : '';

		return new self(
			$text( 'title' ),
			$text( 'message' ),
			$text( 'event_type' ),
			$text( 'severity' ),
			$text( 'category' ),
			$text( 'user' ),
			$text( 'ip' ),
			$text( 'object_label' ),
			$text( 'created_at' ),
			$text( 'site_name' ),
			$text( 'site_url' ),
			$text( 'log_url' ),
			$text( 'rule' ),
			(int) ( $data['event_id'] ?? 0 )
		);
	}
}
