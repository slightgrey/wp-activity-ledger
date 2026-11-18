<?php
/**
 * Snapshot tests for the alert formatters and the webhook signer.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Unit\Alerts;

use PHPUnit\Framework\TestCase;
use WPActivityLedger\Alerts\AlertMessage;
use WPActivityLedger\Alerts\Formatters\DiscordFormatter;
use WPActivityLedger\Alerts\Formatters\EmailFormatter;
use WPActivityLedger\Alerts\Formatters\Formatter;
use WPActivityLedger\Alerts\Formatters\SlackFormatter;
use WPActivityLedger\Alerts\Formatters\TeamsFormatter;
use WPActivityLedger\Alerts\Formatters\WebhookFormatter;
use WPActivityLedger\Alerts\WebhookSigner;

/**
 * @covers \WPActivityLedger\Alerts\Formatters\Formatter
 * @covers \WPActivityLedger\Alerts\Formatters\EmailFormatter
 * @covers \WPActivityLedger\Alerts\Formatters\WebhookFormatter
 * @covers \WPActivityLedger\Alerts\Formatters\SlackFormatter
 * @covers \WPActivityLedger\Alerts\Formatters\DiscordFormatter
 * @covers \WPActivityLedger\Alerts\Formatters\TeamsFormatter
 * @covers \WPActivityLedger\Alerts\WebhookSigner
 * @covers \WPActivityLedger\Alerts\AlertMessage
 */
final class FormattersTest extends TestCase {

	/**
	 * The fixture alert (with characters that need escaping).
	 *
	 * @return AlertMessage Alert.
	 */
	private static function alert(): AlertMessage {
		return new AlertMessage(
			title: 'Plugin activated',
			message: 'Activated plugin "Hello <Dolly> & Co"',
			event_type: 'plugin.activated',
			severity: 'critical',
			category: 'Plugins',
			user: 'admin',
			ip: '203.0.113.5',
			object_label: 'Hello Dolly',
			created_at: '2026-11-18 10:00:00',
			site_name: 'Example',
			site_url: 'https://example.test/',
			log_url: 'https://example.test/wp-admin/admin.php?page=wp-activity-ledger#/log?type=plugin.activated',
			rule: 'Plugin changes',
			event_id: 42
		);
	}

	/**
	 * Returns the formatters by snapshot name.
	 *
	 * @return array<string, array{0: Formatter}> Cases.
	 */
	public static function formatters(): array {
		return array(
			'email'   => array( new EmailFormatter() ),
			'webhook' => array( new WebhookFormatter() ),
			'slack'   => array( new SlackFormatter() ),
			'discord' => array( new DiscordFormatter() ),
			'teams'   => array( new TeamsFormatter() ),
		);
	}

	/**
	 * Each payload matches its snapshot (written on the first run, then reviewed).
	 *
	 * @dataProvider formatters
	 *
	 * @param Formatter $formatter Formatter.
	 *
	 * @return void
	 */
	public function test_payload_snapshot( Formatter $formatter ): void {
		$name     = strtolower( str_replace( 'Formatter', '', ( new \ReflectionClass( $formatter ) )->getShortName() ) );
		$file     = __DIR__ . '/snapshots/' . $name . '.json';
		$actual   = (string) json_encode( $formatter->format( self::alert() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$expected = is_readable( $file ) ? (string) file_get_contents( $file ) : null;

		if ( null === $expected ) {
			file_put_contents( $file, $actual . "\n" );
			$this->markTestIncomplete( "Snapshot $name written; review it and run again." );
		}

		$this->assertSame( trim( $expected ), $actual );
	}

	/**
	 * The email HTML escapes the message.
	 *
	 * @return void
	 */
	public function test_email_escapes_html(): void {
		$email = ( new EmailFormatter() )->format( self::alert() );

		$this->assertStringContainsString( 'Hello &lt;Dolly&gt; &amp; Co', $email['html'] );
		$this->assertStringNotContainsString( '<Dolly>', $email['html'] );
		$this->assertSame( '[Example] Plugin activated', $email['subject'] );
	}

	/**
	 * The signature matches an independently computed HMAC-SHA256 (openssl dgst).
	 *
	 * @return void
	 */
	public function test_signature_known_vector(): void {
		$expected = 'sha256=1698a50bc74d1ff1db85c4e0a5297c2ad9fdba245d5737cdb789e4cc6e098940';

		$this->assertSame( $expected, WebhookSigner::sign( '{"a":1}', 's3cret', 1700000000 ) );
		$this->assertTrue( WebhookSigner::verify( '{"a":1}', 's3cret', 1700000000, $expected ) );
		$this->assertFalse( WebhookSigner::verify( '{"a":2}', 's3cret', 1700000000, $expected ) );
		$this->assertSame( '1700000000', WebhookSigner::headers( '{}', 'x', 1700000000 )['X-WPAL-Timestamp'] );
	}

	/**
	 * Messages survive the job's array round trip.
	 *
	 * @return void
	 */
	public function test_message_round_trip(): void {
		$this->assertEquals( self::alert(), AlertMessage::from_array( self::alert()->to_array() ) );
	}
}
