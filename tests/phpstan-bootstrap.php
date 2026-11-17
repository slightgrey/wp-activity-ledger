<?php
/**
 * Defines the plugin constants for PHPStan, which can't execute the main plugin file.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

define( 'WPAL_VERSION', '0.1.0' );
define( 'WPAL_FILE', dirname( __DIR__ ) . '/wp-activity-ledger.php' );
define( 'WPAL_DIR', dirname( __DIR__ ) . '/' );
define( 'WPAL_URL', 'http://example.test/wp-content/plugins/wp-activity-ledger/' );
