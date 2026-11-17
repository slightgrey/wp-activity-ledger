<?php
/**
 * Tests for the ToolsSensor.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Sensors;

use WPActivityLedger\Plugin;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * @covers \WPActivityLedger\Sensors\ToolsSensor
 */
final class ToolsSensorTest extends IntegrationTestCase {

	/**
	 * Export, import and privacy request hooks are recorded.
	 *
	 * @return void
	 */
	public function test_tools_events(): void {
		$this->as_user( 'administrator' );
		$start = self::max_event_id();
		$email = 'wpal-privacy-' . strtolower( wp_generate_password( 5, false ) ) . '@example.test';

		$request_id = wp_create_user_request( $email, 'export_personal_data' );
		$this->assertIsInt( $request_id );

		do_action( 'export_wp', array( 'content' => 'post' ) );
		do_action( 'import_end' );
		do_action( 'wp_privacy_personal_data_export_file_created', 'a.zip', 'https://x/a.zip', 'a.html', $request_id, 'a.json' );
		do_action( 'wp_privacy_personal_data_erased', $request_id );

		Plugin::instance()->recorder()->flush();
		$events = self::newer_than(
			Plugin::instance()->repository()->query(
				array(
					'category' => 'core',
					'orderby'  => 'id',
					'order'    => 'asc',
				)
			)['items'],
			$start
		);

		$this->assertSame( array( 'core.export_run', 'core.import_run', 'core.privacy_export', 'core.privacy_erase' ), array_column( $events, 'event_type' ) );
		$this->assertSame( 'Exported site content (post)', $events[0]['message'] );
		$this->assertSame( "Erased personal data for $email", $events[3]['message'] );
	}
}
