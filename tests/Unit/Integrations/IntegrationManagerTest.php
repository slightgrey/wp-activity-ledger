<?php
/**
 * Tests for the IntegrationManager.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Unit\Integrations;

use PHPUnit\Framework\TestCase;
use WPActivityLedger\Domain\Category;
use WPActivityLedger\Domain\EventType;
use WPActivityLedger\Domain\EventTypeRegistry;
use WPActivityLedger\Domain\Severity;
use WPActivityLedger\Integrations\IntegrationInterface;
use WPActivityLedger\Integrations\IntegrationManager;
use WPActivityLedger\Loader;

/**
 * @covers \WPActivityLedger\Integrations\IntegrationManager
 */
final class IntegrationManagerTest extends TestCase {

	/**
	 * Clears test filters.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpal_test_filters']['wpal_integrations'] );
	}

	/**
	 * Builds a dummy integration.
	 *
	 * @param string $slug      Slug.
	 * @param bool   $available Whether its plugin is "active".
	 *
	 * @return IntegrationInterface Integration.
	 */
	private static function integration( string $slug, bool $available ): IntegrationInterface {
		return new class( $slug, $available ) implements IntegrationInterface {
			/**
			 * Creates the dummy.
			 *
			 * @param string $slug      Slug.
			 * @param bool   $available Availability.
			 */
			public function __construct( private string $slug, private bool $available ) {
			}

			/**
			 * Slug.
			 *
			 * @return string Slug.
			 */
			public function slug(): string {
				return $this->slug;
			}

			/**
			 * Name.
			 *
			 * @return string Name.
			 */
			public function name(): string {
				return ucfirst( $this->slug );
			}

			/**
			 * Availability.
			 *
			 * @return bool Available.
			 */
			public function is_available(): bool {
				return $this->available;
			}

			/**
			 * Queues one hook.
			 *
			 * @param Loader $loader Loader.
			 *
			 * @return void
			 */
			public function register( Loader $loader ): void {
				$loader->add_action( "{$this->slug}_hook", 'strlen' );
			}

			/**
			 * One event type.
			 *
			 * @return array<int, EventType> Types.
			 */
			public function event_types(): array {
				return array( new EventType( "{$this->slug}.thing_done", Category::System, Severity::Info, 'Thing', 'Did {object}' ) );
			}
		};
	}

	/**
	 * Only available integrations register hooks and event types.
	 *
	 * @return void
	 */
	public function test_only_available_integrations_load(): void {
		$loader   = new Loader();
		$types    = new EventTypeRegistry();
		$manager  = new IntegrationManager( array( self::integration( 'shop', true ), self::integration( 'builder', false ) ) );

		$manager->load( $loader, $types );

		$this->assertSame( array( 'shop_hook' ), array_column( $loader->actions(), 'hook' ) );
		$this->assertNotNull( $types->get( 'shop.thing_done' ) );
		$this->assertNull( $types->get( 'builder.thing_done' ) );
		$this->assertSame( array( 'shop' => 'Shop' ), $manager->active() );
		$this->assertSame(
			array(
				'shop'    => 'Shop',
				'builder' => 'Builder',
			),
			$manager->supported()
		);
	}

	/**
	 * The filter can add integrations; junk is ignored; loading twice doesn't double-register.
	 *
	 * @return void
	 */
	public function test_filter_and_idempotence(): void {
		$extra = self::integration( 'forms', true );

		$GLOBALS['wpal_test_filters']['wpal_integrations'] = static fn( array $list ): array => array_merge( $list, array( $extra, 'junk' ) );

		$loader  = new Loader();
		$manager = new IntegrationManager( array() );
		$manager->load( $loader, new EventTypeRegistry() );
		$manager->load( $loader, new EventTypeRegistry() );

		$this->assertSame( array( 'forms_hook' ), array_column( $loader->actions(), 'hook' ) );
	}
}
