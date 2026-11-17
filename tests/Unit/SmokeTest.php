<?php
/**
 * Smoke test for the unit-test bootstrap.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class SmokeTest extends TestCase {

	/**
	 * The stubs and the real formatting functions are available.
	 *
	 * @return void
	 */
	public function test_bootstrap_provides_wordpress_functions(): void {
		$GLOBALS['wpal_test_filters']['wpal_smoke'] = static fn( $value ) => $value . '!';

		$this->assertSame( 'hi!', apply_filters( 'wpal_smoke', 'hi' ) );
		$this->assertSame( '&lt;b&gt;', esc_html( '<b>' ) );
		$this->assertSame( 'clean text', sanitize_text_field( "  clean\ttext <script>x</script>" ) );
		$this->assertTrue( is_serialized( serialize( array( 1 ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test data.
		$this->assertSame( 'admin@example.test', get_option( 'admin_email' ) );

		do_action( 'wpal_smoke_action', 1, 2 );
		$this->assertSame( array( 'wpal_smoke_action', array( 1, 2 ) ), end( $GLOBALS['wpal_test_actions'] ) );

		unset( $GLOBALS['wpal_test_filters']['wpal_smoke'] );
	}
}
