<?php
/**
 * Plugin Name: WP Activity Ledger test receiver
 * Description: Test fixture. Copied into mu-plugins by an integration test and removed afterwards. Receives webhook alerts at /?wpal_test_hook=1 (fails with 500 for =fail).
 *
 * @package WPActivityLedger
 */

// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput, WordPress.WP.AlternativeFunctions -- Local test fixture only.

if ( ! isset( $_GET['wpal_test_hook'] ) ) {
	return;
}

if ( 'fail' === $_GET['wpal_test_hook'] ) {
	http_response_code( 500 );
	exit;
}

file_put_contents(
	WP_CONTENT_DIR . '/wpal-test-hook.json',
	json_encode(
		array(
			'body'      => file_get_contents( 'php://input' ),
			'timestamp' => $_SERVER['HTTP_X_WPAL_TIMESTAMP'] ?? '',
			'signature' => $_SERVER['HTTP_X_WPAL_SIGNATURE'] ?? '',
			'type'      => $_SERVER['CONTENT_TYPE'] ?? '',
		)
	)
);

http_response_code( 204 );
exit;
