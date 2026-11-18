<?php
/**
 * Tests for the QueryBuilder.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use WPActivityLedger\Database\QueryBuilder;

/**
 * @covers \WPActivityLedger\Database\QueryBuilder
 */
final class QueryBuilderTest extends TestCase {

	/**
	 * No filters means no WHERE clause and sensible defaults.
	 *
	 * @return void
	 */
	public function test_defaults(): void {
		$query = new QueryBuilder( array() );

		$this->assertSame( array( '', array() ), $query->where() );
		$this->assertSame( 'ORDER BY created_at DESC, id DESC', $query->order_by() );
		$this->assertSame( 'LIMIT 25 OFFSET 0', $query->limit() );
	}

	/**
	 * Every filter adds its condition and arguments in order.
	 *
	 * @return void
	 */
	public function test_all_filters(): void {
		$query = new QueryBuilder(
			array(
				'from'        => '2026-11-01',
				'to'          => '2026-11-17 12:30:00',
				'user'        => array( 3, '7' ),
				'role'        => 'administrator',
				'category'    => 'content,auth',
				'type'        => array( 'post.updated' ),
				'severity'    => 'warning',
				'object_type' => 'post',
				'object_id'   => 42,
				'ip'          => '203.0.113.9',
				'session'     => 'abcdef0123456789',
				'search'      => 'hello',
			)
		);

		[ $sql, $args ] = $query->where();

		$this->assertSame(
			'WHERE created_at >= %s AND created_at <= %s AND user_id IN (%d,%d) AND FIND_IN_SET(%s, user_roles) > 0'
			. ' AND category IN (%s,%s) AND event_type IN (%s) AND severity >= %d AND object_type = %s AND object_id = %s'
			. ' AND ip = %s AND session_hash = %s AND (message LIKE %s OR object_label LIKE %s)',
			$sql
		);
		$this->assertSame(
			array( '2026-11-01 00:00:00', '2026-11-17 12:30:00', 3, 7, 'administrator', 'content', 'auth', 'post.updated', 2, 'post', '42', '203.0.113.9', 'abcdef0123456789', '%hello%', '%hello%' ),
			$args
		);
	}

	/**
	 * Invalid values are dropped instead of reaching the SQL.
	 *
	 * @return void
	 */
	public function test_invalid_values_are_dropped(): void {
		$query = new QueryBuilder(
			array(
				'from'     => '2026-13-45',
				'to'       => 'yesterday',
				'user'     => '',
				'role'     => "admin'--",
				'category' => array( 'Content', 'bad slug', 'auth' ),
				'type'     => array( 'nodot', 'DROP.TABLE' ),
				'severity' => 'fatal',
				'ip'       => str_repeat( '1', 50 ),
				'search'   => '   ',
			)
		);

		$this->assertSame( array( 'WHERE category IN (%s)', array( 'auth' ) ), $query->where() );
	}

	/**
	 * Info severity adds nothing (it is the minimum anyway).
	 *
	 * @return void
	 */
	public function test_info_severity_is_no_filter(): void {
		$this->assertSame( array( '', array() ), ( new QueryBuilder( array( 'severity' => 0 ) ) )->where() );
		$this->assertSame( array( 'WHERE severity >= %d', array( 3 ) ), ( new QueryBuilder( array( 'severity' => '3' ) ) )->where() );
	}

	/**
	 * LIKE wildcards in the search text are escaped.
	 *
	 * @return void
	 */
	public function test_search_escapes_like_wildcards(): void {
		[ , $args ] = ( new QueryBuilder( array( 'search' => '50%_off\\' ) ) )->where();

		$this->assertSame( '%50\\%\\_off\\\\%', $args[0] );
	}

	/**
	 * hide_system drops System events below warning, unless the user filter asks for System.
	 *
	 * @return void
	 */
	public function test_hide_system(): void {
		$condition = '(user_id <> 0 OR severity >= 2)';

		$this->assertSame( $condition, QueryBuilder::system_condition() );

		foreach ( array( true, 1, '1', 'true' ) as $on ) {
			$this->assertSame( array( 'WHERE ' . $condition, array() ), ( new QueryBuilder( array( 'hide_system' => $on ) ) )->where() );
		}

		foreach ( array( false, 0, '0', 'false', '', 'yes please' ) as $off ) {
			$this->assertSame( array( '', array() ), ( new QueryBuilder( array( 'hide_system' => $off ) ) )->where() );
		}

		$this->assertSame(
			array( 'WHERE user_id IN (%d) AND ' . $condition, array( 5 ) ),
			( new QueryBuilder( array( 'hide_system' => true, 'user' => array( 5 ) ) ) )->where()
		);
		$this->assertSame(
			array( 'WHERE user_id IN (%d,%d)', array( 0, 5 ) ),
			( new QueryBuilder( array( 'hide_system' => true, 'user' => array( 0, 5 ) ) ) )->where()
		);
	}

	/**
	 * Unknown sort columns fall back to created_at; direction and paging are clamped.
	 *
	 * @return void
	 */
	public function test_order_and_paging(): void {
		$this->assertSame( 'ORDER BY created_at DESC, id DESC', ( new QueryBuilder( array( 'orderby' => 'password; DROP' ) ) )->order_by() );
		$this->assertSame( 'ORDER BY severity ASC, id ASC', ( new QueryBuilder( array( 'orderby' => 'severity', 'order' => 'ASC' ) ) )->order_by() );
		$this->assertSame( 'ORDER BY id DESC', ( new QueryBuilder( array( 'orderby' => 'id' ) ) )->order_by() );
		$this->assertSame( 'LIMIT 100 OFFSET 200', ( new QueryBuilder( array( 'page' => 3, 'per_page' => 500 ) ) )->limit() );
		$this->assertSame( 'LIMIT 1 OFFSET 0', ( new QueryBuilder( array( 'page' => -4, 'per_page' => 0 ) ) )->limit() );
	}
}
