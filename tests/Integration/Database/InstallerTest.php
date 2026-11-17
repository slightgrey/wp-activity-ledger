<?php
/**
 * Tests for the Installer.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Database;

use WPActivityLedger\Database\Installer;
use WPActivityLedger\Database\Schema;
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
