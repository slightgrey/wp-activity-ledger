<?php
/**
 * Turns stored events into queued alerts.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Alerts;

use WPActivityLedger\Admin\Menu;
use WPActivityLedger\Domain\Category;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Domain\EventTypeRegistry;
use WPActivityLedger\Domain\Severity;

/**
 * After each flush (and for each folded repeat), checks the events against the active
 * rules and queues one delivery job per matching rule and channel. Sending happens in the
 * background, so requests never wait for a webhook.
 */
final class Dispatcher {

	/**
	 * Event types that never trigger alerts (an alert about a failed alert could loop).
	 */
	private const IGNORED = array( 'system.alert_failed' );

	/**
	 * Event types sent right away instead of queued: after deactivation, no queued job
	 * would ever run.
	 */
	private const IMMEDIATE = array( 'system.deactivated' );

	/**
	 * Active rules, loaded once per request.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $rules = null;

	/**
	 * Creates the dispatcher.
	 *
	 * @param RuleStore         $store     Rules.
	 * @param RuleMatcher       $matcher   Matcher.
	 * @param EventTypeRegistry $types     Event types (labels).
	 * @param Deliverer         $deliverer Sends immediate alerts.
	 */
	public function __construct(
		private readonly RuleStore $store,
		private readonly RuleMatcher $matcher,
		private readonly EventTypeRegistry $types,
		private readonly Deliverer $deliverer
	) {
	}

	/**
	 * Checks a flushed batch.
	 *
	 * @param mixed $events Stored events.
	 *
	 * @return void
	 */
	public function on_flushed( mixed $events = array() ): void {
		// Other code may fire the hook with do_action(), which passes a single event unwrapped.
		$events = $events instanceof Event ? array( $events ) : $events;

		foreach ( is_array( $events ) ? $events : array() as $event ) {
			if ( $event instanceof Event ) {
				$this->check( $event );
			}
		}
	}

	/**
	 * Checks a repeated event (counted towards thresholds like a new one).
	 *
	 * @param mixed $event Event.
	 *
	 * @return void
	 */
	public function on_repeated( mixed $event = null ): void {
		if ( $event instanceof Event ) {
			$this->check( $event );
		}
	}

	/**
	 * Drops the cached rules after they change.
	 *
	 * @return void
	 */
	public function forget_rules(): void {
		$this->rules = null;
	}

	/**
	 * Queues the alerts an event triggers.
	 *
	 * @param Event $event Event.
	 *
	 * @return int Jobs queued.
	 */
	public function check( Event $event ): int {
		if ( in_array( $event->event_type, self::IGNORED, true ) ) {
			return 0;
		}

		$this->rules ??= $this->store->active();
		$queued        = 0;

		foreach ( $this->rules as $rule ) {
			if ( ! $this->matcher->fires( $rule, $event, time() ) ) {
				continue;
			}

			$message = $this->message( $event, (string) $rule['name'] )->to_array();

			foreach ( (array) $rule['channels'] as $channel_id ) {
				$job = array(
					'channel' => (string) $channel_id,
					'rule'    => (string) $rule['id'],
					'message' => $message,
					'attempt' => in_array( $event->event_type, self::IMMEDIATE, true ) ? Deliverer::MAX_ATTEMPTS : 1,
				);

				in_array( $event->event_type, self::IMMEDIATE, true ) ? $this->deliverer->deliver( $job ) : Queue::push( $job );
				++$queued;
			}
		}

		return $queued;
	}

	/**
	 * Builds the alert content of an event.
	 *
	 * @param Event  $event Event.
	 * @param string $rule  Rule name.
	 *
	 * @return AlertMessage Alert.
	 */
	public function message( Event $event, string $rule ): AlertMessage {
		$severity = Severity::tryFrom( (int) $event->severity ) ?? Severity::Info;
		$query    = array_filter(
			array(
				'type'      => $event->event_type,
				'object_id' => $event->object_id,
			)
		);

		return new AlertMessage(
			title: $this->types->get( $event->event_type )->label ?? $event->event_type,
			message: '' !== $event->message ? $event->message : $this->types->render_message( $event ),
			event_type: $event->event_type,
			severity: $severity->slug(),
			category: Category::tryFrom( $event->category )?->label() ?? $event->category,
			user: $event->user_login,
			ip: $event->ip,
			object_label: $event->object_label,
			created_at: $event->created_at,
			site_name: wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			site_url: home_url( '/' ),
			log_url: Menu::url( '/log?' . http_build_query( $query ) ),
			rule: $rule
		);
	}
}
