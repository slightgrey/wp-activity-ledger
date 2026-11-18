<?php
/**
 * Tests for POST /integration-request.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Rest;

use WPActivityLedger\Rest\IntegrationRequestController;
use WPActivityLedger\Support\Author;

/**
 * @covers \WPActivityLedger\Rest\IntegrationRequestController
 */
final class IntegrationRequestControllerTest extends RestTestCase {

	/**
	 * Emails caught by pre_wp_mail.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $mails = array();

	/**
	 * What pre_wp_mail returns (true = "sent", false = "failed").
	 *
	 * @var bool
	 */
	private bool $mail_result = true;

	/**
	 * Catches emails instead of sending them.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mails       = array();
		$this->mail_result = true;
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
	}

	/**
	 * Removes the mail catcher.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10 );
		parent::tearDown();
	}

	/**
	 * Records an email.
	 *
	 * @param null|bool            $short_circuit Null.
	 * @param array<string, mixed> $atts          wp_mail() arguments.
	 *
	 * @return bool Fake result.
	 */
	public function catch_mail( $short_circuit, array $atts ): bool {
		$this->mails[] = $atts;

		return $this->mail_result;
	}

	/**
	 * A valid request is emailed to the author with the requester as Reply-To and the site details.
	 *
	 * @return void
	 */
	public function test_sends_request(): void {
		$this->as_user( 'administrator' );

		$response = self::request(
			'POST',
			'/integration-request',
			array(
				'name'       => "Jane\r\nBcc: evil@example.test",
				'email'      => 'jane@example.test',
				'plugin'     => 'Gravity Forms',
				'plugin_url' => 'https://wordpress.org/plugins/gravityforms/',
				'message'    => "Entries edited\nand deleted.",
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'sent' => true ), $response->get_data() );
		$this->assertCount( 1, $this->mails );

		$mail    = $this->mails[0];
		$headers = implode( "\n", (array) $mail['headers'] );

		$this->assertSame( Author::REQUEST_EMAIL, $mail['to'] );
		$this->assertSame( '[Activity Ledger] Integration request: Gravity Forms', $mail['subject'] );
		$this->assertStringContainsString( 'Reply-To: Jane Bcc: evil@example.test <jane@example.test>', $headers );
		$this->assertStringNotContainsString( "\nBcc:", $headers, 'A line break in the name must not add a header.' );
		$this->assertStringContainsString( "Entries edited\nand deleted.", $mail['message'] );
		$this->assertStringContainsString( 'Plugin link: https://wordpress.org/plugins/gravityforms/', $mail['message'] );
		$this->assertStringContainsString( 'Site: ' . home_url( '/' ), $mail['message'] );
		$this->assertStringContainsString( 'Activity Ledger: ' . WPAL_VERSION, $mail['message'] );
	}

	/**
	 * Invalid fields return 400 with errors per field, and nothing is sent.
	 *
	 * @return void
	 */
	public function test_validation(): void {
		$this->as_user( 'administrator' );

		$response = self::request(
			'POST',
			'/integration-request',
			array(
				'email'      => 'not-an-email',
				'plugin'     => '   ',
				'plugin_url' => 'javascript:alert(1)',
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array( 'email', 'plugin', 'plugin_url' ), array_keys( $response->get_data()['data']['errors'] ) );
		$this->assertCount( 0, $this->mails );
	}

	/**
	 * Each user may send LIMIT requests per hour; a failed send doesn't count and returns 502.
	 *
	 * @return void
	 */
	public function test_limit_and_mail_failure(): void {
		$this->as_user( 'administrator' );
		$valid = array(
			'email'  => 'jane@example.test',
			'plugin' => 'Some plugin',
		);

		$this->mail_result = false;
		$failed            = self::request( 'POST', '/integration-request', $valid );
		$this->assertSame( 502, $failed->get_status() );
		$this->assertSame( 'wpal_mail_failed', $failed->get_data()['code'] );

		$this->mail_result = true;

		for ( $i = 0; $i < IntegrationRequestController::LIMIT; $i++ ) {
			$this->assertSame( 200, self::request( 'POST', '/integration-request', $valid )->get_status() );
		}

		$this->assertSame( 429, self::request( 'POST', '/integration-request', $valid )->get_status() );
		$this->assertCount( 1 + IntegrationRequestController::LIMIT, $this->mails );
	}

	/**
	 * Editors and logged-out visitors can't send requests.
	 *
	 * @return void
	 */
	public function test_permissions(): void {
		$valid = array(
			'email'  => 'jane@example.test',
			'plugin' => 'Some plugin',
		);

		wp_set_current_user( 0 );
		$this->assertSame( 401, self::request( 'POST', '/integration-request', $valid )->get_status() );

		$this->as_user( 'editor' );
		$this->assertSame( 403, self::request( 'POST', '/integration-request', $valid )->get_status() );
		$this->assertCount( 0, $this->mails );
	}
}
