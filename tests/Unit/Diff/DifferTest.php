<?php
/**
 * Tests for the Differ.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Unit\Diff;

use PHPUnit\Framework\TestCase;
use WPActivityLedger\Diff\Differ;
use WPActivityLedger\Diff\Redactor;
use WPActivityLedger\Domain\Change;
use WPActivityLedger\Support\PatternMatcher;

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test data.

/**
 * @covers \WPActivityLedger\Diff\Differ
 */
final class DifferTest extends TestCase {

	/**
	 * Builds a differ with the default redaction patterns.
	 *
	 * @param int $max Maximum value size.
	 *
	 * @return Differ Differ.
	 */
	private function differ( int $max = 10240 ): Differ {
		return new Differ( new Redactor( new PatternMatcher( array( '*pass*', '*secret*' ) ) ), $max );
	}

	/**
	 * Returns the changes as plain arrays.
	 *
	 * @param array<int, Change> $changes Changes.
	 *
	 * @return array<int, array{field: string, old: mixed, new: mixed, truncated: bool}> Arrays.
	 */
	private static function plain( array $changes ): array {
		return array_map( static fn( Change $c ): array => $c->to_array(), $changes );
	}

	/**
	 * Identical and equivalent values produce no changes.
	 *
	 * @return void
	 */
	public function test_equivalent_values_are_unchanged(): void {
		$before = array(
			'a' => '1',
			'b' => null,
			'c' => true,
			'd' => 2.0,
			'e' => serialize( array( 'x' => 1 ) ),
		);
		$after  = array(
			'a' => 1,
			'b' => '',
			'c' => '1',
			'd' => '2',
			'e' => array( 'x' => '1' ),
		);

		$this->assertSame( array(), $this->differ()->diff( $before, $after ) );
	}

	/**
	 * Changed, added and removed fields are reported in order.
	 *
	 * @return void
	 */
	public function test_changed_added_and_removed_fields(): void {
		$changes = $this->differ()->diff(
			array(
				'title'  => 'Old',
				'status' => 'draft',
				'gone'   => 'x',
			),
			array(
				'title'  => 'New',
				'status' => 'draft',
				'added'  => 'y',
			)
		);

		$this->assertSame(
			array(
				array(
					'field'     => 'title',
					'old'       => 'Old',
					'new'       => 'New',
					'truncated' => false,
				),
				array(
					'field'     => 'gone',
					'old'       => 'x',
					'new'       => null,
					'truncated' => false,
				),
				array(
					'field'     => 'added',
					'old'       => null,
					'new'       => 'y',
					'truncated' => false,
				),
			),
			self::plain( $changes )
		);
	}

	/**
	 * Serialized and JSON strings are decoded, so stored values are arrays.
	 *
	 * @return void
	 */
	public function test_serialized_and_json_are_decoded(): void {
		$change = $this->differ()->compare( 'opt', serialize( array( 'a' => 1 ) ), '{"a":2}' );

		$this->assertNotNull( $change );
		$this->assertSame( array( 'a' => 1 ), $change->old_value );
		$this->assertSame( array( 'a' => 2 ), $change->new_value );
		$this->assertSame( 'not json {', Differ::decode( 'not json {' ) );
		$this->assertFalse( Differ::decode( 'b:0;' ) );
	}

	/**
	 * Large values are cut to the limit and flagged.
	 *
	 * @return void
	 */
	public function test_large_values_are_truncated(): void {
		$change = $this->differ( 10 )->compare( 'content', 'short', str_repeat( 'é', 20 ) );

		$this->assertNotNull( $change );
		$this->assertTrue( $change->truncated );
		$this->assertSame( 'short', $change->old_value );
		$this->assertSame( str_repeat( 'é', 5 ), $change->new_value );

		$array_change = $this->differ( 10 )->compare( 'list', array(), range( 1, 50 ) );
		$this->assertNotNull( $array_change );
		$this->assertSame( '[1,2,3,4,5', $array_change->new_value );
	}

	/**
	 * Secret fields show a change without the values; nested secrets are hidden.
	 *
	 * @return void
	 */
	public function test_redaction(): void {
		$changes = $this->differ()->diff(
			array(
				'smtp_pass' => 'old',
				'config'    => array(
					'host'   => 'a',
					'secret' => 'x',
				),
			),
			array(
				'smtp_pass' => 'new',
				'config'    => array(
					'host'   => 'b',
					'secret' => 'y',
				),
			)
		);

		$this->assertSame( Redactor::MARKER, $changes[0]->old_value );
		$this->assertSame( Redactor::MARKER, $changes[0]->new_value );
		$this->assertSame(
			array(
				'host'   => 'b',
				'secret' => Redactor::MARKER,
			),
			$changes[1]->new_value
		);
	}

	/**
	 * Ignored fields are skipped, and the prefix is added to field names.
	 *
	 * @return void
	 */
	public function test_ignore_and_prefix(): void {
		$changes = $this->differ()->diff(
			array(
				'_edit_lock' => '1',
				'price'      => '5',
			),
			array(
				'_edit_lock' => '2',
				'price'      => '6',
			),
			new PatternMatcher( array( '*_lock' ) ),
			'meta:'
		);

		$this->assertCount( 1, $changes );
		$this->assertSame( 'meta:price', $changes[0]->field );
	}
}
