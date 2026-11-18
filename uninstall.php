<?php
/**
 * Runs when WP Activity Ledger is deleted from the Plugins screen.
 *
 * Always removes scheduled jobs and caches. The audit data (log tables, settings, alerts,
 * capabilities) is only deleted on sites where "Delete data when uninstalled" is on; the
 * network-wide data (network settings, users' UI preferences) when it is on for the main
 * site. On multisite every site is handled.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own tables and options.

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Cron hooks the plugin schedules.
 */
const WPAL_UNINSTALL_HOOKS = array( 'wpal_daily_maintenance', 'wpal_maintenance_continue', 'wpal_deliver_alert' );

/**
 * Capabilities the plugin adds to roles.
 */
const WPAL_UNINSTALL_CAPS = array( 'wpal_view_logs', 'wpal_export_logs', 'wpal_manage_settings', 'wpal_delete_logs', 'wpal_manage_sessions' );

/**
 * Tells whether the current site asked for its data to be deleted.
 *
 * @return bool True when "Delete data when uninstalled" is on.
 */
function wpal_uninstall_wants_delete(): bool {
	$settings = get_option( 'wpal_settings', array() );

	return is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );
}

/**
 * Removes the current site's scheduled jobs and transients.
 *
 * @return void
 */
function wpal_uninstall_clear_jobs(): void {
	global $wpdb;

	foreach ( WPAL_UNINSTALL_HOOKS as $hook ) {
		wp_unschedule_hook( $hook );
	}

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'wpal' );
	}

	$names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( '_transient_wpal_' ) . '%'
		)
	);

	foreach ( (array) $names as $name ) {
		delete_transient( substr( (string) $name, strlen( '_transient_' ) ) );
	}
}

/**
 * Deletes the current site's log tables, options and capabilities.
 *
 * @return void
 */
function wpal_uninstall_delete_site_data(): void {
	global $wpdb;

	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpal_event_changes" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpal_events" );

	$options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'wpal_' ) . '%' ) );

	foreach ( (array) $options as $option ) {
		delete_option( (string) $option );
	}

	foreach ( wp_roles()->role_objects as $role ) {
		foreach ( WPAL_UNINSTALL_CAPS as $cap ) {
			$role->remove_cap( $cap );
		}
	}
}

/**
 * Handles one site.
 *
 * @return bool Whether the site's data was deleted.
 */
function wpal_uninstall_site(): bool {
	$delete = wpal_uninstall_wants_delete();

	wpal_uninstall_clear_jobs();

	if ( $delete ) {
		wpal_uninstall_delete_site_data();
	}

	return $delete;
}

/**
 * Deletes the network-wide data: network settings and users' UI preferences.
 *
 * @return void
 */
function wpal_uninstall_delete_global_data(): void {
	delete_metadata( 'user', 0, 'wpal_ui_prefs', '', true );

	if ( is_multisite() ) {
		delete_site_option( 'wpal_network_settings' );
	}
}

if ( ! is_multisite() ) {
	if ( wpal_uninstall_site() ) {
		wpal_uninstall_delete_global_data();
	}

	return;
}

$wpal_main_deletes = false;
$wpal_offset       = 0;

do {
	$wpal_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 100,
			'offset' => $wpal_offset,
		)
	);

	foreach ( $wpal_sites as $wpal_site_id ) {
		switch_to_blog( (int) $wpal_site_id );
		$wpal_deleted = wpal_uninstall_site();

		if ( is_main_site() ) {
			$wpal_main_deletes = $wpal_deleted;
		}

		restore_current_blog();
	}

	$wpal_offset += 100;
	$wpal_more    = 100 === count( $wpal_sites );
} while ( $wpal_more );

if ( $wpal_main_deletes ) {
	wpal_uninstall_delete_global_data();
}
