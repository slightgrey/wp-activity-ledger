<?php
/**
 * Runs the integration suite against a real WordPress install.
 *
 * WordPress (and the plugins it loads) must be included at true global scope, so
 * top-level variables in wp-config.php, wp-settings.php and plugin files stay
 * global. PHPUnit includes its bootstrap file inside a method, which would break
 * that, so this script loads WordPress first and then hands over to PHPUnit.
 *
 * Environment:
 * - WPAL_TEST_HOST  Host name WordPress is served on (default "plugin.test").
 * - WPAL_TEST_PATH  Site path WITHOUT the leading slash, e.g. "plugin-ms/site2/" (default "").
 *                   Git Bash rewrites values that start with "/", so the slash is added here.
 * - WPAL_TEST_ROOT  WordPress root directory (default: four levels above the plugin).
 *
 * Usage: php tests/Integration/run.php [phpunit arguments]
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

// phpcs:disable WordPress.Security.ValidatedSanitizedInput, WordPress.NamingConventions.PrefixAllGlobals -- CLI test runner, not plugin code.

$wpal_plugin_dir = dirname( __DIR__, 2 );
$wpal_host       = getenv( 'WPAL_TEST_HOST' ) ? (string) getenv( 'WPAL_TEST_HOST' ) : 'plugin.test';
$wpal_path       = '/' . ltrim( (string) getenv( 'WPAL_TEST_PATH' ), '/' );
$wpal_root       = getenv( 'WPAL_TEST_ROOT' ) ? rtrim( (string) getenv( 'WPAL_TEST_ROOT' ), '/\\' ) : dirname( $wpal_plugin_dir, 3 );

$_SERVER['HTTP_HOST']       = $wpal_host;
$_SERVER['SERVER_NAME']     = $wpal_host;
$_SERVER['REQUEST_URI']     = $wpal_path;
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REQUEST_SCHEME']  = 'http';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';

define( 'WPAL_INTEGRATION_TESTS', true );

require $wpal_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once $wpal_plugin_dir . '/vendor/autoload.php';

$wpal_argv = $_SERVER['argv'];

if ( ! in_array( '-c', $wpal_argv, true ) && ! in_array( '--configuration', $wpal_argv, true ) ) {
	array_splice( $wpal_argv, 1, 0, array( '-c', $wpal_plugin_dir . '/phpunit-integration.xml.dist' ) );
}

exit( ( new PHPUnit\TextUI\Application() )->run( $wpal_argv ) );
