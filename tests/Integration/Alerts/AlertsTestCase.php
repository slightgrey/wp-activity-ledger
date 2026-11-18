<?php
/**
 * Shared helpers for alert tests.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Alerts;

use WPActivityLedger\Alerts\Queue;
use WPActivityLedger\Plugin;
use WPActivityLedger\Tests\Integration\Rest\RestTestCase;

/**
 * Starts each test with no channels or rules, as an administrator.
 */
abstract class AlertsTestCase extends RestTestCase {

	/**
	 * Clears the alert options (rolled back afterwards) and the cached rules.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), Queue::GROUP ); // Rolled back with the test.
		}

		delete_option( 'wpal_alert_channels' );
		delete_option( 'wpal_alert_rules' );
		Plugin::instance()->dispatcher()->forget_rules();
		$this->as_user( 'administrator' );
	}

	/**
	 * Forgets the rules cached during the test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		parent::tearDown();
		Plugin::instance()->dispatcher()->forget_rules();
	}

	/**
	 * Creates a channel through the API and returns it (masked).
	 *
	 * @param array<string, mixed> $fields Fields.
	 *
	 * @return array<string, mixed> Channel.
	 */
	protected static function channel( array $fields ): array {
		$response = self::request( 'POST', '/alerts/channels', $fields );
		self::assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	/**
	 * Creates a rule through the API and returns it.
	 *
	 * @param array<string, mixed> $fields Fields.
	 *
	 * @return array<string, mixed> Rule.
	 */
	protected static function rule( array $fields ): array {
		$response = self::request( 'POST', '/alerts/rules', $fields );
		self::assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	/**
	 * Returns the queued delivery jobs, oldest first.
	 *
	 * @return array<int, array<string, mixed>> Jobs.
	 */
	protected static function queued_jobs(): array {
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => Queue::HOOK,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'group'    => Queue::GROUP,
				'per_page' => 50,
				'orderby'  => 'date',
				'order'    => 'ASC',
			)
		);

		return array_values( array_map( static fn( \ActionScheduler_Action $action ): array => $action->get_args()[0], $actions ) );
	}
}
