<?php
/**
 * Reads and writes activity events.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Database;

use WPActivityLedger\Domain\Event;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- Custom tables; every value goes through $wpdb->prepare() with generated placeholders.

/**
 * All SQL for the wpal_events and wpal_event_changes tables.
 */
final class EventRepository {

	/**
	 * Maximum rows per multi-row INSERT.
	 */
	private const CHUNK = 500;

	/**
	 * Placeholder of each wpal_events column, in Event::to_row() order.
	 */
	private const EVENT_FORMATS = array(
		'created_at'   => '%s',
		'user_id'      => '%d',
		'user_login'   => '%s',
		'user_roles'   => '%s',
		'ip'           => '%s',
		'user_agent'   => '%s',
		'category'     => '%s',
		'event_type'   => '%s',
		'severity'     => '%d',
		'object_type'  => '%s',
		'object_id'    => '%s',
		'object_label' => '%s',
		'message'      => '%s',
		'context'      => '%s',
		'session_hash' => '%s',
	);

	/**
	 * Database connection.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Creates the repository.
	 *
	 * @param \wpdb|null $db Connection (default: the global $wpdb).
	 */
	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;

		$this->db = $db ?? $wpdb;
	}

	/**
	 * Returns one event with its changes.
	 *
	 * @param int $id Event ID.
	 *
	 * @return array<string, mixed>|null Typed event with a "changes" list, or null when missing.
	 */
	public function find( int $id ): ?array {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM ' . Schema::events_table() . ' WHERE id = %d', $id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		$event            = RowHydrator::event( $row );
		$event['changes'] = $this->changes_for( array( $event['id'] ) )[ $event['id'] ] ?? array();

		return $event;
	}

	/**
	 * Returns one page of events matching the filters, plus the total match count.
	 *
	 * Items carry a "change_count" instead of the changes themselves.
	 *
	 * @param array<string, mixed> $filters Filters (see QueryBuilder).
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int} Result.
	 */
	public function query( array $filters ): array {
		$query            = new QueryBuilder( $filters );
		[ $where, $args ] = $query->where();
		$table            = Schema::events_table();

		$rows  = $this->db->get_results( $this->maybe_prepare( "SELECT * FROM $table $where {$query->order_by()} {$query->limit()}", $args ), ARRAY_A );
		$total = (int) $this->db->get_var( $this->maybe_prepare( "SELECT COUNT(*) FROM $table $where", $args ) );
		$items = array_map( array( RowHydrator::class, 'event' ), is_array( $rows ) ? $rows : array() );

		return array(
			'items'    => $this->with_change_counts( $items ),
			'total'    => $total,
			'page'     => $query->page(),
			'per_page' => $query->per_page(),
		);
	}

	/**
	 * Yields every matching event with its changes, newest first, in batches.
	 *
	 * Pages by ID ranges instead of OFFSET, so long exports stay fast.
	 *
	 * @param array<string, mixed> $filters Filters (paging and sorting keys are ignored).
	 * @param int                  $batch   Rows per query.
	 *
	 * @return \Generator<int, array<string, mixed>> Typed events with "changes".
	 */
	public function iterate( array $filters, int $batch = 1000 ): \Generator {
		[ $where, $args ] = ( new QueryBuilder( $filters ) )->where();
		$table            = Schema::events_table();
		$last_id          = PHP_INT_MAX;
		$where            = '' === $where ? 'WHERE id < %d' : $where . ' AND id < %d';
		$batch            = max( 1, $batch );

		do {
			$sql     = "SELECT * FROM $table $where ORDER BY id DESC LIMIT $batch";
			$rows    = $this->db->get_results( $this->db->prepare( $sql, array_merge( $args, array( $last_id ) ) ), ARRAY_A );
			$items   = array_map( array( RowHydrator::class, 'event' ), is_array( $rows ) ? $rows : array() );
			$fetched = count( $items );

			// Yield one by one: "yield from" an array would repeat keys 0..n in every batch.
			foreach ( $this->with_changes( $items ) as $item ) {
				yield $item;
			}

			$last_id = 0 === $fetched ? 0 : (int) end( $items )['id'];
		} while ( $fetched === $batch );
	}

	/**
	 * Returns the newest event of a type with the same label and IP since a given time.
	 *
	 * Used to fold repeated events (e.g. failed logins) into one row.
	 *
	 * @param string $event_type Type.
	 * @param string $label      Object label.
	 * @param string $ip         IP address.
	 * @param string $since      UTC "Y-m-d H:i:s".
	 *
	 * @return array<string, mixed>|null Typed event, or null when none.
	 */
	public function find_recent( string $event_type, string $label, string $ip, string $since ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . Schema::events_table() . ' WHERE event_type = %s AND object_label = %s AND ip = %s AND created_at >= %s ORDER BY id DESC LIMIT 1',
				$event_type,
				$label,
				$ip,
				$since
			),
			ARRAY_A
		);

		return is_array( $row ) ? RowHydrator::event( $row ) : null;
	}

	/**
	 * Updates the context, message and time of an existing event.
	 *
	 * @param int                  $id         Event ID.
	 * @param array<string, mixed> $context    New context.
	 * @param string               $message    New message.
	 * @param string               $created_at New UTC time.
	 *
	 * @return bool True when a row was updated.
	 */
	public function update_summary( int $id, array $context, string $message, string $created_at ): bool {
		$updated = $this->db->update(
			Schema::events_table(),
			array(
				'context'    => (string) wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'message'    => $message,
				'created_at' => $created_at,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return is_int( $updated ) && $updated > 0;
	}

	/**
	 * Deletes every event matching the filters, with its changes.
	 *
	 * No filters means everything. Paging and sorting keys are ignored.
	 *
	 * @param array<string, mixed> $filters Filters (see QueryBuilder).
	 *
	 * @return int Number of events deleted.
	 */
	public function delete_where( array $filters ): int {
		[ $where, $args ] = ( new QueryBuilder( $filters ) )->where();
		$events           = Schema::events_table();
		$changes          = Schema::changes_table();

		// The filter columns only exist in the events table, so they are unambiguous in the join.
		$this->db->query( $this->maybe_prepare( "DELETE c FROM $changes c INNER JOIN $events e ON c.event_id = e.id $where", $args ) );

		return (int) $this->db->query( $this->maybe_prepare( "DELETE FROM $events $where", $args ) );
	}

	/**
	 * Deletes up to $limit events older than a date, oldest first, with their changes.
	 *
	 * @param string $before UTC "Y-m-d H:i:s"; events created before it are deleted.
	 * @param int    $limit  Maximum number of events to delete.
	 *
	 * @return int Number of events deleted.
	 */
	public function delete_older_than( string $before, int $limit ): int {
		$ids = $this->db->get_col(
			$this->db->prepare( 'SELECT id FROM ' . Schema::events_table() . ' WHERE created_at < %s ORDER BY id ASC LIMIT %d', $before, max( 1, $limit ) )
		);

		return $this->delete_ids( array_map( 'intval', $ids ) );
	}

	/**
	 * Deletes the oldest events beyond a maximum row count, up to $limit at a time.
	 *
	 * @param int $max_rows Number of events to keep.
	 * @param int $limit    Maximum number of events to delete now.
	 *
	 * @return int Number of events deleted.
	 */
	public function delete_beyond( int $max_rows, int $limit ): int {
		$excess = $this->count() - max( 0, $max_rows );

		if ( $excess <= 0 ) {
			return 0;
		}

		$ids = $this->db->get_col(
			$this->db->prepare( 'SELECT id FROM ' . Schema::events_table() . ' ORDER BY id ASC LIMIT %d', min( $excess, max( 1, $limit ) ) )
		);

		return $this->delete_ids( array_map( 'intval', $ids ) );
	}

	/**
	 * Anonymises the actor fields of a user's events (privacy erasure): the login becomes
	 * "deleted-user-#ID", IP address and user agent are blanked. The rows are kept.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return int Rows changed.
	 */
	public function anonymize_user( int $user_id ): int {
		return (int) $this->db->query(
			$this->db->prepare(
				'UPDATE ' . Schema::events_table() . " SET user_login = %s, ip = '', user_agent = '' WHERE user_id = %d AND ( user_login <> %s OR ip <> '' OR user_agent <> '' )",
				'deleted-user-#' . $user_id,
				$user_id,
				'deleted-user-#' . $user_id
			)
		);
	}

	/**
	 * Returns the total number of stored events.
	 *
	 * @return int Count.
	 */
	public function count(): int {
		return (int) $this->db->get_var( 'SELECT COUNT(*) FROM ' . Schema::events_table() );
	}

	/**
	 * Deletes change rows whose event no longer exists.
	 *
	 * @return int Rows deleted.
	 */
	public function delete_orphan_changes(): int {
		$events  = Schema::events_table();
		$changes = Schema::changes_table();

		return (int) $this->db->query( "DELETE c FROM $changes c LEFT JOIN $events e ON e.id = c.event_id WHERE e.id IS NULL" );
	}

	/**
	 * Deletes events (and their changes) by ID.
	 *
	 * @param array<int, int> $ids Event IDs.
	 *
	 * @return int Number of events deleted.
	 */
	private function delete_ids( array $ids ): int {
		if ( array() === $ids ) {
			return 0;
		}

		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$this->db->query( $this->db->prepare( 'DELETE FROM ' . Schema::changes_table() . " WHERE event_id IN ($in)", $ids ) );

		return (int) $this->db->query( $this->db->prepare( 'DELETE FROM ' . Schema::events_table() . " WHERE id IN ($in)", $ids ) );
	}

	/**
	 * Loads the changes of several events with one query.
	 *
	 * @param array<int, int> $event_ids Event IDs.
	 *
	 * @return array<int, array<int, array{field: string, old: mixed, new: mixed, truncated: bool}>> Changes by event ID.
	 */
	public function changes_for( array $event_ids ): array {
		if ( array() === $event_ids ) {
			return array();
		}

		$sql  = 'SELECT * FROM ' . Schema::changes_table() . ' WHERE event_id IN (' . implode( ',', array_fill( 0, count( $event_ids ), '%d' ) ) . ') ORDER BY id';
		$rows = $this->db->get_results( $this->db->prepare( $sql, $event_ids ), ARRAY_A );
		$map  = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$map[ (int) $row['event_id'] ][] = RowHydrator::change( $row );
		}

		return $map;
	}

	/**
	 * Adds the full change list to each event.
	 *
	 * @param array<int, array<string, mixed>> $items Typed events.
	 *
	 * @return array<int, array<string, mixed>> Events with "changes".
	 */
	private function with_changes( array $items ): array {
		$changes = $this->changes_for( array_map( static fn( array $i ): int => (int) $i['id'], $items ) );

		foreach ( $items as $index => $item ) {
			$items[ $index ]['changes'] = $changes[ (int) $item['id'] ] ?? array();
		}

		return $items;
	}

	/**
	 * Adds the number of changes to each event with one grouped query.
	 *
	 * @param array<int, array<string, mixed>> $items Typed events.
	 *
	 * @return array<int, array<string, mixed>> Events with "change_count".
	 */
	private function with_change_counts( array $items ): array {
		$ids    = array_map( static fn( array $i ): int => (int) $i['id'], $items );
		$counts = array();

		if ( array() !== $ids ) {
			$sql  = 'SELECT event_id, COUNT(*) AS n FROM ' . Schema::changes_table() . ' WHERE event_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') GROUP BY event_id';
			$rows = $this->db->get_results( $this->db->prepare( $sql, $ids ), ARRAY_A );

			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$counts[ (int) $row['event_id'] ] = (int) $row['n'];
			}
		}

		foreach ( $items as $index => $item ) {
			$items[ $index ]['change_count'] = $counts[ (int) $item['id'] ] ?? 0;
		}

		return $items;
	}

	/**
	 * Prepares a query only when it has arguments (prepare() rejects queries without placeholders).
	 *
	 * @param string                 $sql  SQL.
	 * @param array<int, int|string> $args Arguments.
	 *
	 * @return string SQL ready to run.
	 */
	private function maybe_prepare( string $sql, array $args ): string {
		return array() === $args ? $sql : (string) $this->db->prepare( $sql, $args );
	}

	/**
	 * Stores events and their changes with as few queries as possible, keeping their order.
	 *
	 * Runs of events without changes go in multi-row INSERTs. An event with changes is
	 * inserted on its own at its place in the sequence, because only a single-row INSERT
	 * reliably reports its ID (MySQL doesn't guarantee consecutive IDs for multi-row
	 * INSERTs). All changes then go in multi-row INSERTs.
	 *
	 * @param array<int, Event> $events Events (created_at must be set).
	 *
	 * @return int Number of events stored.
	 */
	public function insert_many( array $events ): int {
		$stored = 0;
		$plain  = array();
		$rows   = array();

		foreach ( $events as $event ) {
			if ( array() === $event->changes ) {
				$plain[] = $event;
				continue;
			}

			$stored  += $this->insert_plain( $plain );
			$plain    = array();
			$event_id = $this->insert_one( $event );

			if ( 0 !== $event_id ) {
				++$stored;
				array_push( $rows, ...array_map( static fn( $change ) => $change->to_row( $event_id ), $event->changes ) );
			}
		}

		$stored += $this->insert_plain( $plain );
		$this->insert_change_rows( $rows );

		return $stored;
	}

	/**
	 * Inserts events without changes in chunks of multi-row INSERTs.
	 *
	 * @param array<int, Event> $events Events.
	 *
	 * @return int Rows inserted.
	 */
	private function insert_plain( array $events ): int {
		$stored = 0;

		foreach ( array_chunk( $events, self::CHUNK ) as $chunk ) {
			$stored += $this->insert_event_rows( array_map( static fn( Event $e ): array => $e->to_row(), $chunk ) );
		}

		return $stored;
	}

	/**
	 * Inserts one event and returns its ID.
	 *
	 * @param Event $event Event.
	 *
	 * @return int New ID, or 0 on failure.
	 */
	private function insert_one( Event $event ): int {
		$inserted = $this->db->insert( Schema::events_table(), $event->to_row(), array_values( self::EVENT_FORMATS ) );

		return false === $inserted ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Runs one multi-row INSERT into wpal_events.
	 *
	 * @param array<int, array<string, int|string>> $rows Rows from Event::to_row().
	 *
	 * @return int Rows inserted.
	 */
	private function insert_event_rows( array $rows ): int {
		if ( array() === $rows ) {
			return 0;
		}

		$tuple  = '(' . implode( ',', self::EVENT_FORMATS ) . ')';
		$values = array();

		foreach ( $rows as $row ) {
			array_push( $values, ...array_values( $row ) );
		}

		$sql = 'INSERT INTO ' . Schema::events_table() . ' (' . implode( ',', array_keys( self::EVENT_FORMATS ) ) . ') VALUES '
			. implode( ',', array_fill( 0, count( $rows ), $tuple ) );

		return (int) $this->db->query( $this->db->prepare( $sql, $values ) );
	}

	/**
	 * Inserts change rows in chunks of multi-row INSERTs. NULL values stay NULL.
	 *
	 * @param array<int, array{event_id: int, field: string, old_value: string|null, new_value: string|null, truncated: int}> $rows Rows.
	 *
	 * @return void
	 */
	private function insert_change_rows( array $rows ): void {
		foreach ( array_chunk( $rows, self::CHUNK ) as $chunk ) {
			$tuples = array();
			$values = array();

			foreach ( $chunk as $row ) {
				$tuples[] = self::change_tuple( $row, $values );
			}

			$sql = 'INSERT INTO ' . Schema::changes_table() . ' (event_id,field,old_value,new_value,truncated) VALUES ' . implode( ',', $tuples );
			$this->db->query( $this->db->prepare( $sql, $values ) );
		}
	}

	/**
	 * Builds one "(...)" tuple for a change row and appends its prepare arguments.
	 *
	 * NULL values are written as the literal NULL, because prepare() turns null into ''.
	 *
	 * @param array{event_id: int, field: string, old_value: string|null, new_value: string|null, truncated: int} $row    Row.
	 * @param array<int, int|string>                                                                              $values Prepare arguments (appended to).
	 *
	 * @return string Tuple with placeholders.
	 */
	private static function change_tuple( array $row, array &$values ): string {
		$placeholders = array( '%d', '%s' );
		array_push( $values, $row['event_id'], $row['field'] );

		foreach ( array( $row['old_value'], $row['new_value'] ) as $value ) {
			$placeholders[] = null === $value ? 'NULL' : '%s';

			if ( null !== $value ) {
				$values[] = $value;
			}
		}

		$placeholders[] = '%d';
		$values[]       = $row['truncated'];

		return '(' . implode( ',', $placeholders ) . ')';
	}
}
