<?php
/**
 * Decides which rules fire for an event.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Alerts;

use WPActivityLedger\Domain\Event;

/**
 * Matches an event against a rule (types, categories, minimum severity, actor roles),
 * then applies the rule's threshold ("N matches within M minutes, per IP or user") and
 * cooldown. Pure apart from the injected state.
 *
 * Criteria given together must all match; within a list, any value matches. A threshold
 * rule cools down for its window after firing unless it sets its own cooldown.
 */
final class RuleMatcher {

	/**
	 * Creates the matcher.
	 *
	 * @param AlertState $state Threshold counters and cooldowns.
	 */
	public function __construct( private readonly AlertState $state ) {
	}

	/**
	 * Tells whether a rule should send an alert for an event.
	 *
	 * @param array<string, mixed> $rule  Rule.
	 * @param Event                $event Event (enriched: severity and roles set).
	 * @param int                  $now   Current Unix time.
	 *
	 * @return bool True to alert.
	 */
	public function fires( array $rule, Event $event, int $now ): bool {
		if ( ! self::matches( (array) ( $rule['match'] ?? array() ), $event ) ) {
			return false;
		}

		$threshold = is_array( $rule['threshold'] ?? null ) ? $rule['threshold'] : null;
		$key       = (string) ( $rule['id'] ?? '' ) . ( null === $threshold ? '' : ':' . self::group( $threshold, $event ) );

		if ( null !== $threshold && $this->state->hit( $key, $now, (int) $threshold['minutes'] * 60 ) < (int) $threshold['count'] ) {
			return false;
		}

		if ( $this->state->cooling( $key, $now ) ) {
			return false;
		}

		$cooldown = (int) ( $rule['cooldown_minutes'] ?? 0 );
		$cooldown = 0 === $cooldown && null !== $threshold ? (int) $threshold['minutes'] : $cooldown;

		if ( $cooldown > 0 ) {
			$this->state->cool_until( $key, $now + $cooldown * 60 );
		}

		return true;
	}

	/**
	 * Tells whether an event meets a rule's criteria.
	 *
	 * @param array<string, mixed> $criteria Criteria.
	 * @param Event                $event Event.
	 *
	 * @return bool True when every given criterion matches.
	 */
	public static function matches( array $criteria, Event $event ): bool {
		$types      = (array) ( $criteria['event_types'] ?? array() );
		$categories = (array) ( $criteria['categories'] ?? array() );
		$roles      = (array) ( $criteria['roles'] ?? array() );

		return ( array() === $types || in_array( $event->event_type, $types, true ) )
			&& ( array() === $categories || in_array( $event->category, $categories, true ) )
			&& (int) $event->severity >= (int) ( $criteria['min_severity'] ?? 0 )
			&& ( array() === $roles || array() !== array_intersect( $roles, $event->user_roles ) );
	}

	/**
	 * Returns the threshold group of an event: its IP or its acting user.
	 *
	 * @param array<string, mixed> $threshold Threshold.
	 * @param Event                $event     Event.
	 *
	 * @return string Group.
	 */
	private static function group( array $threshold, Event $event ): string {
		return 'user' === ( $threshold['group_by'] ?? 'ip' ) ? 'user:' . ( 0 === $event->user_id ? $event->object_label : (string) $event->user_id ) : 'ip:' . $event->ip;
	}
}
