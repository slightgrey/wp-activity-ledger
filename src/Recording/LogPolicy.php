<?php
/**
 * Decides which events are recorded.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Recording;

use WPActivityLedger\Domain\Category;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Settings\Settings;

/**
 * Applies the "who and what to log" settings to an enriched event.
 *
 * Activity Ledger's own events (category "system") are always kept, so excluding a
 * user or disabling types can't hide tampering with the log itself.
 */
final class LogPolicy {

	/**
	 * Creates the policy.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * Tells whether an event should be stored.
	 *
	 * @param Event  $event  Enriched event (actor and category filled in).
	 * @param string $source Request source (web, admin, ajax, rest, cron, cli, xmlrpc).
	 *
	 * @return bool True to store.
	 */
	public function allows( Event $event, string $source ): bool {
		$allowed = Category::System->value === $event->category || $this->settings_allow( $event, $source );

		/**
		 * Filters whether an event is recorded.
		 *
		 * @param bool   $allowed Whether the settings allow it.
		 * @param Event  $event   The event.
		 * @param string $source  Request source.
		 */
		return (bool) apply_filters( 'wpal_should_log_event', $allowed, $event, $source );
	}

	/**
	 * Checks the disabled types, anonymous, excluded users and logged roles settings.
	 *
	 * @param Event  $event  Event.
	 * @param string $source Request source.
	 *
	 * @return bool True when the settings allow the event.
	 */
	private function settings_allow( Event $event, string $source ): bool {
		if ( in_array( $event->event_type, (array) $this->settings->get( 'disabled_event_types' ), true ) ) {
			return false;
		}

		if ( 0 === $event->user_id ) {
			return in_array( $source, array( 'cli', 'cron' ), true ) || (bool) $this->settings->get( 'log_anonymous' );
		}

		if ( in_array( $event->user_id, array_map( 'intval', (array) $this->settings->get( 'excluded_users' ) ), true ) ) {
			return false;
		}

		return $this->role_is_logged( $event->user_roles );
	}

	/**
	 * Tells whether any of the user's roles is in the logged roles ("*" = all users).
	 *
	 * @param array<int, string> $roles User's roles.
	 *
	 * @return bool True when logged.
	 */
	private function role_is_logged( array $roles ): bool {
		$logged = (array) $this->settings->get( 'logged_roles' );

		return in_array( '*', $logged, true ) || array() !== array_intersect( $roles, $logged );
	}
}
