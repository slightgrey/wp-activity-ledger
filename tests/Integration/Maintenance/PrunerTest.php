<?php
/**
 * Tests for the daily retention clean-up.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Maintenance;

use WPActivityLedger\Domain\Event;
use WPActivityLedger\Maintenance\Pruner;
use WPActivityLedger\Plugin;
use WPActivityLedger\Settings\Settings;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * @covers \WPActivityLedger\Maintenance\Pruner
 */
final class PrunerTest extends IntegrationTestCase {

	/**
	 * Returns a pruner with the given retention settings.
	 *
	 * @param array<string, mixed> $settings Settings.
	 *
	 * @return Pruner Pruner.
	 */
	private static function pruner( array $settings ): Pruner {
		return new Pruner( Plugin::instance()->repository(), new Settings( $settings ), Plugin::instance()->recorder() );
	}

	/**
	 * Counts events older than the cutoff.
	 *
	 * @param string $before UTC datetime.
	 *
	 * @return int Count.
	 */
	private static function older_than( string $before ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpal_events WHERE created_at < %s", $before ) );
	}

	/**
	 * 12,000 expired events go in batches of 5,000, with a follow-up and one summary per run.
	 *
	 * @return void
	 */
	public function test_batches_and_follow_up(): void {
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
		$existing = self::older_than( $cutoff );
		$recorder = Plugin::instance()->recorder();
		$old      = gmdate( 'Y-m-d H:i:s', time() - 120 * DAY_IN_SECONDS );

		for ( $i = 1; $i <= 12000; $i++ ) {
			$recorder->record( new Event( 'post.updated', object_type: 'post', object_id: (string) $i, created_at: $old ) );

			if ( 0 === $i % 1000 ) {
				$recorder->flush();
			}
		}

		wp_unschedule_hook( Pruner::CONTINUE_HOOK );
		$start  = self::max_event_id();
		$pruner = self::pruner(
			array(
				'retention_days' => 90,
				'max_rows'       => 0,
			)
		);

		$first = $pruner->run();
		$this->assertSame( 5000, $first['expired'] );
		$this->assertTrue( $first['more'] );
		$this->assertNotFalse( wp_next_scheduled( Pruner::CONTINUE_HOOK ) );
		$this->assertSame( $existing + 7000, self::older_than( $cutoff ) );

		$runs = 1;

		while ( self::older_than( $cutoff ) > 0 && $runs < 10 ) {
			$pruner->run();
			++$runs;
		}

		$this->assertSame( 0, self::older_than( $cutoff ) );

		$summaries = self::newer_than( Plugin::instance()->repository()->query( array( 'type' => 'system.pruned' ) )['items'], $start );
		$this->assertCount( $runs, $summaries );
		$this->assertSame( 12000 + $existing, array_sum( array_map( static fn( array $e ): int => (int) $e['context']['count'], $summaries ) ) );

		// Nothing left to delete: no summary is recorded.
		$this->assertSame( 0, $pruner->run()['expired'] );
		$this->assertCount( $runs, self::newer_than( Plugin::instance()->repository()->query( array( 'type' => 'system.pruned' ) )['items'], $start ) );
	}

	/**
	 * The row limit deletes the oldest events beyond it; 0 settings delete nothing.
	 *
	 * @return void
	 */
	public function test_row_limit_and_disabled(): void {
		$repository = Plugin::instance()->repository();

		// At least a dozen rows, whatever the log held before.
		for ( $i = 1; $i <= 12; $i++ ) {
			Plugin::instance()->recorder()->record( new Event( 'post.updated', object_type: 'post', object_id: (string) $i ) );
		}

		Plugin::instance()->recorder()->flush();
		$total = $repository->count();

		$this->assertSame(
			array(
				'expired' => 0,
				'excess'  => 0,
				'more'    => false,
			),
			self::pruner(
				array(
					'retention_days' => 0,
					'max_rows'       => 0,
				)
			)->run()
		);

		$result = self::pruner(
			array(
				'retention_days' => 0,
				'max_rows'       => max( 1, $total - 10 ),
			)
		)->run();

		$this->assertSame( min( 10, $total - 1 ), $result['excess'] );
		$this->assertSame( max( 1, $total - 10 ) + 1, $repository->count(), 'The limit, plus the summary event.' );
	}

	/**
	 * The daily event is (re)scheduled when missing.
	 *
	 * @return void
	 */
	public function test_schedule_self_heals(): void {
		wp_unschedule_hook( Pruner::HOOK );
		$this->assertFalse( wp_next_scheduled( Pruner::HOOK ) );

		Pruner::schedule();

		$this->assertNotFalse( wp_next_scheduled( Pruner::HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( Pruner::HOOK ) );
	}
}
