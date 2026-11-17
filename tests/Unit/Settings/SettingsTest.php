<?php
/**
 * Tests for Settings and SettingsValidator.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use WPActivityLedger\Settings\Settings;
use WPActivityLedger\Settings\SettingsValidator;

/**
 * @covers \WPActivityLedger\Settings\Settings
 * @covers \WPActivityLedger\Settings\SettingsValidator
 */
final class SettingsTest extends TestCase {

	/**
	 * Stored values are merged over the defaults.
	 *
	 * @return void
	 */
	public function test_defaults_merge(): void {
		$settings = new Settings( array( 'retention_days' => 30 ) );

		$this->assertSame( 30, $settings->get( 'retention_days' ) );
		$this->assertSame( array( '*' ), $settings->get( 'logged_roles' ) );
		$this->assertTrue( $settings->get( 'log_anonymous' ) );
		$this->assertNull( $settings->get( 'nope' ) );
		$this->assertSame( array_keys( Settings::defaults() ), array_keys( $settings->all() ) );
	}

	/**
	 * Every default value passes validation unchanged.
	 *
	 * @return void
	 */
	public function test_defaults_are_valid(): void {
		[ $settings, $errors ] = SettingsValidator::validate( Settings::defaults(), array() );

		$this->assertSame( array(), $errors );
		$this->assertSame( Settings::defaults(), $settings );
	}

	/**
	 * Values are coerced to their types; numbers are clamped.
	 *
	 * @return void
	 */
	public function test_coercion_and_clamping(): void {
		[ $settings, $errors ] = SettingsValidator::validate(
			array(
				'retention_days'       => '99999',
				'max_value_size'       => 10,
				'anonymize_ip'         => 'yes',
				'log_anonymous'        => '0',
				'excluded_users'       => array( '3', 'x', -1, 3, 9 ),
				'logged_roles'         => "administrator\neditor\n<bad>\n*",
				'view_roles'           => array( 'editor', '*' ),
				'redact_patterns'      => array( '*pass*', 'has space', '*pass*' ),
				'disabled_event_types' => array( 'post.updated', 'nodot' ),
				'trusted_proxy_header' => 'HTTP_CF_CONNECTING_IP',
				'unknown_key'          => 'dropped',
			),
			Settings::defaults()
		);

		$this->assertSame( array(), $errors );
		$this->assertSame( 3650, $settings['retention_days'] );
		$this->assertSame( 1024, $settings['max_value_size'] );
		$this->assertTrue( $settings['anonymize_ip'] );
		$this->assertFalse( $settings['log_anonymous'] );
		$this->assertSame( array( 3, 9 ), $settings['excluded_users'] );
		$this->assertSame( array( 'administrator', 'editor', '*' ), $settings['logged_roles'] );
		$this->assertSame( array( 'editor' ), $settings['view_roles'] );
		$this->assertSame( array( '*pass*' ), $settings['redact_patterns'] );
		$this->assertSame( array( 'post.updated' ), $settings['disabled_event_types'] );
		$this->assertSame( 'HTTP_CF_CONNECTING_IP', $settings['trusted_proxy_header'] );
		$this->assertArrayNotHasKey( 'unknown_key', $settings );
	}

	/**
	 * Wrong types are reported per field.
	 *
	 * @return void
	 */
	public function test_invalid_values_are_errors(): void {
		[ , $errors ] = SettingsValidator::validate(
			array(
				'retention_days'       => 'forever',
				'anonymize_ip'         => 'maybe',
				'logged_roles'         => 42,
				'trusted_proxy_header' => 'HTTP_X_EVIL',
			),
			Settings::defaults()
		);

		$this->assertSame( array( 'retention_days', 'anonymize_ip', 'logged_roles', 'trusted_proxy_header' ), array_keys( $errors ) );
	}

	/**
	 * update() saves nothing when a field is invalid.
	 *
	 * @return void
	 */
	public function test_update_rejects_all_on_error(): void {
		$settings = new Settings( array() );
		$errors   = $settings->update(
			array(
				'retention_days' => 'x',
				'anonymize_ip'   => true,
			)
		);

		$this->assertArrayHasKey( 'retention_days', $errors );
		$this->assertFalse( $settings->get( 'anonymize_ip' ) );
	}
}
