<?php
/**
 * Tests for the streamed export.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Admin;

use WPActivityLedger\Admin\ExportHandler;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Plugin;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * @covers \WPActivityLedger\Admin\ExportHandler
 */
final class ExportHandlerTest extends IntegrationTestCase {

	/**
	 * Seeds events for a marker user ID.
	 *
	 * @param int    $count   Events.
	 * @param int    $user_id Actor ID (not a real user).
	 * @param string $label   Object label.
	 *
	 * @return void
	 */
	private static function seed( int $count, int $user_id, string $label = 'Seeded' ): void {
		$recorder = Plugin::instance()->recorder();

		for ( $i = 1; $i <= $count; $i++ ) {
			$recorder->record( new Event( 'post.updated', object_type: 'post', object_id: (string) $i, object_label: $label, user_id: $user_id, user_login: 'seed_' . $user_id ) );

			if ( 0 === $i % 500 ) {
				$recorder->flush();
			}
		}

		$recorder->flush();
	}

	/**
	 * Returns a handler.
	 *
	 * @return ExportHandler Handler.
	 */
	private static function handler(): ExportHandler {
		return new ExportHandler( Plugin::instance()->repository(), Plugin::instance()->recorder() );
	}

	/**
	 * 5,000 events export as 5,000 CSV rows with modest memory, and the export is logged.
	 *
	 * @return void
	 */
	public function test_large_csv_export(): void {
		$this->as_user( 'administrator' );
		self::seed( 5000, 990001 );
		$start = self::max_event_id();
		$out   = fopen( 'php://temp', 'w+' );

		memory_reset_peak_usage();
		$base  = memory_get_usage();
		$count = self::handler()->export( array( 'user' => array( 990001 ) ), 'csv', $out );
		$peak  = memory_get_peak_usage() - $base;

		rewind( $out );
		$header = fgetcsv( $out, null, ',', '"', '' );
		$rows   = 0;

		while ( false !== fgetcsv( $out, null, ',', '"', '' ) ) {
			++$rows;
		}

		$this->assertSame( 5000, $count );
		$this->assertSame( 5000, $rows );
		$this->assertSame( "\xEF\xBB\xBFid", $header[0] );
		$this->assertContains( 'changes', $header );
		$this->assertLessThan( 64 * 1024 * 1024, $peak, 'Peak memory of the export.' );

		$logged = Plugin::instance()->repository()->query( array( 'type' => 'system.logs_exported' ) )['items'];
		$logged = self::newer_than( $logged, $start );
		$this->assertCount( 1, $logged );
		$this->assertSame( 5000, $logged[0]['context']['count'] );
		$this->assertSame( array( 990001 ), $logged[0]['context']['filters']['user'] );
	}

	/**
	 * JSON exports nest changes; a user filter only exports that user's events; formulas are neutralised.
	 *
	 * @return void
	 */
	public function test_json_filter_and_formula_cells(): void {
		$this->as_user( 'administrator' );
		self::seed( 3, 990002, '=HYPERLINK("http://evil")' );
		self::seed( 2, 990003 );

		$out = fopen( 'php://temp', 'w+' );
		self::handler()->export( array( 'user' => array( 990002 ) ), 'json', $out );
		rewind( $out );
		$items = json_decode( (string) stream_get_contents( $out ), true );

		$this->assertCount( 3, $items );
		$this->assertSame( array( 990002 ), array_values( array_unique( array_column( $items, 'user_id' ) ) ) );
		$this->assertArrayHasKey( 'changes', $items[0] );

		$csv = fopen( 'php://temp', 'w+' );
		self::handler()->export( array( 'user' => array( 990002 ) ), 'csv', $csv );
		rewind( $csv );
		$this->assertStringContainsString( "'=HYPERLINK", (string) stream_get_contents( $csv ) );
	}

	/**
	 * Filters are validated with the events schema (lists accept comma strings).
	 *
	 * @return void
	 */
	public function test_filter_validation(): void {
		$this->assertSame(
			array(
				'user' => array( 5, 7 ),
				'type' => array( 'post.updated' ),
			),
			ExportHandler::filters(
				array(
					'action'   => 'wpal_export',
					'user'     => '5,7',
					'type'     => array( 'post.updated' ),
					'per_page' => 10,
					'search'   => '',
				)
			)
		);
		$this->assertInstanceOf( \WP_Error::class, ExportHandler::filters( array( 'orderby' => 'evil' ) ) );
		$this->assertSame( "'+1", ExportHandler::safe_cell( '+1' ) );
		$this->assertSame( 'a+1', ExportHandler::safe_cell( 'a+1' ) );
	}
}
