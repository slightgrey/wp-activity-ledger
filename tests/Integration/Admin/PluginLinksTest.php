<?php
/**
 * Tests for the Plugins screen links.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Admin;

use WPActivityLedger\Admin\PluginLinks;
use WPActivityLedger\Support\Author;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * @covers \WPActivityLedger\Admin\PluginLinks
 */
final class PluginLinksTest extends IntegrationTestCase {

	/**
	 * This plugin's row gets Documentation and Buy me a coffee; other rows are untouched.
	 *
	 * @return void
	 */
	public function test_row_meta(): void {
		$existing = array( 'Version 1.0', 'By someone' );
		$links    = PluginLinks::row_meta( $existing, plugin_basename( WPAL_FILE ) );

		$this->assertSame( $existing, array_slice( array_values( $links ), 0, 2 ) );
		$this->assertStringContainsString( 'href="' . Author::REPOSITORY_URL . '#readme"', $links['wpal-docs'] );
		$this->assertStringContainsString( 'href="https://paypal.me/' . Author::PAYPAL_ME . '"', $links['wpal-coffee'] );
		$this->assertStringContainsString( 'Buy me a coffee', $links['wpal-coffee'] );
		$this->assertStringContainsString( 'target="_blank" rel="noopener noreferrer"', $links['wpal-coffee'] );

		$this->assertSame( $existing, PluginLinks::row_meta( $existing, 'akismet/akismet.php' ) );
		$this->assertSame( array(), PluginLinks::row_meta( null, 'akismet/akismet.php' ) );
	}
}
