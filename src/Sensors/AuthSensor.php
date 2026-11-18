<?php
/**
 * Authentication sensor.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Sensors;

use WPActivityLedger\Database\EventRepository;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Loader;
use WPActivityLedger\Recording\Recorder;
use WPActivityLedger\Settings\Settings;
use WPActivityLedger\Support\RequestContext;

/**
 * Records logins, logouts and failed logins.
 *
 * Repeated failed logins for the same username and IP within the dedupe window update
 * one row (context "count") instead of adding a row per attempt.
 */
final class AuthSensor implements SensorInterface {

	/**
	 * Session token of a login in progress (the cookie isn't readable yet during wp_login).
	 *
	 * @var string
	 */
	private string $login_token = '';

	/**
	 * Creates the sensor.
	 *
	 * @param Recorder        $recorder   Recorder.
	 * @param EventRepository $repository Storage (for failed-login folding).
	 * @param Settings        $settings   Settings.
	 */
	public function __construct(
		private readonly Recorder $recorder,
		private readonly EventRepository $repository,
		private readonly Settings $settings
	) {
	}

	/**
	 * Queues the hooks.
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'set_logged_in_cookie', array( $this, 'remember_token' ), 10, 6 );
		$loader->add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );
		$loader->add_action( 'wp_logout', array( $this, 'on_logout' ), 10, 1 );
		$loader->add_action( 'wp_login_failed', array( $this, 'on_login_failed' ), 10, 1 );
		$loader->add_action( 'retrieve_password', array( $this, 'on_reset_requested' ), 10, 1 );
		$loader->add_action( 'after_password_reset', array( $this, 'on_password_reset' ), 10, 1 );
		$loader->add_action( 'wp_create_application_password', array( $this, 'on_app_password_created' ), 10, 2 );
		$loader->add_action( 'wp_delete_application_password', array( $this, 'on_app_password_revoked' ), 10, 2 );
	}

	/**
	 * Records a password reset request (by the user or by an admin from the Users screen).
	 *
	 * @param string $user_login Login of the account.
	 *
	 * @return void
	 */
	public function on_reset_requested( $user_login ): void {
		$user = get_user_by( 'login', (string) $user_login );

		$this->recorder->record(
			new Event(
				'auth.password_reset_requested',
				object_type: 'user',
				object_id: false === $user ? '' : (string) $user->ID,
				object_label: (string) $user_login
			)
		);
	}

	/**
	 * Records a completed password reset. The user isn't logged in, so they are the actor.
	 *
	 * @param mixed $user User whose password was reset.
	 *
	 * @return void
	 */
	public function on_password_reset( mixed $user = null ): void {
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$this->recorder->record( $this->user_event( 'auth.password_reset', $user, array(), get_current_user_id() > 0 ? 0 : $user->ID ) );
	}

	/**
	 * Records a new application password (never the password itself).
	 *
	 * @param int                  $user_id  Owner.
	 * @param array<string, mixed> $new_item Item details.
	 *
	 * @return void
	 */
	public function on_app_password_created( $user_id, $new_item = array() ): void {
		$this->record_app_password( 'auth.app_password_created', (int) $user_id, (array) $new_item );
	}

	/**
	 * Records a revoked application password.
	 *
	 * @param int                  $user_id Owner.
	 * @param array<string, mixed> $item    Item details.
	 *
	 * @return void
	 */
	public function on_app_password_revoked( $user_id, $item = array() ): void {
		$this->record_app_password( 'auth.app_password_revoked', (int) $user_id, (array) $item );
	}

	/**
	 * Records an application password event.
	 *
	 * @param string               $type    Event type.
	 * @param int                  $user_id Owner.
	 * @param array<string, mixed> $item    Item details.
	 *
	 * @return void
	 */
	private function record_app_password( string $type, int $user_id, array $item ): void {
		$user = get_userdata( $user_id );

		if ( false === $user ) {
			return;
		}

		$this->recorder->record(
			$this->user_event(
				$type,
				$user,
				array(
					'app_name' => sanitize_text_field( (string) ( $item['name'] ?? '' ) ),
					'app_uuid' => sanitize_text_field( (string) ( $item['uuid'] ?? '' ) ),
				)
			)
		);
	}

	/**
	 * Builds an event about a user account.
	 *
	 * @param string               $type     Event type.
	 * @param \WP_User             $user     The account.
	 * @param array<string, mixed> $context  Context.
	 * @param int                  $actor_id Actor (0 = current user).
	 *
	 * @return Event Event.
	 */
	private function user_event( string $type, \WP_User $user, array $context = array(), int $actor_id = 0 ): Event {
		return new Event(
			$type,
			object_type: 'user',
			object_id: (string) $user->ID,
			object_label: (string) $user->user_login,
			context: $context,
			user_id: $actor_id
		);
	}

	/**
	 * Remembers the session token of the login being set up.
	 *
	 * @param string $cookie     Cookie value.
	 * @param int    $expire     Cookie expiry.
	 * @param int    $expiration Session expiry.
	 * @param int    $user_id    User ID.
	 * @param string $scheme     Cookie scheme.
	 * @param string $token      Session token.
	 *
	 * @return void
	 */
	public function remember_token( $cookie, $expire, $expiration, $user_id, $scheme, $token = '' ): void {
		unset( $cookie, $expire, $expiration, $user_id, $scheme );
		$this->login_token = (string) $token;
	}

	/**
	 * Records a successful login.
	 *
	 * @param string $user_login Login name.
	 * @param mixed  $user       User (a WP_User from core; checked because any code can fire the hook).
	 *
	 * @return void
	 */
	public function on_login( $user_login, mixed $user = null ): void {
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$this->recorder->record(
			new Event(
				'auth.login',
				object_type: 'user',
				object_id: (string) $user->ID,
				object_label: (string) $user_login,
				user_id: $user->ID,
				session_hash: RequestContext::hash_token( $this->login_token )
			)
		);
	}

	/**
	 * Records a logout. The current user is already cleared, so the passed ID is used.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return void
	 */
	public function on_logout( $user_id = 0 ): void {
		$user = get_userdata( (int) $user_id );

		if ( false === $user ) {
			return;
		}

		$this->recorder->record(
			new Event(
				'auth.logout',
				object_type: 'user',
				object_id: (string) $user->ID,
				object_label: (string) $user->user_login,
				user_id: $user->ID
			)
		);
	}

	/**
	 * Records a failed login, or bumps the count of a recent identical failure.
	 *
	 * @param string $username Attempted username or email.
	 *
	 * @return void
	 */
	public function on_login_failed( $username ): void {
		$username = sanitize_user( (string) $username );

		if ( $this->fold_into_recent( $username ) ) {
			return;
		}

		$user = get_user_by( 'login', $username );
		$user = false === $user ? get_user_by( 'email', $username ) : $user;

		$this->recorder->record(
			new Event(
				'auth.login_failed',
				object_type: 'user',
				object_id: false === $user ? '' : (string) $user->ID,
				object_label: $username,
				context: array(
					'count'        => 1,
					'user_exists'  => false !== $user,
					'last_attempt' => gmdate( 'Y-m-d H:i:s' ),
				)
			)
		);
	}

	/**
	 * Adds this attempt to a recent failed-login row for the same username and IP.
	 *
	 * @param string $username Attempted username.
	 *
	 * @return bool True when an existing row was updated.
	 */
	private function fold_into_recent( string $username ): bool {
		$minutes = (int) $this->settings->get( 'failed_login_dedupe_minutes' );

		if ( $minutes <= 0 ) {
			return false;
		}

		$now    = gmdate( 'Y-m-d H:i:s' );
		$since  = gmdate( 'Y-m-d H:i:s', time() - $minutes * MINUTE_IN_SECONDS );
		$recent = $this->repository->find_recent( 'auth.login_failed', $username, $this->recorder->ip(), $since );

		if ( null === $recent ) {
			return false;
		}

		$context                 = $recent['context'];
		$context['count']        = (int) ( $context['count'] ?? 1 ) + 1;
		$context['last_attempt'] = $now;
		$message                 = $this->recorder->with_message(
			new Event( 'auth.login_failed', object_label: $username, context: $context )
		)->message;

		if ( ! $this->repository->update_summary( (int) $recent['id'], $context, $message, $now ) ) {
			return false;
		}

		/**
		 * Fires when a repeated event updates its earlier row instead of adding one, so
		 * threshold alerts still count every occurrence.
		 *
		 * @param Event $event The repeated event, enriched like a stored one.
		 */
		do_action(
			'wpal_event_repeated',
			$this->recorder->enriched(
				new Event( 'auth.login_failed', object_type: 'user', object_id: (string) $recent['object_id'], object_label: $username, context: $context )
			)
		);

		return true;
	}
}
