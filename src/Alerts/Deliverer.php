<?php
/**
 * Sends alerts through channels.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Alerts;

use WPActivityLedger\Alerts\Formatters\DiscordFormatter;
use WPActivityLedger\Alerts\Formatters\EmailFormatter;
use WPActivityLedger\Alerts\Formatters\Formatter;
use WPActivityLedger\Alerts\Formatters\SlackFormatter;
use WPActivityLedger\Alerts\Formatters\TeamsFormatter;
use WPActivityLedger\Alerts\Formatters\WebhookFormatter;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Loader;
use WPActivityLedger\Recording\Recorder;

/**
 * Runs "wpal_deliver_alert" jobs: formats the alert for the channel and sends it (email
 * with wp_mail, the others as a JSON POST with a 5-second timeout). A failed delivery is
 * retried twice (after 1 and 5 minutes); the third failure is recorded as
 * "system.alert_failed".
 */
final class Deliverer {

	/**
	 * Attempts before giving up.
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * Delay before each retry, in seconds, by attempt number.
	 */
	private const BACKOFF = array(
		1 => 60,
		2 => 300,
	);

	/**
	 * HTTP timeout in seconds.
	 */
	private const TIMEOUT = 5;

	/**
	 * Creates the deliverer.
	 *
	 * @param ChannelStore $channels Channels.
	 * @param Recorder     $recorder Recorder (for failures).
	 */
	public function __construct(
		private readonly ChannelStore $channels,
		private readonly Recorder $recorder
	) {
	}

	/**
	 * Queues the hook.
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( Queue::HOOK, array( $this, 'deliver' ), 10, 1 );
	}

	/**
	 * Runs one delivery job, scheduling a retry or recording the failure.
	 *
	 * @param mixed $job Job: channel, message, attempt.
	 *
	 * @return void
	 */
	public function deliver( mixed $job = null ): void {
		$job     = is_array( $job ) ? $job : array();
		$channel = $this->channels->get( (string) ( $job['channel'] ?? '' ) );

		if ( null === $channel ) {
			return; // The channel was deleted since the alert was queued.
		}

		$error = $this->send( $channel, AlertMessage::from_array( (array) ( $job['message'] ?? array() ) ) );

		if ( null === $error ) {
			return;
		}

		$attempt = max( 1, (int) ( $job['attempt'] ?? 1 ) );

		if ( $attempt < self::MAX_ATTEMPTS ) {
			Queue::push( array_merge( $job, array( 'attempt' => $attempt + 1 ) ), self::BACKOFF[ $attempt ] );

			return;
		}

		$this->record_failure( $channel, $error, $attempt );
	}

	/**
	 * Sends an alert through a channel right away.
	 *
	 * @param array<string, mixed> $channel Channel.
	 * @param AlertMessage         $alert   Alert.
	 *
	 * @return string|null Error message, or null on success.
	 */
	public function send( array $channel, AlertMessage $alert ): ?string {
		$type   = (string) ( $channel['type'] ?? '' );
		$config = (array) ( $channel['config'] ?? array() );

		if ( 'email' === $type ) {
			return self::send_email( (array) ( $config['recipients'] ?? array() ), ( new EmailFormatter() )->format( $alert ) );
		}

		$formatter = self::formatter( $type );

		if ( null === $formatter ) {
			return 'Unknown channel type.';
		}

		$body    = (string) wp_json_encode( $formatter->format( $alert ) );
		$headers = array( 'Content-Type' => 'application/json; charset=utf-8' );

		if ( 'webhook' === $type ) {
			$headers += WebhookSigner::headers( $body, (string) ( $config['secret'] ?? '' ), time() );
		}

		return self::post( (string) ( $config['url'] ?? '' ), $body, $headers );
	}

	/**
	 * Returns a sample alert for "Send test".
	 *
	 * @return AlertMessage Alert.
	 */
	public function sample_message(): AlertMessage {
		$user = wp_get_current_user();

		return new AlertMessage(
			title: __( 'Test alert', 'wp-activity-ledger' ),
			message: __( 'This is a test alert from WP Activity Ledger. If you can read it, the channel works.', 'wp-activity-ledger' ),
			event_type: 'system.alert_test',
			severity: 'notice',
			category: __( 'System', 'wp-activity-ledger' ),
			user: $user->exists() ? $user->user_login : '',
			ip: '',
			object_label: '',
			created_at: gmdate( 'Y-m-d H:i:s' ),
			site_name: wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			site_url: home_url( '/' ),
			log_url: \WPActivityLedger\Admin\Menu::url( '/log' ),
			rule: __( 'Test', 'wp-activity-ledger' )
		);
	}

	/**
	 * Returns the formatter of a webhook-style channel type.
	 *
	 * @param string $type Channel type.
	 *
	 * @return Formatter|null Formatter.
	 */
	private static function formatter( string $type ): ?Formatter {
		return match ( $type ) {
			'webhook' => new WebhookFormatter(),
			'slack'   => new SlackFormatter(),
			'discord' => new DiscordFormatter(),
			'teams'   => new TeamsFormatter(),
			default   => null,
		};
	}

	/**
	 * POSTs a JSON body.
	 *
	 * @param string                $url     URL.
	 * @param string                $body    Body.
	 * @param array<string, string> $headers Headers.
	 *
	 * @return string|null Error, or null for a 2xx response.
	 */
	private static function post( string $url, string $body, array $headers ): ?string {
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => $headers,
				'body'        => $body,
				'user-agent'  => 'WP-Activity-Ledger/' . WPAL_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return $code >= 200 && $code < 300 ? null : sprintf( 'HTTP %d', $code );
	}

	/**
	 * Sends an HTML email (with a plain-text alternative).
	 *
	 * @param array<int, string>                                 $recipients Addresses.
	 * @param array{subject: string, html: string, text: string} $email      Email.
	 *
	 * @return string|null Error, or null when wp_mail accepted it.
	 */
	private static function send_email( array $recipients, array $email ): ?string {
		if ( array() === $recipients ) {
			return 'No recipients.';
		}

		$error   = null;
		$capture = static function ( \WP_Error $failure ) use ( &$error ): void {
			$error = $failure->get_error_message();
		};
		$alt     = static function ( $mailer ) use ( $email ): void {
			if ( $mailer instanceof \PHPMailer\PHPMailer\PHPMailer ) {
				$mailer->AltBody = $email['text']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
			}
		};

		add_action( 'wp_mail_failed', $capture );
		add_action( 'phpmailer_init', $alt );
		$sent = wp_mail( $recipients, $email['subject'], $email['html'], array( 'Content-Type: text/html; charset=UTF-8' ) );
		remove_action( 'wp_mail_failed', $capture );
		remove_action( 'phpmailer_init', $alt );

		return $sent ? null : ( $error ?? 'wp_mail() failed.' );
	}

	/**
	 * Records a delivery that failed for good.
	 *
	 * @param array<string, mixed> $channel  Channel.
	 * @param string               $error    Last error.
	 * @param int                  $attempts Attempts made.
	 *
	 * @return void
	 */
	private function record_failure( array $channel, string $error, int $attempts ): void {
		$this->recorder->record_now(
			new Event(
				'system.alert_failed',
				object_type: 'alerts',
				object_id: (string) $channel['id'],
				object_label: (string) ( $channel['name'] ?? '' ),
				context: array(
					'error'    => mb_substr( $error, 0, 300 ),
					'attempts' => $attempts,
					'type'     => (string) ( $channel['type'] ?? '' ),
				)
			)
		);
	}
}
