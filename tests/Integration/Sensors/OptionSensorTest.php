<?php
/**
 * Tests for the OptionSensor.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Sensors;

use WPActivityLedger\Plugin;
use WPActivityLedger\Sensors\OptionSensor;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

// phpcs:disable WordPress.Security.NonceVerification.Missing -- Tests fill $_POST themselves.

/**
 * @covers \WPActivityLedger\Sensors\OptionSensor
 */
final class OptionSensorTest extends IntegrationTestCase {

	/**
	 * Settles changes left pending by earlier tests in this process.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		self::settle( PHP_INT_MAX );
	}

	/**
	 * Clears $_POST.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * Records what the option sensor collected and returns the new option.updated events.
	 *
	 * @param int $after ID boundary.
	 *
	 * @return array<int, array<string, mixed>> Events with changes, newest first.
	 */
	private static function settle( int $after ): array {
		$sensor = Plugin::instance()->core_sensors()['options'];
		self::assertInstanceOf( OptionSensor::class, $sensor );
		$sensor->record_pending();
		Plugin::instance()->recorder()->flush();

		$items = self::newer_than( Plugin::instance()->repository()->query( array( 'type' => 'option.updated' ) )['items'], $after );

		return array_map( static fn( array $i ): array => (array) Plugin::instance()->repository()->find( $i['id'] ), $items );
	}

	/**
	 * A General settings save with two changes is one event labelled "general".
	 *
	 * @return void
	 */
	public function test_settings_page_save(): void {
		$this->as_user( 'administrator' );
		$start               = self::max_event_id();
		$_POST['option_page'] = 'general';

		update_option( 'blogdescription', 'Tagline ' . wp_generate_password( 4, false ) );
		update_option( 'timezone_string', 'UTC' === get_option( 'timezone_string' ) ? 'Europe/London' : 'UTC' );
		update_option( 'cron', get_option( 'cron' ) );
		set_transient( 'wpal_test_transient', 'x', 60 );
		update_option( 'wpal_settings', get_option( 'wpal_settings' ) );

		$events = self::settle( $start );

		$this->assertCount( 1, $events );
		$this->assertSame( 'Changed 2 setting(s) on general', $events[0]['message'] );
		$this->assertEqualsCanonicalizing( array( 'blogdescription', 'timezone_string' ), array_column( $events[0]['changes'], 'field' ) );
	}

	/**
	 * Array options are diffed by key; added and deleted options are recorded; secrets are redacted.
	 *
	 * @return void
	 */
	public function test_arrays_add_delete_and_redaction(): void {
		$this->as_user( 'administrator' );
		$start = self::max_event_id();

		add_option(
			'acme_settings',
			array(
				'mode'    => 'test',
				'api_key' => 'sk_1',
			)
		);
		update_option(
			'acme_settings',
			array(
				'mode'    => 'live',
				'api_key' => 'sk_2',
			)
		);
		add_option( 'acme_flag', 'on' );
		delete_option( 'acme_flag' );

		$events = self::settle( $start );
		$fields = array_column( $events[0]['changes'], null, 'field' );

		$this->assertEqualsCanonicalizing( array( 'acme_settings:mode', 'acme_settings:api_key' ), array_keys( $fields ), 'Added-then-deleted acme_flag cancels out.' );
		$this->assertNull( $fields['acme_settings:mode']['old'] );
		$this->assertSame( 'live', $fields['acme_settings:mode']['new'] );
		$this->assertSame( '[redacted]', $fields['acme_settings:api_key']['new'] );
	}

	/**
	 * Claimed options (widgets) are left to their own sensor.
	 *
	 * @return void
	 */
	public function test_claimed_options_are_skipped(): void {
		$this->as_user( 'administrator' );
		$start = self::max_event_id();

		update_option( 'widget_wpal_test', array( 2 => array( 'title' => 'x' ) ) );

		$this->assertSame( array(), self::settle( $start ) );
	}

	/**
	 * Option writes during WP-Cron are background state and skipped unless turned on.
	 *
	 * @return void
	 */
	public function test_cron_writes_are_skipped(): void {
		$start = self::max_event_id();
		add_filter( 'wp_doing_cron', '__return_true' );

		update_option( 'acme_last_result', array( 'time' => wp_rand() ) );
		$this->assertSame( array(), self::settle( $start ) );

		add_filter( 'wpal_record_cron_option_changes', '__return_true' );
		update_option( 'acme_last_result', array( 'time' => wp_rand() ) );
		$events = self::settle( $start );

		remove_filter( 'wpal_record_cron_option_changes', '__return_true' );
		remove_filter( 'wp_doing_cron', '__return_true' );

		$this->assertCount( 1, $events );
	}
}
