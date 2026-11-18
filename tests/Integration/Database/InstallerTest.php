<?php
/**
 * Tests for the Installer.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Database;

use WPActivityLedger\Alerts\ChannelStore;
use WPActivityLedger\Alerts\RuleStore;
use WPActivityLedger\Database\Installer;
use WPActivityLedger\Database\Schema;
use WPActivityLedger\Plugin;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * Installing runs DDL, which commits the open transaction; the tables are meant to stay.
 *
 * @covers \WPActivityLedger\Database\Installer
 * @covers \WPActivityLedger\Database\Schema
 */
final class InstallerTest extends IntegrationTestCase {

	/**
	 * Install creates both tables with every column and index; a second run changes nothing.
	 *
	 * @return void
	 */
	public function test_install_is_complete_and_idempotent(): void {
		global $wpdb;

		Installer::install();
		$second_run = Installer::install();

		$this->assertTrue( Installer::tables_exist() );
		$this->assertSame( array(), $second_run, 'Second dbDelta run should change nothing: ' . implode( '; ', $second_run ) );
		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );

		// phpcs:ignore WordPress.DB -- Test inspects the schema.
		$events = (string) $wpdb->get_var( 'SHOW CREATE TABLE ' . Schema::events_table(), 1 );

		foreach ( array( 'created_at', 'user_id', 'user_login', 'user_roles', 'ip', 'user_agent', 'category', 'event_type', 'severity', 'object_type', 'object_id', 'object_label', 'message', 'context', 'session_hash' ) as $column ) {
			$this->assertStringContainsString( "`$column`", $events );
		}

		foreach ( array( 'KEY `created_at`', 'KEY `user_created` (`user_id`,`created_at`)', 'KEY `type_created`', 'KEY `category_created`', 'KEY `object` (`object_type`,`object_id`)', 'KEY `ip`' ) as $index ) {
			$this->assertStringContainsString( $index, $events );
		}

		// phpcs:ignore WordPress.DB -- Test inspects the schema.
		$changes = (string) $wpdb->get_var( 'SHOW CREATE TABLE ' . Schema::changes_table(), 1 );
		$this->assertStringContainsString( 'KEY `event_id`', $changes );
		$this->assertStringContainsString( '`truncated`', $changes );
	}

	/**
	 * An outdated version triggers an upgrade that restores the version and clears errors.
	 *
	 * @return void
	 */
	public function test_maybe_upgrade_updates_outdated_version(): void {
		update_option( Schema::VERSION_OPTION, '0' );
		update_option( Installer::ERROR_OPTION, 'old error' );

		Installer::maybe_upgrade();

		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
		$this->assertFalse( get_option( Installer::ERROR_OPTION ) );
	}

	/**
	 * Upgrading autoloads an existing, non-autoloaded alert rules option (version 2); saving
	 * rules keeps them autoloaded while channels (webhook secrets) stay non-autoloaded.
	 *
	 * @return void
	 */
	public function test_alert_rules_are_autoloaded(): void {
		// Upgrading may run DDL, which commits the test transaction: restore the options after.
		$saved = array(
			RuleStore::OPTION    => get_option( RuleStore::OPTION ),
			ChannelStore::OPTION => get_option( ChannelStore::OPTION ),
		);

		try {
			$this->check_alert_rules_autoload();
		} finally {
			foreach ( $saved as $option => $value ) {
				delete_option( $option );

				if ( false !== $value ) {
					add_option( $option, $value, '', RuleStore::OPTION === $option );
				}
			}

			update_option( Schema::VERSION_OPTION, Schema::VERSION );
		}
	}

	/**
	 * Body of test_alert_rules_are_autoloaded().
	 *
	 * @return void
	 */
	private function check_alert_rules_autoload(): void {
		delete_option( RuleStore::OPTION );
		add_option( RuleStore::OPTION, array(), '', false );
		$this->assertFalse( self::autoloaded( RuleStore::OPTION ) );

		update_option( Schema::VERSION_OPTION, '1' );
		Installer::maybe_upgrade();

		$this->assertTrue( self::autoloaded( RuleStore::OPTION ) );
		$this->assertArrayHasKey( RuleStore::OPTION, wp_load_alloptions() );
		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );

		// Repeating the step is harmless, and a missing option is not created.
		delete_option( RuleStore::OPTION );
		Installer::upgrade_data();
		$this->assertFalse( get_option( RuleStore::OPTION ) );

		$plugin = Plugin::instance();
		$plugin->alert_channels()->create(
			array(
				'type'   => 'webhook',
				'name'   => 'Autoload check',
				'config' => array( 'url' => 'https://example.test/hook' ),
			)
		);
		$plugin->alert_rules()->create(
			array(
				'name'  => 'Autoload check',
				'match' => array( 'min_severity' => 3 ),
			)
		);

		$this->assertTrue( self::autoloaded( RuleStore::OPTION ) );
		$this->assertFalse( self::autoloaded( ChannelStore::OPTION ) );
	}

	/**
	 * Tells whether an option row is marked for autoloading.
	 *
	 * @param string $option Option name.
	 *
	 * @return bool True when autoloaded.
	 */
	private static function autoloaded( string $option ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB -- Test reads the autoload flag directly.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		$yes   = function_exists( 'wp_autoload_values_to_autoload' ) ? wp_autoload_values_to_autoload() : array( 'yes' );

		return in_array( $value, $yes, true );
	}

	/**
	 * A current version is left alone (no install runs).
	 *
	 * @return void
	 */
	public function test_maybe_upgrade_skips_current_version(): void {
		update_option( Schema::VERSION_OPTION, Schema::VERSION );
		update_option( Installer::ERROR_OPTION, 'untouched' );

		Installer::maybe_upgrade();

		$this->assertSame( 'untouched', get_option( Installer::ERROR_OPTION ) );
		delete_option( Installer::ERROR_OPTION );
	}
}
