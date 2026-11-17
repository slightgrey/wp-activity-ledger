<?php
/**
 * Tests for the UserProfileSensor.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Sensors;

use WPActivityLedger\Plugin;
use WPActivityLedger\Sensors\UserProfileSensor;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * @covers \WPActivityLedger\Sensors\UserProfileSensor
 */
final class UserProfileSensorTest extends IntegrationTestCase {

	/**
	 * Records what the profile sensor collected and flushes the recorder.
	 *
	 * @return void
	 */
	private static function settle(): void {
		$sensor = Plugin::instance()->core_sensors()['profile'];
		self::assertInstanceOf( UserProfileSensor::class, $sensor );
		$sensor->record_pending();
		Plugin::instance()->recorder()->flush();
	}

	/**
	 * Creates a subscriber (and settles, so creation isn't counted as an update).
	 *
	 * @return int User ID.
	 */
	private static function create_user(): int {
		$id = wp_insert_user(
			array(
				'user_login' => 'wpal_prof_' . strtolower( wp_generate_password( 6, false ) ),
				'user_pass'  => 'initial-password',
				'user_email' => 'wpal-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test',
				'first_name' => 'Ann',
				'role'       => 'subscriber',
			)
		);

		self::settle();

		return is_int( $id ) ? $id : 0;
	}

	/**
	 * Returns user.updated events with their changes.
	 *
	 * @param int $user_id User.
	 *
	 * @return array<int, array<string, mixed>> Events.
	 */
	private static function updates( int $user_id ): array {
		$items = Plugin::instance()->repository()->query(
			array(
				'type'        => 'user.updated',
				'object_type' => 'user',
				'object_id'   => (string) $user_id,
			)
		)['items'];

		return array_map( static fn( array $i ): array => (array) Plugin::instance()->repository()->find( $i['id'] ), $items );
	}

	/**
	 * Editing email and first name gives one event with two changes.
	 *
	 * @return void
	 */
	public function test_email_and_name_edit(): void {
		$this->as_user( 'administrator' );
		$user_id = self::create_user();
		$this->assertSame( array(), self::updates( $user_id ), 'Creation is not an update.' );

		wp_update_user(
			array(
				'ID'         => $user_id,
				'user_email' => 'changed-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test',
				'first_name' => 'Beth',
			)
		);
		self::settle();

		$updates = self::updates( $user_id );
		$this->assertCount( 1, $updates );

		$fields = array_column( $updates[0]['changes'], null, 'field' );
		$this->assertEqualsCanonicalizing( array( 'user_email', 'meta:first_name' ), array_keys( $fields ) );
		$this->assertSame( 'Ann', $fields['meta:first_name']['old'] );
		$this->assertSame( 'Beth', $fields['meta:first_name']['new'] );
	}

	/**
	 * A password change is recorded without any value.
	 *
	 * @return void
	 */
	public function test_password_change_is_redacted(): void {
		$this->as_user( 'administrator' );
		$user_id = self::create_user();

		wp_update_user(
			array(
				'ID'        => $user_id,
				'user_pass' => 'a-brand-new-password',
			)
		);
		self::settle();

		$updates = self::updates( $user_id );
		$this->assertCount( 1, $updates );
		$this->assertSame(
			array(
				array(
					'field'     => 'password',
					'old'       => '[redacted]',
					'new'       => '[redacted]',
					'truncated' => false,
				),
			),
			$updates[0]['changes']
		);
	}

	/**
	 * Custom meta is tracked, ignored keys are skipped, secrets are redacted, and
	 * separate meta writes in one request merge into the same event.
	 *
	 * @return void
	 */
	public function test_custom_meta_ignore_and_redaction(): void {
		$this->as_user( 'administrator' );
		$user_id = self::create_user();

		update_user_meta( $user_id, 'favorite_color', 'blue' );
		update_user_meta( $user_id, 'favorite_color', 'green' );
		update_user_meta( $user_id, 'session_tokens', array( 'x' => array( 'expiration' => 1 ) ) );
		update_user_meta( $user_id, 'closedpostboxes_dashboard', array( 'a' ) );
		update_user_meta( $user_id, 'acme_api_token', 'sk_live_123' );
		delete_user_meta( $user_id, 'first_name' );
		self::settle();

		$updates = self::updates( $user_id );
		$this->assertCount( 1, $updates );

		$fields = array_column( $updates[0]['changes'], null, 'field' );
		$this->assertEqualsCanonicalizing( array( 'meta:favorite_color', 'meta:acme_api_token', 'meta:first_name' ), array_keys( $fields ) );
		$this->assertNull( $fields['meta:favorite_color']['old'], 'First old value is kept.' );
		$this->assertSame( 'green', $fields['meta:favorite_color']['new'], 'Last new value is kept.' );
		$this->assertSame( '[redacted]', $fields['meta:acme_api_token']['new'] );
		$this->assertSame( 'Ann', $fields['meta:first_name']['old'] );
		$this->assertNull( $fields['meta:first_name']['new'] );
	}

	/**
	 * Saving without changes records nothing.
	 *
	 * @return void
	 */
	public function test_no_change_no_event(): void {
		$this->as_user( 'administrator' );
		$user_id = self::create_user();

		wp_update_user(
			array(
				'ID'         => $user_id,
				'first_name' => 'Ann',
			)
		);
		self::settle();

		$this->assertSame( array(), self::updates( $user_id ) );
	}
}
