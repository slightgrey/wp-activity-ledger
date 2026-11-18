<?php
/**
 * Tests for alert channel and rule storage through the REST API.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Alerts;

use WPActivityLedger\Alerts\AlertTemplates;
use WPActivityLedger\Plugin;

/**
 * @covers \WPActivityLedger\Rest\AlertsController
 * @covers \WPActivityLedger\Alerts\ChannelStore
 * @covers \WPActivityLedger\Alerts\RuleStore
 * @covers \WPActivityLedger\Alerts\ListStore
 * @covers \WPActivityLedger\Alerts\AlertTemplates
 */
final class AlertsControllerTest extends AlertsTestCase {

	/**
	 * Secrets are masked on read, a masked value sent back keeps the original, and the
	 * change is recorded.
	 *
	 * @return void
	 */
	public function test_webhook_secret_masking(): void {
		$start   = self::max_event_id();
		$channel = self::channel(
			array(
				'type'   => 'webhook',
				'name'   => 'Ops hook',
				'config' => array(
					'url'    => 'https://hooks.example.test/abc/xyz123',
					'secret' => 'topsecret9876',
				),
			)
		);

		$this->assertSame( 'https://hooks.example.test/••••••••z123', $channel['config']['url'] );
		$this->assertSame( '••••••••9876', $channel['config']['secret'] );
		$this->assertSame( $channel, self::request( 'GET', '/alerts/channels/' . $channel['id'] )->get_data() );

		$updated = self::request(
			'PUT',
			'/alerts/channels/' . $channel['id'],
			array(
				'name'   => 'Ops hook 2',
				'config' => $channel['config'],
			)
		);
		$this->assertSame( 200, $updated->get_status() );

		$stored = Plugin::instance()->alert_channels()->get( $channel['id'] );
		$this->assertSame( 'topsecret9876', $stored['config']['secret'] );
		$this->assertSame( 'https://hooks.example.test/abc/xyz123', $stored['config']['url'] );
		$this->assertSame( 'Ops hook 2', $stored['name'] );

		self::flush();
		$events = self::newer_than( self::request( 'GET', '/events', array( 'type' => 'system.alert_channels_changed' ) )->get_data(), $start );
		$this->assertNotSame( array(), $events );
	}

	/**
	 * Invalid channels and rules give 400 with field errors; deleting a channel removes it
	 * from rules; editors can't use the API.
	 *
	 * @return void
	 */
	public function test_validation_and_cleanup(): void {
		$bad = self::request(
			'POST',
			'/alerts/channels',
			array(
				'type'   => 'email',
				'name'   => '',
				'config' => array( 'recipients' => 'not-an-email' ),
			)
		);
		$this->assertSame( 400, $bad->get_status() );
		$this->assertEqualsCanonicalizing( array( 'name', 'config.recipients' ), array_keys( $bad->get_data()['data']['errors'] ) );

		$this->assertSame( 400, self::request( 'POST', '/alerts/rules', array( 'name' => 'Everything' ) )->get_status() );

		$channel = self::channel(
			array(
				'type'   => 'email',
				'name'   => 'Admins',
				'config' => array( 'recipients' => 'a@example.test, b@example.test' ),
			)
		);
		$rule    = self::rule(
			array(
				'name'     => 'Plugins',
				'match'    => array( 'event_types' => array( 'plugin.activated', 'bogus key!' ) ),
				'channels' => array( $channel['id'], 'unknown-id' ),
			)
		);

		$this->assertSame( array( 'plugin.activated' ), $rule['match']['event_types'] );
		$this->assertSame( array( $channel['id'] ), $rule['channels'] );
		$this->assertSame( array( 'a@example.test', 'b@example.test' ), $channel['config']['recipients'] );

		self::request( 'DELETE', '/alerts/channels/' . $channel['id'] );
		$this->assertSame( array(), Plugin::instance()->alert_rules()->get( $rule['id'] )['channels'] );
		$this->assertSame( 404, self::request( 'GET', '/alerts/channels/' . $channel['id'] )->get_status() );

		$this->as_user( 'editor' );
		$this->assertSame( 403, self::request( 'GET', '/alerts/rules' )->get_status() );
	}

	/**
	 * The seven templates are added once, disabled, and not again on reactivation.
	 *
	 * @return void
	 */
	public function test_templates_install_once(): void {
		delete_option( AlertTemplates::INSTALLED_OPTION );
		$rules = Plugin::instance()->alert_rules();

		$this->assertSame( 7, AlertTemplates::install( $rules ) );
		$this->assertSame( 0, AlertTemplates::install( $rules ) );

		$all = $rules->all();
		$this->assertCount( 7, $all );
		$this->assertSame( array( false ), array_values( array_unique( array_column( $all, 'enabled' ) ) ) );

		// A template the admin deleted stays deleted.
		$rules->delete( $all[0]['id'] );
		$this->assertSame( 0, AlertTemplates::install( $rules ) );
		$this->assertCount( 6, $rules->all() );
	}

	/**
	 * "Send test" emails the channel's recipients right away.
	 *
	 * @return void
	 */
	public function test_send_test_email(): void {
		$sent    = array();
		$capture = static function ( $short_circuit, $atts ) use ( &$sent ) {
			$sent[] = $atts;

			return true;
		};
		add_filter( 'pre_wp_mail', $capture, 5, 2 );

		$channel = self::channel(
			array(
				'type'   => 'email',
				'name'   => 'Me',
				'config' => array( 'recipients' => array( 'me@example.test' ) ),
			)
		);
		$result  = self::request( 'POST', '/alerts/channels/' . $channel['id'] . '/test' )->get_data();

		remove_filter( 'pre_wp_mail', $capture, 5 );

		$this->assertSame(
			array(
				'ok'    => true,
				'error' => null,
			),
			$result
		);
		$this->assertCount( 1, $sent );
		$this->assertSame( array( 'me@example.test' ), $sent[0]['to'] );
		$this->assertStringContainsString( 'Test alert', $sent[0]['subject'] );
	}
}
