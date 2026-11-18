<?php
/**
 * Alert rules.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Alerts;

use WPActivityLedger\Domain\Category;
use WPActivityLedger\Domain\EventType;

/**
 * Alert rules (option "wpal_alert_rules"):
 * { id, name, enabled, match: { event_types[], categories[], min_severity, roles[] },
 *   threshold: { count, minutes, group_by: ip|user } | null, cooldown_minutes, channels[], template }.
 *
 * A rule must narrow the events somehow (types, categories or a minimum severity), so a
 * mistake can't send an alert for every event. Unknown channel IDs are dropped.
 */
final class RuleStore extends ListStore {

	/**
	 * Option name.
	 */
	public const OPTION = 'wpal_alert_rules';

	/**
	 * Creates the store.
	 *
	 * @param ChannelStore $channels Channels (to validate channel IDs).
	 */
	public function __construct( private readonly ChannelStore $channels ) {
	}

	/**
	 * Returns the option name.
	 *
	 * @return string Option.
	 */
	protected function option(): string {
		return self::OPTION;
	}

	/**
	 * Returns the enabled rules that have at least one channel.
	 *
	 * @return array<int, array<string, mixed>> Rules.
	 */
	public function active(): array {
		return array_values( array_filter( $this->all(), static fn( array $rule ): bool => ! empty( $rule['enabled'] ) && ! empty( $rule['channels'] ) ) );
	}

	/**
	 * Removes a deleted channel from every rule.
	 *
	 * @param string $channel_id Channel ID.
	 *
	 * @return void
	 */
	public function forget_channel( string $channel_id ): void {
		$rules = array_map(
			static function ( array $rule ) use ( $channel_id ): array {
				$rule['channels'] = array_values( array_diff( (array) ( $rule['channels'] ?? array() ), array( $channel_id ) ) );

				return $rule;
			},
			$this->all()
		);

		$this->save( $rules );
	}

	/**
	 * Validates a rule.
	 *
	 * @param array<string, mixed>      $input    Submitted fields.
	 * @param array<string, mixed>|null $existing Stored rule.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, string>} Rule and errors.
	 */
	protected function sanitize( array $input, ?array $existing ): array {
		$errors = array();
		$match  = self::match( (array) self::field( $input, $existing, 'match', array() ) );

		if ( array() === $match['event_types'] && array() === $match['categories'] && 0 === $match['min_severity'] ) {
			$errors['match'] = __( 'Choose event types, categories or a minimum severity.', 'wp-activity-ledger' );
		}

		$rule = array(
			'name'             => self::name( self::field( $input, $existing, 'name', '' ), $errors ),
			'enabled'          => (bool) self::field( $input, $existing, 'enabled', true ),
			'match'            => $match,
			'threshold'        => self::threshold( self::field( $input, $existing, 'threshold', null ) ),
			'cooldown_minutes' => min( 1440, max( 0, (int) self::field( $input, $existing, 'cooldown_minutes', 0 ) ) ),
			'channels'         => $this->channel_ids( self::field( $input, $existing, 'channels', array() ) ),
			'template'         => sanitize_key( (string) self::field( $input, $existing, 'template', '' ) ),
		);

		return array( $rule, $errors );
	}

	/**
	 * Validates the match criteria.
	 *
	 * @param array<mixed> $criteria Submitted criteria.
	 *
	 * @return array{event_types: array<int, string>, categories: array<int, string>, min_severity: int, roles: array<int, string>} Criteria.
	 */
	private static function match( array $criteria ): array {
		$strings = static fn( mixed $values ): array => array_values( array_unique( array_map( 'strval', is_array( $values ) ? $values : array() ) ) );

		return array(
			'event_types'  => array_values( array_filter( $strings( $criteria['event_types'] ?? array() ), array( EventType::class, 'is_valid_key' ) ) ),
			'categories'   => array_values( array_filter( $strings( $criteria['categories'] ?? array() ), static fn( string $c ): bool => null !== Category::tryFrom( $c ) ) ),
			'min_severity' => min( 3, max( 0, (int) ( $criteria['min_severity'] ?? 0 ) ) ),
			'roles'        => array_values( array_filter( $strings( $criteria['roles'] ?? array() ), static fn( string $r ): bool => 1 === preg_match( '/^[a-z0-9_-]{1,60}$/', $r ) ) ),
		);
	}

	/**
	 * Validates the optional threshold ("count events within minutes, per IP or user").
	 *
	 * @param mixed $threshold Submitted threshold.
	 *
	 * @return array{count: int, minutes: int, group_by: string}|null Threshold, or null for none.
	 */
	private static function threshold( mixed $threshold ): ?array {
		if ( ! is_array( $threshold ) || (int) ( $threshold['count'] ?? 0 ) < 2 ) {
			return null;
		}

		return array(
			'count'    => min( 1000, (int) $threshold['count'] ),
			'minutes'  => min( 1440, max( 1, (int) ( $threshold['minutes'] ?? 10 ) ) ),
			'group_by' => 'user' === ( $threshold['group_by'] ?? 'ip' ) ? 'user' : 'ip',
		);
	}

	/**
	 * Keeps the IDs of existing channels.
	 *
	 * @param mixed $ids Submitted IDs.
	 *
	 * @return array<int, string> Channel IDs.
	 */
	private function channel_ids( mixed $ids ): array {
		$known = array_column( $this->channels->all(), 'id' );

		return array_values( array_intersect( array_unique( array_map( 'strval', is_array( $ids ) ? $ids : array() ) ), $known ) );
	}
}
