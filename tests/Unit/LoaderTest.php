<?php
/**
 * Tests for the hook Loader.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPActivityLedger\Loader;

/**
 * @covers \WPActivityLedger\Loader
 */
final class LoaderTest extends TestCase {

	/**
	 * Clears recorded hook calls.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['wpal_test_hooks'] = array();
	}

	/**
	 * Queued hooks reach WordPress only when run() is called, with priority and args.
	 *
	 * @return void
	 */
	public function test_run_registers_queued_hooks(): void {
		$loader = new Loader();
		$loader->add_action( 'init', 'strlen', 5, 2 );
		$loader->add_filter( 'the_title', 'trim' );

		$this->assertSame( array(), $GLOBALS['wpal_test_hooks'] );

		$loader->run();

		$this->assertSame(
			array(
				array( 'action', 'init', 'strlen', 5, 2 ),
				array( 'filter', 'the_title', 'trim', 10, 1 ),
			),
			$GLOBALS['wpal_test_hooks']
		);
	}

	/**
	 * Running twice doesn't register hooks twice.
	 *
	 * @return void
	 */
	public function test_run_is_idempotent(): void {
		$loader = new Loader();
		$loader->add_action( 'init', 'strlen' );
		$loader->run();
		$loader->run();

		$this->assertCount( 1, $GLOBALS['wpal_test_hooks'] );
	}

	/**
	 * Hooks added after run() are registered immediately.
	 *
	 * @return void
	 */
	public function test_late_hooks_register_immediately(): void {
		$loader = new Loader();
		$loader->run();
		$loader->add_filter( 'the_content', 'trim', 20 );

		$this->assertSame( array( array( 'filter', 'the_content', 'trim', 20, 1 ) ), $GLOBALS['wpal_test_hooks'] );
		$this->assertSame( array(), $loader->filters() );
	}
}
