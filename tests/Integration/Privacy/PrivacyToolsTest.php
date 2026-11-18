<?php
/**
 * Tests for the personal data exporter and eraser.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Privacy;

use WPActivityLedger\Domain\Event;
use WPActivityLedger\Plugin;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * @covers \WPActivityLedger\Privacy\PrivacyTools
 */
final class PrivacyToolsTest extends IntegrationTestCase {

	/**
	 * Creates a user with 3 recorded events and returns [ID, email].
	 *
	 * @return array{0: int, 1: string} User ID and email.
	 */
	private static function person(): array {
		$email = 'wpal-gdpr-' . wp_generate_password( 6, false ) . '@example.test';
		$login = 'wpal_gdpr_' . wp_generate_password( 6, false );
		$id    = (int) wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password(),
				'user_email' => $email,
				'role'       => 'subscriber',
			)
		);

		$recorder = Plugin::instance()->recorder();

		for ( $i = 1; $i <= 3; $i++ ) {
			$recorder->record( new Event( 'post.updated', object_type: 'post', object_id: (string) $i, object_label: "Post $i", user_id: $id, user_login: $login, user_roles: array( 'subscriber' ), ip: '203.0.113.9', user_agent: 'TestBrowser/1.0' ) );
		}

		$recorder->flush();

		return array( $id, $email );
	}

	/**
	 * The registered exporter returns the person's events in an "Activity Ledger" group.
	 *
	 * @return void
	 */
	public function test_export(): void {
		[ , $email ] = self::person();
		$exporters   = apply_filters( 'wp_privacy_personal_data_exporters', array() );

		$this->assertArrayHasKey( 'wp-activity-ledger', $exporters );

		$page = call_user_func( $exporters['wp-activity-ledger']['callback'], $email, 1 );
		$data = array_column( $page['data'][0]['data'], 'value', 'name' );

		$this->assertTrue( $page['done'] );
		$this->assertCount( 3, $page['data'] );
		$this->assertSame( 'Activity Ledger', $page['data'][0]['group_label'] );
		$this->assertSame( '203.0.113.9', $data['IP address'] );
		$this->assertSame( 'Post 1', $data['Object'] );

		$nobody = call_user_func( $exporters['wp-activity-ledger']['callback'], 'nobody@example.test', 1 );
		$this->assertSame( array(), $nobody['data'] );
	}

	/**
	 * The eraser anonymises login, IP and browser, keeps the rows and says so.
	 *
	 * @return void
	 */
	public function test_erase(): void {
		global $wpdb;

		[ $id, $email ] = self::person();
		$erasers        = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		$result         = call_user_func( $erasers['wp-activity-ledger']['callback'], $email, 1 );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$this->assertStringContainsString( 'keeps 3 log entries', $result['messages'][0] );

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_login, ip, user_agent FROM {$wpdb->prefix}wpal_events WHERE user_id = %d", $id ), ARRAY_A );

		$this->assertCount( 3, $rows );
		$this->assertSame(
			array(
				'user_login' => 'deleted-user-#' . $id,
				'ip'         => '',
				'user_agent' => '',
			),
			$rows[0]
		);
	}
}
