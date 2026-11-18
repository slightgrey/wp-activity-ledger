<?php
/**
 * Tests for alert dispatch and delivery.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Alerts;

use WPActivityLedger\Alerts\Queue;
use WPActivityLedger\Alerts\WebhookSigner;
use WPActivityLedger\Plugin;

/**
 * @covers \WPActivityLedger\Alerts\Dispatcher
 * @covers \WPActivityLedger\Alerts\Deliverer
 * @covers \WPActivityLedger\Alerts\Queue
 */
final class AlertDeliveryTest extends AlertsTestCase {

	/**
	 * Receiver fixture installed for the test.
	 */
	private const RECEIVER = 'wpal-test-hook.php';

	/**
	 * File the receiver writes.
	 *
	 * @return string Path.
	 */
	private static function received_file(): string {
		return WP_CONTENT_DIR . '/wpal-test-hook.json';
	}

	/**
	 * Installs the receiver (a mu-plugin) and skips without Action Scheduler.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler is not available.' );
		}

		wp_mkdir_p( WPMU_PLUGIN_DIR );
		copy( dirname( __DIR__, 2 ) . '/fixtures/' . self::RECEIVER, WPMU_PLUGIN_DIR . '/' . self::RECEIVER );
		wp_delete_file( self::received_file() );
	}

	/**
	 * Removes the receiver and its output.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		wp_delete_file( WPMU_PLUGIN_DIR . '/' . self::RECEIVER );
		wp_delete_file( self::received_file() );
		parent::tearDown();
	}

	/**
	 * Activating a plugin queues one job; running it POSTs a signed payload.
	 *
	 * @return void
	 */
	public function test_plugin_activation_sends_signed_webhook(): void {
		$channel = self::channel(
			array(
				'type'   => 'webhook',
				'name'   => 'Receiver',
				'config' => array( 'url' => home_url( '/?wpal_test_hook=1' ) ),
			)
		);
		self::rule(
			array(
				'name'     => 'Plugin changes',
				'match'    => array( 'event_types' => array( 'plugin.activated' ) ),
				'channels' => array( $channel['id'] ),
			)
		);

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->assertNull( activate_plugin( 'hello.php' ) );
		self::flush();

		$jobs = self::queued_jobs();
		$this->assertCount( 1, $jobs );
		$this->assertSame( $channel['id'], $jobs[0]['channel'] );

		do_action( Queue::HOOK, $jobs[0] );

		$this->assertFileExists( self::received_file() );
		$received = json_decode( (string) file_get_contents( self::received_file() ), true );
		$secret   = Plugin::instance()->alert_channels()->get( $channel['id'] )['config']['secret'];
		$body     = json_decode( $received['body'], true );

		$this->assertTrue( WebhookSigner::verify( $received['body'], $secret, (int) $received['timestamp'], $received['signature'] ) );
		$this->assertSame( 'plugin.activated', $body['event']['type'] );
		$this->assertSame( 'Plugin changes', $body['rule'] );
		$this->assertStringStartsWith( 'application/json', $received['type'] );
	}

	/**
	 * A flush holding a single event still alerts (do_action unwraps one-item arrays).
	 *
	 * @return void
	 */
	public function test_single_event_flush_alerts(): void {
		$channel = self::channel(
			array(
				'type'   => 'email',
				'name'   => 'Me',
				'config' => array( 'recipients' => 'me@example.test' ),
			)
		);
		self::rule(
			array(
				'name'     => 'New admin',
				'match'    => array(
					'event_types'  => array( 'user.created' ),
					'min_severity' => 3,
				),
				'channels' => array( $channel['id'] ),
			)
		);
		self::flush();

		$login = 'wpal_single_' . wp_generate_password( 6, false );

		wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password(),
				'user_email' => 'wpal-single-' . wp_generate_password( 6, false ) . '@example.test',
				'role'       => 'administrator',
			)
		);
		$this->assertCount( 1, Plugin::instance()->recorder()->pending() );
		self::flush();

		$labels = array_map( static fn( array $job ): string => $job['message']['object_label'], self::queued_jobs() );
		$this->assertContains( $login, $labels );
	}

	/**
	 * A failing endpoint is retried twice, then the failure is recorded.
	 *
	 * @return void
	 */
	public function test_failures_retry_then_record(): void {
		$channel = self::channel(
			array(
				'type'   => 'slack',
				'name'   => 'Broken',
				'config' => array( 'url' => home_url( '/?wpal_test_hook=fail' ) ),
			)
		);
		self::rule(
			array(
				'name'     => 'Settings',
				'match'    => array( 'event_types' => array( 'system.settings_changed' ) ),
				'channels' => array( $channel['id'] ),
			)
		);
		$start = self::max_event_id();

		Plugin::instance()->dispatcher()->check( Plugin::instance()->recorder()->enriched( new \WPActivityLedger\Domain\Event( 'system.settings_changed' ) ) );

		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			$jobs = array_values( array_filter( self::queued_jobs(), static fn( array $job ): bool => $job['attempt'] === $attempt ) );
			$this->assertCount( 1, $jobs, "attempt $attempt" );
			do_action( Queue::HOOK, $jobs[0] );
		}

		$this->assertSame( array( 1, 2, 3 ), array_column( self::queued_jobs(), 'attempt' ), 'No fourth attempt.' );

		// Deactivation cancels the plugin's queued jobs by group.
		as_unschedule_all_actions( '', array(), Queue::GROUP );
		$this->assertSame( array(), self::queued_jobs() );

		$failed = self::newer_than( self::request( 'GET', '/events', array( 'type' => 'system.alert_failed' ) )->get_data(), $start );
		$this->assertCount( 1, $failed );
		$this->assertSame( 'HTTP 500', $failed[0]['context']['error'] );
		$this->assertSame( 3, $failed[0]['context']['attempts'] );
	}

	/**
	 * Folded failed logins count towards a brute-force threshold.
	 *
	 * @return void
	 */
	public function test_folded_failed_logins_reach_threshold(): void {
		$channel = self::channel(
			array(
				'type'   => 'email',
				'name'   => 'Me',
				'config' => array( 'recipients' => 'me@example.test' ),
			)
		);
		self::rule(
			array(
				'name'      => 'Brute force',
				'match'     => array( 'event_types' => array( 'auth.login_failed' ) ),
				'threshold' => array(
					'count'    => 3,
					'minutes'  => 10,
					'group_by' => 'user',
				),
				'channels'  => array( $channel['id'] ),
			)
		);
		$user = 'wpal_nobody_' . wp_generate_password( 6, false );

		do_action( 'wp_login_failed', $user, new \WP_Error() );
		self::flush();
		$this->assertSame( array(), self::queued_jobs() );

		do_action( 'wp_login_failed', $user, new \WP_Error() );
		$this->assertSame( array(), self::queued_jobs() );

		do_action( 'wp_login_failed', $user, new \WP_Error() );
		$this->assertCount( 1, self::queued_jobs() );
	}

	/**
	 * Webhook channels can't reach internal addresses (SSRF): those are refused before any
	 * request is made, while public URLs are still sent, with WordPress's safety check on.
	 *
	 * @return void
	 */
	public function test_webhooks_refuse_internal_addresses(): void {
		$deliverer = Plugin::instance()->deliverer();
		$sample    = $deliverer->sample_message();
		$sent      = array();
		$catch     = static function ( $pre, array $args, string $url ) use ( &$sent ) {
			$sent[] = array(
				'url'  => $url,
				'safe' => ! empty( $args['reject_unsafe_urls'] ),
			);

			return $pre;
		};

		add_filter( 'pre_http_request', $catch, 10, 3 );

		try {
			foreach ( array( 'http://127.0.0.2/hook', 'http://169.254.169.254/latest/meta-data/', 'http://10.0.0.5:8080/hook', 'http://[::1]/hook' ) as $url ) {
				$error = $deliverer->send(
					array(
						'type'   => 'webhook',
						'config' => array(
							'url'    => $url,
							'secret' => 'secret',
						),
					),
					$sample
				);

				// WP_Http's own message when reject_unsafe_urls refuses a URL (no connection attempted).
				$this->assertSame( 'A valid URL was not provided.', $error, "$url must be refused by the safety check" );
			}

			// A public URL is attempted with the safety check on (answered here without network).
			$fake = static fn() => array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
			);
			add_filter( 'pre_http_request', $fake, 20 );

			$this->assertNull(
				$deliverer->send(
					array(
						'type'   => 'slack',
						'config' => array( 'url' => 'https://hooks.slack.com/services/T000/B000/XXXX' ),
					),
					$sample
				)
			);
		} finally {
			remove_filter( 'pre_http_request', $catch, 10 );
			remove_filter( 'pre_http_request', $fake ?? '__return_false', 20 );
		}

		$public = array_values( array_filter( $sent, static fn( array $call ): bool => str_starts_with( $call['url'], 'https://hooks.slack.com/' ) ) );
		$this->assertCount( 1, $public );
		$this->assertTrue( $public[0]['safe'] );
	}
}
