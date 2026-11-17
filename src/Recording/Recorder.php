<?php
/**
 * Buffers events during a request and stores them in one go.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Recording;

use WPActivityLedger\Database\EventRepository;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Domain\EventTypeRegistry;
use WPActivityLedger\Loader;
use WPActivityLedger\Settings\Settings;
use WPActivityLedger\Support\IpResolver;
use WPActivityLedger\Support\RequestContext;

/**
 * Collects events from the sensors, fills in who/where/when, and writes them all at
 * shutdown with as few queries as possible. Long-running requests (CLI, cron) flush
 * every FLUSH_EVERY events to keep memory bounded.
 */
final class Recorder {

	/**
	 * Buffer size that triggers an early flush in CLI and cron.
	 */
	public const FLUSH_EVERY = 100;

	/**
	 * Events waiting to be stored.
	 *
	 * @var array<int, Event>
	 */
	private array $buffer = array();

	/**
	 * Request facts, built on first use.
	 *
	 * @var RequestContext|null
	 */
	private ?RequestContext $request = null;

	/**
	 * Creates the recorder.
	 *
	 * @param EventRepository   $repository Storage.
	 * @param EventTypeRegistry $types      Type registry (categories, severities, messages).
	 * @param Settings          $settings   Settings.
	 */
	public function __construct(
		private readonly EventRepository $repository,
		private readonly EventTypeRegistry $types,
		private readonly Settings $settings
	) {
	}

	/**
	 * Hooks the flush to shutdown (early priority, before other plugins tear things down).
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'shutdown', array( $this, 'flush' ), 1, 0 );
	}

	/**
	 * Adds an event to the buffer after filling in the actor and request details.
	 *
	 * @param Event $event Event from a sensor.
	 *
	 * @return void
	 */
	public function record( Event $event ): void {
		$event = $this->enrich( $event );

		if ( ! ( new LogPolicy( $this->settings ) )->allows( $event, $this->request()->source() ) ) {
			return;
		}

		/**
		 * Filters an event right before it is buffered. Return anything but an Event to drop it.
		 *
		 * @param Event $event The enriched event.
		 */
		$event = self::as_event( apply_filters( 'wpal_event_before_save', $event ) );

		if ( null === $event ) {
			return;
		}

		$this->buffer[] = $event;

		if ( count( $this->buffer ) >= self::FLUSH_EVERY && $this->is_long_running() ) {
			$this->flush();
		}
	}

	/**
	 * Records an event and stores it (with anything buffered before it) right away.
	 *
	 * For events that must survive even if the request ends abruptly, such as the
	 * plugin's own deactivation or a log purge.
	 *
	 * @param Event $event Event.
	 *
	 * @return void
	 */
	public function record_now( Event $event ): void {
		$this->record( $event );
		$this->flush();
	}

	/**
	 * Stores every buffered event and empties the buffer.
	 *
	 * @return int Number of events stored.
	 */
	public function flush(): int {
		if ( array() === $this->buffer ) {
			return 0;
		}

		$events       = array_map( array( $this, 'with_message' ), $this->buffer );
		$this->buffer = array();
		$stored       = $this->repository->insert_many( $events );

		/**
		 * Fires after a batch of events was stored (alerts listen here).
		 *
		 * @param array<int, Event> $events Stored events.
		 */
		do_action( 'wpal_events_flushed', $events );

		return $stored;
	}

	/**
	 * Returns the buffered events (for tests and for sensors that merge events).
	 *
	 * @return array<int, Event> Buffered events.
	 */
	public function pending(): array {
		return $this->buffer;
	}

	/**
	 * Accepts a filter result only when it is still an Event (third-party filters may return anything).
	 *
	 * @param mixed $value Filter result.
	 *
	 * @return Event|null The event, or null to drop it.
	 */
	private static function as_event( mixed $value ): ?Event {
		return $value instanceof Event ? $value : null;
	}

	/**
	 * Fills in category, severity, actor, request details and time.
	 *
	 * @param Event $event Event.
	 *
	 * @return Event Enriched event.
	 */
	private function enrich( Event $event ): Event {
		$type    = $this->types->get( $event->event_type );
		$request = $this->request();

		return $event->with(
			array_merge(
				$this->actor( $event ),
				array(
					'category'     => '' !== $event->category ? $event->category : ( $type?->category->value ?? 'system' ),
					'severity'     => $event->severity ?? $type?->severity->value ?? 0,
					'ip'           => '' !== $event->ip ? $event->ip : $this->ip(),
					'user_agent'   => $this->settings->get( 'store_user_agent' ) ? $request->user_agent() : '',
					'session_hash' => '' !== $event->session_hash ? $event->session_hash : $request->session_hash(),
					'context'      => array_merge( $event->context, array( 'request' => $request->to_context() ) ),
					'created_at'   => '' !== $event->created_at ? $event->created_at : gmdate( 'Y-m-d H:i:s' ),
				)
			)
		);
	}

	/**
	 * Returns the user fields: the event's own user when set, otherwise the current user.
	 *
	 * @param Event $event Event.
	 *
	 * @return array{user_id: int, user_login: string, user_roles: array<int, string>} Fields.
	 */
	private function actor( Event $event ): array {
		$user_id = $event->user_id > 0 ? $event->user_id : get_current_user_id();
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;

		return array(
			'user_id'    => $user_id,
			'user_login' => '' !== $event->user_login ? $event->user_login : ( false === $user ? '' : (string) $user->user_login ),
			'user_roles' => array() !== $event->user_roles ? $event->user_roles : ( false === $user ? array() : array_values( $user->roles ) ),
		);
	}

	/**
	 * Returns a copy of the event with its message rendered (done at flush, after init).
	 *
	 * @param Event $event Event.
	 *
	 * @return Event Event with message.
	 */
	public function with_message( Event $event ): Event {
		return '' !== $event->message ? $event : $event->with( array( 'message' => $this->types->render_message( $event ) ) );
	}

	/**
	 * Resolves the client IP according to the privacy settings.
	 *
	 * @return string IP.
	 */
	public function ip(): string {
		$resolver = new IpResolver(
			$_SERVER, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- IpResolver validates every value it reads.
			(string) $this->settings->get( 'trusted_proxy_header' ),
			(bool) $this->settings->get( 'anonymize_ip' )
		);

		return $resolver->resolve();
	}

	/**
	 * Returns the request facts, built once per request.
	 *
	 * @return RequestContext Request.
	 */
	private function request(): RequestContext {
		$this->request ??= RequestContext::from_globals();

		return $this->request;
	}

	/**
	 * Tells whether this request may run long (CLI or cron), so the buffer is flushed early.
	 *
	 * @return bool True for CLI and cron.
	 */
	private function is_long_running(): bool {
		return in_array( $this->request()->source(), array( 'cli', 'cron' ), true );
	}
}
