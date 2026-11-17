<?php
/**
 * Tests for the SensorRegistry.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Unit\Sensors;

use PHPUnit\Framework\TestCase;
use WPActivityLedger\Loader;
use WPActivityLedger\Sensors\SensorInterface;
use WPActivityLedger\Sensors\SensorRegistry;

/**
 * @covers \WPActivityLedger\Sensors\SensorRegistry
 */
final class SensorRegistryTest extends TestCase {

	/**
	 * Clears filters and recorded hooks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['wpal_test_hooks'] = array();
		unset( $GLOBALS['wpal_test_filters']['wpal_sensors'] );
	}

	/**
	 * Builds a dummy sensor that hooks into the given action.
	 *
	 * @param string $hook Hook.
	 *
	 * @return SensorInterface Sensor.
	 */
	private static function sensor( string $hook ): SensorInterface {
		return new class( $hook ) implements SensorInterface {
			/**
			 * Creates the dummy.
			 *
			 * @param string $hook Hook.
			 */
			public function __construct( private string $hook ) {
			}

			/**
			 * Queues one hook.
			 *
			 * @param Loader $loader Loader.
			 *
			 * @return void
			 */
			public function register( Loader $loader ): void {
				$loader->add_action( $this->hook, 'strlen' );
			}
		};
	}

	/**
	 * Every sensor's hooks end up on the loader.
	 *
	 * @return void
	 */
	public function test_registers_sensor_hooks(): void {
		$loader   = new Loader();
		$registry = new SensorRegistry(
			array(
				'a' => self::sensor( 'hook_a' ),
				'b' => self::sensor( 'hook_b' ),
			)
		);

		$registry->register( $loader );

		$this->assertSame( array( 'hook_a', 'hook_b' ), array_column( $loader->actions(), 'hook' ) );
	}

	/**
	 * The filter can remove and add sensors; junk is ignored.
	 *
	 * @return void
	 */
	public function test_filter_changes_sensors(): void {
		$extra = self::sensor( 'hook_extra' );

		$GLOBALS['wpal_test_filters']['wpal_sensors'] = static function ( array $sensors ) use ( $extra ): array {
			unset( $sensors['a'] );
			$sensors['extra'] = $extra;
			$sensors['junk']  = 'nope';
			return $sensors;
		};

		$loader = new Loader();
		( new SensorRegistry( array( 'a' => self::sensor( 'hook_a' ) ) ) )->register( $loader );

		$this->assertSame( array( 'hook_extra' ), array_column( $loader->actions(), 'hook' ) );
	}
}
