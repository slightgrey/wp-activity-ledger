<?php
/**
 * Tests for the core event type catalogue.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use WPActivityLedger\Domain\Category;
use WPActivityLedger\Domain\CoreEventTypes;
use WPActivityLedger\Domain\EventType;
use WPActivityLedger\Domain\EventTypeRegistry;
use WPActivityLedger\Domain\Severity;

/**
 * @covers \WPActivityLedger\Domain\CoreEventTypes
 */
final class CoreEventTypesTest extends TestCase {

	/**
	 * Every key is unique and valid, with a real category, severity, label and template.
	 *
	 * @return void
	 */
	public function test_definitions_are_valid_and_unique(): void {
		$keys = array();

		foreach ( CoreEventTypes::definitions() as $definition ) {
			[ $key, $category, $severity, $label, $template ] = $definition;

			$this->assertTrue( EventType::is_valid_key( $key ), $key );
			$this->assertInstanceOf( Category::class, $category );
			$this->assertInstanceOf( Severity::class, $severity );
			$this->assertNotSame( '', $label, $key );
			$this->assertNotSame( '', $template, $key );
			$this->assertArrayNotHasKey( $key, $keys, "Duplicate key $key" );
			$keys[ $key ] = true;
		}

		$this->assertGreaterThan( 70, count( $keys ) );
	}

	/**
	 * Registering through the provider fills the registry.
	 *
	 * @return void
	 */
	public function test_register_fills_registry(): void {
		$registry = new EventTypeRegistry();
		$registry->add_provider( array( CoreEventTypes::class, 'register' ) );

		$this->assertSame( count( CoreEventTypes::definitions() ), count( $registry->all() ) );
		$this->assertSame( Severity::Critical, $registry->get( 'system.deactivated' )?->severity );
		$this->assertSame( Category::Auth, $registry->get( 'auth.login_failed' )?->category );
	}
}
