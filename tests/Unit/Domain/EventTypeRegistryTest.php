<?php
/**
 * Tests for the event type registry.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use WPActivityLedger\Domain\Category;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Domain\EventType;
use WPActivityLedger\Domain\EventTypeRegistry;
use WPActivityLedger\Domain\Severity;

/**
 * @covers \WPActivityLedger\Domain\EventTypeRegistry
 * @covers \WPActivityLedger\Domain\EventType
 */
final class EventTypeRegistryTest extends TestCase {

	/**
	 * Clears test filters.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpal_test_filters']['wpal_event_types'] );
	}

	/**
	 * Builds a registry with one post type registered through a provider.
	 *
	 * @return EventTypeRegistry Registry.
	 */
	private function registry(): EventTypeRegistry {
		$registry = new EventTypeRegistry();
		$registry->add_provider(
			static function ( EventTypeRegistry $r ): void {
				$r->register( new EventType( 'post.updated', Category::Content, Severity::Info, 'Post updated', 'Updated {object_type} "{object}" ({count} changes)' ) );
			}
		);

		return $registry;
	}

	/**
	 * Providers run lazily on first read, exactly once.
	 *
	 * @return void
	 */
	public function test_providers_run_lazily_once(): void {
		$calls    = 0;
		$registry = new EventTypeRegistry();
		$registry->add_provider(
			static function () use ( &$calls ): void {
				++$calls;
			}
		);

		$this->assertSame( 0, $calls );
		$registry->all();
		$registry->get( 'x.y' );
		$this->assertSame( 1, $calls );
	}

	/**
	 * Placeholders are filled from the event and its scalar context.
	 *
	 * @return void
	 */
	public function test_render_message_fills_placeholders(): void {
		$event = new Event(
			'post.updated',
			object_type: 'page',
			object_id: '12',
			object_label: 'About',
			context: array(
				'count' => 3,
				'list'  => array( 1 ),
			)
		);

		$this->assertSame( 'Updated page "About" (3 changes)', $this->registry()->render_message( $event ) );
	}

	/**
	 * Without a label, {object} falls back to the ID; unknown placeholders vanish.
	 *
	 * @return void
	 */
	public function test_render_message_object_id_fallback_and_unknown_placeholder(): void {
		$event = new Event( 'post.updated', object_type: 'post', object_id: '99' );

		$this->assertSame( 'Updated post "99" ( changes)', $this->registry()->render_message( $event ) );
	}

	/**
	 * Unregistered types get a generic message.
	 *
	 * @return void
	 */
	public function test_unknown_type_uses_fallback(): void {
		$registry = $this->registry();

		$this->assertSame( 'acme.synced: Order 5', $registry->render_message( new Event( 'acme.synced', object_label: 'Order 5' ) ) );
		$this->assertSame( 'acme.ping', $registry->render_message( new Event( 'acme.ping' ) ) );
	}

	/**
	 * The wpal_event_types filter can add and remove types; junk values are dropped.
	 *
	 * @return void
	 */
	public function test_filter_can_change_types(): void {
		$GLOBALS['wpal_test_filters']['wpal_event_types'] = static function ( array $types ): array {
			unset( $types['post.updated'] );
			$types[] = new EventType( 'acme.synced', Category::System, Severity::Notice, 'Synced', 'Synced {object}' );
			$types[] = 'not a type';
			return $types;
		};

		$registry = $this->registry();

		$this->assertNull( $registry->get( 'post.updated' ) );
		$this->assertSame( array( 'acme.synced' ), array_keys( $registry->all() ) );
	}

	/**
	 * Malformed keys are rejected.
	 *
	 * @return void
	 */
	public function test_invalid_keys_are_rejected(): void {
		$this->assertFalse( EventType::is_valid_key( 'nodot' ) );
		$this->assertFalse( EventType::is_valid_key( 'Post.Updated' ) );
		$this->assertFalse( EventType::is_valid_key( 'a.' . str_repeat( 'b', 70 ) ) );
		$this->assertTrue( EventType::is_valid_key( 'woocommerce.order_status_changed' ) );

		$this->expectException( \InvalidArgumentException::class );
		new EventType( 'bad key', Category::System, Severity::Info, 'x', 'x' );
	}
}
