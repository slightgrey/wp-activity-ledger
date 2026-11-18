<?php
/**
 * Creates and upgrades the plugin's tables.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Database;

/**
 * Runs dbDelta() for the current site and records the schema version.
 */
final class Installer {

	/**
	 * Option holding the last install error ('' or missing when fine).
	 */
	public const ERROR_OPTION = 'wpal_db_error';

	/**
	 * Creates or updates the tables for the current site.
	 *
	 * @return array<string, string> dbDelta's list of executed changes (empty when up to date).
	 */
	public static function install(): array {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$changes = dbDelta( Schema::sql( $wpdb->prefix, $wpdb->get_charset_collate() ) );

		update_option( Schema::VERSION_OPTION, Schema::VERSION, true );

		return $changes;
	}

	/**
	 * Installs or upgrades the tables when the stored schema version is outdated.
	 *
	 * On failure the database error is stored in ERROR_OPTION, which shows an admin notice.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( Schema::VERSION === get_option( Schema::VERSION_OPTION ) ) {
			return;
		}

		self::install();

		if ( self::tables_exist() ) {
			delete_option( self::ERROR_OPTION );
			return;
		}

		global $wpdb;

		update_option( self::ERROR_OPTION, '' !== $wpdb->last_error ? $wpdb->last_error : 'Tables are missing after install.', false );
		delete_option( Schema::VERSION_OPTION );
	}

	/**
	 * Prints an admin notice when the last install or upgrade failed.
	 *
	 * @return void
	 */
	public static function error_notice(): void {
		// A failed install deletes the (autoloaded) version, so a current version means no
		// error: this skips a database query on every admin page.
		if ( Schema::VERSION === get_option( Schema::VERSION_OPTION ) ) {
			return;
		}

		$error = get_option( self::ERROR_OPTION );

		if ( ! is_string( $error ) || '' === $error || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <code>%3$s</code></p></div>',
			esc_html__( 'WP Activity Ledger:', 'wp-activity-ledger' ),
			esc_html__( 'The activity log tables could not be created, so nothing is being recorded. Database error:', 'wp-activity-ledger' ),
			esc_html( $error )
		);
	}

	/**
	 * Tells whether both tables exist on the current site.
	 *
	 * @return bool True when installed.
	 */
	public static function tables_exist(): bool {
		global $wpdb;

		foreach ( array( Schema::events_table(), Schema::changes_table() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema check, nothing to cache.
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				return false;
			}
		}

		return true;
	}
}
