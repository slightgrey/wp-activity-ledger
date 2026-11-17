<?php
/**
 * PHPUnit bootstrap for the integration suite.
 *
 * WordPress is already loaded by tests/Integration/run.php; this only checks that
 * the suite was started through the runner and the plugin is active on the site.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

if ( ! defined( 'WPAL_INTEGRATION_TESTS' ) || ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run the integration suite through tests/Integration/run.php (composer test:integration).\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI output.
	exit( 1 );
}

if ( ! defined( 'WPAL_VERSION' ) ) {
	fwrite( STDERR, 'WP Activity Ledger is not active on ' . home_url() . ". Activate it before running the integration suite.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI output.
	exit( 1 );
}
