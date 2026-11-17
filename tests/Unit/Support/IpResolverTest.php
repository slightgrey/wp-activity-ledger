<?php
/**
 * Tests for the IP resolver.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use WPActivityLedger\Support\IpResolver;

/**
 * @covers \WPActivityLedger\Support\IpResolver
 */
final class IpResolverTest extends TestCase {

	/**
	 * A spoofed proxy header is ignored when no header is trusted.
	 *
	 * @return void
	 */
	public function test_untrusted_header_is_ignored(): void {
		$server = array(
			'REMOTE_ADDR'          => '203.0.113.9',
			'HTTP_X_FORWARDED_FOR' => '198.51.100.1',
		);

		$this->assertSame( '203.0.113.9', ( new IpResolver( $server ) )->resolve() );
	}

	/**
	 * A trusted header wins, skipping private hops and junk.
	 *
	 * @return void
	 */
	public function test_trusted_header_first_public_ip(): void {
		$server = array(
			'REMOTE_ADDR'          => '10.0.0.5',
			'HTTP_X_FORWARDED_FOR' => 'garbage, 192.168.1.4, 8.8.8.8, 1.1.1.1',
		);

		$this->assertSame( '8.8.8.8', ( new IpResolver( $server, 'HTTP_X_FORWARDED_FOR' ) )->resolve() );
	}

	/**
	 * Falls back to REMOTE_ADDR when the trusted header has no public IP or isn't allowed.
	 *
	 * @return void
	 */
	public function test_fallbacks(): void {
		$server = array(
			'REMOTE_ADDR'           => '127.0.0.1',
			'HTTP_CF_CONNECTING_IP' => '192.168.0.2',
			'HTTP_X_EVIL'           => '8.8.8.8',
		);

		$this->assertSame( '127.0.0.1', ( new IpResolver( $server, 'HTTP_CF_CONNECTING_IP' ) )->resolve() );
		$this->assertSame( '127.0.0.1', ( new IpResolver( $server, 'HTTP_X_EVIL' ) )->resolve() );
	}

	/**
	 * Invalid or missing REMOTE_ADDR gives an empty string.
	 *
	 * @return void
	 */
	public function test_invalid_ip(): void {
		$this->assertSame( '', ( new IpResolver( array( 'REMOTE_ADDR' => '999.1.1.1' ) ) )->resolve() );
		$this->assertSame( '', ( new IpResolver( array() ) )->resolve() );
		$this->assertSame( '', ( new IpResolver( array( 'REMOTE_ADDR' => array( '1.2.3.4' ) ) ) )->resolve() );
	}

	/**
	 * Anonymisation masks IPv4 to /24 and IPv6 to /48.
	 *
	 * @return void
	 */
	public function test_anonymize(): void {
		$this->assertSame( '203.0.113.0', ( new IpResolver( array( 'REMOTE_ADDR' => '203.0.113.77' ), '', true ) )->resolve() );
		$this->assertSame( '2001:db8:85a3::', IpResolver::anonymize( '2001:db8:85a3:8d3:1319:8a2e:370:7348' ) );
		$this->assertSame( '', IpResolver::anonymize( 'nope' ) );
	}
}
