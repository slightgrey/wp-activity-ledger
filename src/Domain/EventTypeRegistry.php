<?php
/**
 * Registry of known event types.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Domain;

/**
 * Holds every event type and renders event messages from their templates.
 *
 * Types come from providers (callables that receive the registry) which run lazily
 * on first lookup, after `init`, so translated labels don't load too early.
 */
final class EventTypeRegistry {

	/**
	 * Registered types by key.
	 *
	 * @var array<string, EventType>
	 */
	private array $types = array();

	/**
	 * Providers that have not run yet.
	 *
	 * @var array<int, callable(EventTypeRegistry): void>
	 */
	private array $providers = array();

	/**
	 * Whether providers ran and the wpal_event_types filter was applied.
	 *
	 * @var bool
	 */
	private bool $resolved = false;

	/**
	 * Adds a provider that registers types when the registry is first read.
	 *
	 * @param callable(EventTypeRegistry): void $provider Provider.
	 *
	 * @return void
	 */
	public function add_provider( callable $provider ): void {
		$this->providers[] = $provider;
		$this->resolved    = false;
	}

	/**
	 * Registers (or replaces) a type.
	 *
	 * @param EventType $type Type.
	 *
	 * @return void
	 */
	public function register( EventType $type ): void {
		$this->types[ $type->key ] = $type;
	}

	/**
	 * Returns a type by key.
	 *
	 * @param string $key Key.
	 *
	 * @return EventType|null The type, or null when unknown.
	 */
	public function get( string $key ): ?EventType {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * Returns every type, keyed by type key.
	 *
	 * @return array<string, EventType> Types.
	 */
	public function all(): array {
		$this->resolve();

		return $this->types;
	}

	/**
	 * Renders an event's message from its type's template.
	 *
	 * Placeholders: {object} (label, or ID when no label), {object_id}, {object_type},
	 * {user}, {type}, and any scalar context key, e.g. {count}. Unknown placeholders
	 * are removed.
	 *
	 * @param Event $event Event.
	 *
	 * @return string Message.
	 */
	public function render_message( Event $event ): string {
		$type     = $this->get( $event->event_type );
		$template = null === $type ? self::fallback_template( $event ) : $type->template;
		$values   = self::placeholder_values( $event, $type );

		$message = (string) preg_replace_callback(
			'/\{([a-z0-9_]+)\}/',
			static fn( array $found ): string => $values[ $found[1] ] ?? '',
			$template
		);

		return trim( (string) preg_replace( '/\s{2,}/', ' ', $message ) );
	}

	/**
	 * Runs pending providers once, then lets other code adjust the types.
	 *
	 * @return void
	 */
	private function resolve(): void {
		if ( $this->resolved ) {
			return;
		}

		$this->resolved  = true;
		$providers       = $this->providers;
		$this->providers = array();

		foreach ( $providers as $provider ) {
			$provider( $this );
		}

		/**
		 * Filters the registered event types.
		 *
		 * @param array<string, EventType> $types Types keyed by key.
		 */
		$this->types = self::only_types( apply_filters( 'wpal_event_types', $this->types ), $this->types );
	}

	/**
	 * Keeps only EventType values from a filter result, re-keyed by their key.
	 *
	 * Third-party filters may return anything, so non-arrays fall back to the unfiltered types.
	 *
	 * @param mixed                    $types    Filtered value.
	 * @param array<string, EventType> $fallback Types before filtering.
	 *
	 * @return array<string, EventType> Clean types.
	 */
	private static function only_types( mixed $types, array $fallback ): array {
		if ( ! is_array( $types ) ) {
			return $fallback;
		}

		$clean = array();

		foreach ( $types as $type ) {
			if ( $type instanceof EventType ) {
				$clean[ $type->key ] = $type;
			}
		}

		return $clean;
	}

	/**
	 * Returns the template used for unregistered types.
	 *
	 * @param Event $event Event.
	 *
	 * @return string Template.
	 */
	private static function fallback_template( Event $event ): string {
		return '' === $event->object_label && '' === $event->object_id ? '{type}' : '{type}: {object}';
	}

	/**
	 * Builds the placeholder => value map for an event.
	 *
	 * @param Event          $event Event.
	 * @param EventType|null $type  Its type, if registered.
	 *
	 * @return array<string, string> Values.
	 */
	private static function placeholder_values( Event $event, ?EventType $type ): array {
		$values = array();

		foreach ( $event->context as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$values[ (string) $key ] = (string) $value;
			}
		}

		return array_merge(
			$values,
			array(
				'object'      => '' !== $event->object_label ? $event->object_label : $event->object_id,
				'object_id'   => $event->object_id,
				'object_type' => $event->object_type,
				'user'        => $event->user_login,
				'type'        => null === $type ? $event->event_type : $type->label,
			)
		);
	}
}
