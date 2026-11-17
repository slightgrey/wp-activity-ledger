<?php
/**
 * Tests for the Redactor.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Unit\Diff;

use PHPUnit\Framework\TestCase;
use WPActivityLedger\Diff\Redactor;
use WPActivityLedger\Support\PatternMatcher;

/**
 * @covers \WPActivityLedger\Diff\Redactor
 */
final class RedactorTest extends TestCase {

	/**
	 * Builds a redactor with the default patterns.
	 *
	 * @return Redactor Redactor.
	 */
	private function redactor(): Redactor {
		return new Redactor( new PatternMatcher( array( '*pass*', '*secret*', '*token*', '*api_key*', '*license*' ) ) );
	}

	/**
	 * A sensitive top-level key hides the whole value, whatever its type.
	 *
	 * @return void
	 */
	public function test_sensitive_key_hides_whole_value(): void {
		$redactor = $this->redactor();

		$this->assertSame( Redactor::MARKER, $redactor->redact_value( 'smtp_password', 'hunter2' ) );
		$this->assertSame( Redactor::MARKER, $redactor->redact_value( 'Stripe_API_Key', array( 'live' => 'sk_1' ) ) );
		$this->assertNull( $redactor->redact_value( 'smtp_password', null ) );
	}

	/**
	 * Nested sensitive keys are hidden; other keys are kept.
	 *
	 * @return void
	 */
	public function test_nested_keys_are_redacted(): void {
		$value = array(
			'host'     => 'smtp.example.test',
			'auth'     => array(
				'user'      => 'me',
				'pass'      => 'x',
				'api_token' => 'y',
			),
			'licenses' => array( 'abc' ),
		);

		$this->assertSame(
			array(
				'host'     => 'smtp.example.test',
				'auth'     => array(
					'user'      => 'me',
					'pass'      => Redactor::MARKER,
					'api_token' => Redactor::MARKER,
				),
				'licenses' => Redactor::MARKER,
			),
			$this->redactor()->redact_value( 'mail_settings', $value )
		);
	}

	/**
	 * Objects become arrays with their secrets redacted; scalars pass through.
	 *
	 * @return void
	 */
	public function test_objects_and_scalars(): void {
		$object         = new \stdClass();
		$object->name   = 'gateway';
		$object->secret = 's';

		$this->assertSame(
			array(
				'name'   => 'gateway',
				'secret' => Redactor::MARKER,
			),
			$this->redactor()->redact_nested( $object )
		);
		$this->assertSame( 42, $this->redactor()->redact_value( 'count', 42 ) );
		$this->assertFalse( $this->redactor()->is_sensitive( 'blogname' ) );
	}
}
