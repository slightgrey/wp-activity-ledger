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
	 * Site each buffered event happened on (multisite), by buffer index.
	 *
	 * @var array<int, int>
	 */
	private array $sites = array();

	/**
	 * Site to attribute recorded events to instead of the current one (see as_site()).
	 *
	 * @var int|null
	 */
	private ?int $site_override = null;

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
		$this->sites[]  = $this->site_override ?? get_current_blog_id();

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
	 * Runs a callback whose recorded events belong to the given site (multisite).
	 *
	 * @param int      $site_id  Site ID.
	 * @param callable $callback Callback.
	 *
	 * @return void
	 */
	public function as_site( int $site_id, callable $callback ): void {
		$previous            = $this->site_override;
		$this->site_override = $site_id;

		try {
			$callback();
		} finally {
			$this->site_override = $previous;
		}
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
		$sites        = $this->sites;
		$this->buffer = array();
		$this->sites  = array();
		$stored       = $this->store( $events, $sites );

		/**
		 * Fires after a batch of events was stored (alerts listen here).
		 *
		 * @param array<int, Event> $events Stored events.
		 */
		// do_action() would unwrap a one-item array into the bare Event; pass the list as is.
		do_action_ref_array( 'wpal_events_flushed', array( $events ) );

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
	 * Stores events. On multisite each event goes to the log of the site it happened on
	 * (code may switch sites mid-request), and network events (context "network") to the
	 * main site's log.
	 *
	 * @param array<int, Event> $events Events.
	 * @param array<int, int>   $sites  Site of each event, by index.
	 *
	 * @return int Stored.
	 */
	private function store( array $events, array $sites ): int {
		if ( ! is_multisite() ) {
			return $this->repository->insert_many( $events );
		}

		$current = get_current_blog_id();
		$groups  = array();

		foreach ( $events as $index => $event ) {
			$site              = self::is_network( $event ) ? get_main_site_id() : ( $sites[ $index ] ?? $current );
			$groups[ $site ][] = $event;
		}

		$stored = 0;

		foreach ( $groups as $site => $group ) {
			$stored += $site === $current ? $this->repository->insert_many( $group ) : $this->store_in( (int) $site, $group );
		}

		return $stored;
	}

	/**
	 * Stores events in another site's log (skipped when that site no longer exists).
	 *
	 * @param int               $site_id Site ID.
	 * @param array<int, Event> $events  Events.
	 *
	 * @return int Stored.
	 */
	private function store_in( int $site_id, array $events ): int {
		if ( ! get_site( $site_id ) instanceof \WP_Site ) {
			return 0;
		}

		switch_to_blog( $site_id );

		try {
			return $this->repository->insert_many( $events );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Tells whether an event belongs to the network log.
	 *
	 * @param Event $event Event.
	 *
	 * @return bool True for network events.
	 */
	private static function is_network( Event $event ): bool {
		return ! empty( $event->context['network'] );
	}

	/**
	 * Returns an event as it would be stored (enriched, with its message), without storing it.
	 *
	 * For events that update an existing row instead of adding one (folded failed logins).
	 *
	 * @param Event $event Event.
	 *
	 * @return Event Enriched event.
	 */
	public function enriched( Event $event ): Event {
		return $this->with_message( $this->enrich( $event ) );
	}

	/**
	 * Fills in the actor, category, severity, request details and time.
	 *
	 * @param Event $event Event from a sensor.
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
