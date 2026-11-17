<?php
/**
 * Tests for the request context.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPActivityLedger\Support\RequestContext;

/**
 * @covers \WPActivityLedger\Support\RequestContext
 */
final class RequestContextTest extends TestCase {

	/**
	 * Flag combinations and the expected source.
	 *
	 * @return array<string, array{0: array<string, bool>, 1: string}> Cases.
	 */
	public static function sources(): array {
		return array(
			'plain web'           => array( array(), 'web' ),
			'admin'               => array( array( 'admin' => true ), 'admin' ),
			'ajax beats admin'    => array(
				array(
					'admin' => true,
					'ajax'  => true,
				),
				'ajax',
			),
			'rest'                => array( array( 'rest' => true ), 'rest' ),
			'xmlrpc'              => array( array( 'xmlrpc' => true ), 'xmlrpc' ),
			'cron beats admin'    => array(
				array(
					'cron'  => true,
					'admin' => true,
				),
				'cron',
			),
			'cli beats all'       => array(
				array(
					'cli'  => true,
					'cron' => true,
					'rest' => true,
				),
				'cli',
			),
			'false flags ignored' => array(
				array(
					'cli'   => false,
					'admin' => false,
				),
				'web',
			),
		);
	}

	/**
	 * The source follows the priority cli > cron > xmlrpc > rest > ajax > admin > web.
	 *
	 * @param array<string, bool> $flags    Flags.
	 * @param string              $expected Expected source.
	 *
	 * @return void
	 */
	#[DataProvider( 'sources' )]
	public function test_source( array $flags, string $expected ): void {
		$this->assertSame( $expected, ( new RequestContext( array(), $flags ) )->source() );
	}

	/**
	 * Method, URI and user agent are cleaned; secret query values are masked.
	 *
	 * @return void
	 */
	public function test_request_details(): void {
		$context = new RequestContext(
			array(
				'REQUEST_METHOD'  => 'post',
				'REQUEST_URI'     => "/wp-login.php?action=rp&key=AbC123&login=bob&_wpnonce=f00\n",
				'HTTP_USER_AGENT' => str_repeat( 'U', 300 ),
			),
			array( 'admin' => true )
		);

		$this->assertSame( 'POST', $context->method() );
		$this->assertSame( '/wp-login.php?action=rp&key=***&login=bob&_wpnonce=***', $context->uri() );
		$this->assertSame( 255, strlen( $context->user_agent() ) );
		$this->assertSame(
			array(
				'source' => 'admin',
				'method' => 'POST',
				'uri'    => '/wp-login.php?action=rp&key=***&login=bob&_wpnonce=***',
			),
			$context->to_context()
		);
	}

	/**
	 * Missing values give empty strings.
	 *
	 * @return void
	 */
	public function test_missing_values(): void {
		$context = new RequestContext( array( 'REQUEST_URI' => array( 'x' ) ), array() );

		$this->assertSame( '', $context->method() );
		$this->assertSame( '', $context->uri() );
		$this->assertSame( '', $context->user_agent() );
		$this->assertSame( '', $context->session_hash() );
	}

	/**
	 * The session hash is 16 hex characters and stable for the same token.
	 *
	 * @return void
	 */
	public function test_session_hash(): void {
		$hash = ( new RequestContext( array(), array(), 'token-abc' ) )->session_hash();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $hash );
		$this->assertSame( $hash, ( new RequestContext( array(), array(), 'token-abc' ) )->session_hash() );
		$this->assertNotSame( $hash, ( new RequestContext( array(), array(), 'token-xyz' ) )->session_hash() );
	}
}
