<?php
/**
 * Plugin Name:       WP Activity Ledger
 * Description:       Records every meaningful action on your site (who, what, when, from where) with field-level before/after diffs, alerts, export and a modern admin.
 * Version:           0.4.5
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Web of D
 * Author URI:        https://vincelayte.me/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-activity-ledger
 * Domain Path:       /languages
 * Network:           false
 * Update URI:        https://github.com/slightgrey/wp-activity-ledger
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Another copy of this plugin (e.g. a second folder) is already loaded: let it run alone.
if ( defined( 'WPAL_VERSION' ) ) {
	return;
}

define( 'WPAL_VERSION', '0.4.5' );
define( 'WPAL_FILE', __FILE__ );
define( 'WPAL_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPAL_URL', plugin_dir_url( __FILE__ ) );

// Declared conditionally: PHP binds unconditional top-level functions at compile time,
// before the duplicate-copy return above could skip them.
if ( ! function_exists( 'wpal_missing_vendor_notice' ) ) {
	/**
	 * Prints an admin notice telling the site owner to install Composer dependencies.
	 *
	 * @return void
	 */
	function wpal_missing_vendor_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error wpal-notice"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'WP Activity Ledger:', 'wp-activity-ledger' ),
			wp_kses(
				__( 'Run <code>composer install</code> in the plugin directory, or install the release zip, before use.', 'wp-activity-ledger' ),
				array( 'code' => array() )
			)
		);
	}
}

if ( ! file_exists( WPAL_DIR . 'vendor/autoload.php' ) ) {
	add_action( 'admin_notices', 'wpal_missing_vendor_notice' );
	return;
}

require_once WPAL_DIR . 'vendor/autoload.php';

register_activation_hook( __FILE__, array( WPActivityLedger\Lifecycle\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( WPActivityLedger\Lifecycle\Deactivator::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( WPActivityLedger\Plugin::class, 'boot' ) );
