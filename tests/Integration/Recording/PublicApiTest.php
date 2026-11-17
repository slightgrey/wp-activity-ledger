<?php
/**
 * Tests for the public wpal_log() API.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Recording;

use WPActivityLedger\Database\EventRepository;
use WPActivityLedger\Plugin;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * @covers ::wpal_log
 * @covers ::wpal_build_event
 * @covers ::wpal_build_changes
 */
final class PublicApiTest extends IntegrationTestCase {

	/**
	 * Both the function and the action record events with the given details.
	 *
	 * @return void
	 */
	public function test_function_and_action_record_events(): void {
		$this->as_user( 'administrator' );
		$label = 'wpalapi' . strtolower( wp_generate_password( 8, false ) );

		wpal_log(
			'system.test_function',
			array(
				'object_type'  => 'invoice',
				'object_id'    => 42,
				'object_label' => $label,
				'severity'     => 9,
				'context'      => array( 'amount' => '99.00' ),
				'changes'      => array(
					'status'   => array( 'draft', 'sent' ),
					'same'     => array( 'a', 'a' ),
					'api_key'  => array( 'old', 'new' ),
				),
				'bogus'        => 'ignored',
			)
		);
		do_action( 'wpal_log', 'system.test_action', array( 'object_label' => $label ) );

		Plugin::instance()->recorder()->flush();

		$items = ( new EventRepository() )->query(
			array(
				'search'  => $label,
				'orderby' => 'id',
				'order'   => 'asc',
			)
		)['items'];

		$this->assertCount( 2, $items );
		$this->assertSame( 'system.test_function', $items[0]['event_type'] );
		$this->assertSame( 'invoice', $items[0]['object_type'] );
		$this->assertSame( '42', $items[0]['object_id'] );
		$this->assertSame( 3, $items[0]['severity'], 'Severity is clamped to critical.' );
		$this->assertSame( '99.00', $items[0]['context']['amount'] );
		$this->assertSame( "system.test_function: $label", $items[0]['message'] );
		$this->assertSame( 'system.test_action', $items[1]['event_type'] );

		$changes = ( new EventRepository() )->find( $items[0]['id'] )['changes'] ?? array();
		$this->assertSame( array( 'status', 'api_key' ), array_column( $changes, 'field' ) );
		$this->assertSame( '[redacted]', $changes[1]['new'] );
	}

	/**
	 * Invalid type keys are rejected without recording.
	 *
	 * @return void
	 */
	public function test_invalid_type_is_rejected(): void {
		$before = count( Plugin::instance()->recorder()->pending() );

		add_filter( 'doing_it_wrong_trigger_error', '__return_false' );
		wpal_log( 'Not A Key' );
		remove_filter( 'doing_it_wrong_trigger_error', '__return_false' );

		$this->assertCount( $before, Plugin::instance()->recorder()->pending() );
	}
}
