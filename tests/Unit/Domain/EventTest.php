<?php
/**
 * Tests for the Event and Change value objects.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use WPActivityLedger\Domain\Change;
use WPActivityLedger\Domain\Event;

/**
 * @covers \WPActivityLedger\Domain\Event
 * @covers \WPActivityLedger\Domain\Change
 */
final class EventTest extends TestCase {

	/**
	 * to_row() returns every column, joins roles and encodes context as JSON.
	 *
	 * @return void
	 */
	public function test_to_row_has_every_column(): void {
		$event = new Event(
			event_type: 'post.updated',
			category: 'content',
			severity: 1,
			object_type: 'post',
			object_id: '42',
			object_label: 'Hello / world',
			context: array(
				'source' => 'admin',
				'title'  => 'Café',
			),
			user_id: 7,
			user_roles: array( 'administrator', 'editor' ),
			created_at: '2026-11-17 10:00:00'
		);

		$row = $event->to_row();

		$this->assertSame(
			array( 'created_at', 'user_id', 'user_login', 'user_roles', 'ip', 'user_agent', 'category', 'event_type', 'severity', 'object_type', 'object_id', 'object_label', 'message', 'context', 'session_hash' ),
			array_keys( $row )
		);
		$this->assertSame( 'administrator,editor', $row['user_roles'] );
		$this->assertSame( '{"source":"admin","title":"Café"}', $row['context'] );
		$this->assertSame( 7, $row['user_id'] );
		$this->assertSame( 1, $row['severity'] );
	}

	/**
	 * Empty context is stored as "{}", and long strings are cut to the column length.
	 *
	 * @return void
	 */
	public function test_to_row_truncates_and_defaults(): void {
		$row = ( new Event( 'x.y', user_agent: str_repeat( 'a', 300 ), object_label: str_repeat( 'é', 300 ) ) )->to_row();

		$this->assertSame( '{}', $row['context'] );
		$this->assertSame( 255, strlen( $row['user_agent'] ) );
		$this->assertSame( 255, mb_strlen( $row['object_label'] ) );
	}

	/**
	 * with() copies the event and leaves the original unchanged.
	 *
	 * @return void
	 */
	public function test_with_returns_a_modified_copy(): void {
		$event = new Event( 'user.login' );
		$copy  = $event->with(
			array(
				'user_id' => 3,
				'ip'      => '10.0.0.1',
			)
		);

		$this->assertSame( 0, $event->user_id );
		$this->assertSame( 3, $copy->user_id );
		$this->assertSame( '10.0.0.1', $copy->ip );
		$this->assertSame( 'user.login', $copy->event_type );
	}

	/**
	 * with() rejects unknown property names.
	 *
	 * @return void
	 */
	public function test_with_rejects_unknown_fields(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new Event( 'a.b' ) )->with( array( 'nope' => 1 ) );
	}

	/**
	 * with_changes() appends changes.
	 *
	 * @return void
	 */
	public function test_with_changes_appends(): void {
		$event = ( new Event( 'a.b', changes: array( new Change( 'one', 1, 2 ) ) ) )->with_changes( new Change( 'two', null, 'x' ) );

		$this->assertSame( array( 'one', 'two' ), array_map( static fn( Change $c ) => $c->field, $event->changes ) );
	}

	/**
	 * Change rows store strings as-is, null as NULL and other values as JSON.
	 *
	 * @return void
	 */
	public function test_change_row_stringifies_values(): void {
		$row = ( new Change( 'tags', array( 'a', 'b' ), null, true ) )->to_row( 9 );

		$this->assertSame(
			array(
				'event_id'  => 9,
				'field'     => 'tags',
				'old_value' => '["a","b"]',
				'new_value' => null,
				'truncated' => 1,
			),
			$row
		);
		$this->assertSame( '5', Change::stringify( 5 ) );
		$this->assertSame( 'true', Change::stringify( true ) );
		$this->assertSame( 'plain', Change::stringify( 'plain' ) );
	}
}
