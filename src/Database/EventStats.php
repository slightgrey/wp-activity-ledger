<?php
/**
 * Dashboard aggregates.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Database;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; values are prepared, the table name is ours.

/**
 * Counts events for the dashboard: per day and category, top users and types,
 * failed logins and recent critical events.
 */
final class EventStats {

	/**
	 * Database connection.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Creates the stats query.
	 *
	 * @param \wpdb|null $db Connection (default: the global $wpdb).
	 */
	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;

		$this->db = $db ?? $wpdb;
	}

	/**
	 * Returns every dashboard aggregate for a UTC time range.
	 *
	 * @param string $from           Start, UTC "Y-m-d H:i:s" (inclusive).
	 * @param string $to             End, UTC "Y-m-d H:i:s" (inclusive).
	 * @param int    $offset_seconds Site timezone offset from UTC, used to group days locally.
	 * @param string $bucket         "day" or "hour" for the activity series.
	 * @param bool   $hide_system    Leave out System events (see QueryBuilder::system_condition()).
	 *
	 * @return array<string, mixed> Aggregates.
	 */
	public function summary( string $from, string $to, int $offset_seconds = 0, string $bucket = 'day', bool $hide_system = false ): array {
		return array(
			'totals'          => $this->totals( $from, $to, $hide_system ),
			'by_day'          => $this->by_day( $from, $to, $offset_seconds, $bucket, $hide_system ),
			'top_users'       => $this->top( 'user_id, user_login', $from, $to, $hide_system ),
			'top_types'       => $this->top( 'event_type', $from, $to, $hide_system ),
			'recent_critical' => $this->recent_critical( $from, $to ),
		);
	}

	/**
	 * Returns the headline counts.
	 *
	 * @param string $from        Start (UTC).
	 * @param string $to          End (UTC).
	 * @param bool   $hide_system Leave out System events.
	 *
	 * @return array{events: int, failed_logins: int, critical: int, users: int} Counts.
	 */
	public function totals( string $from, string $to, bool $hide_system = false ): array {
		$table = Schema::events_table();
		$extra = self::extra( $hide_system );
		$row   = $this->db->get_row(
			$this->db->prepare(
				"SELECT COUNT(*) AS events,
					SUM(CASE WHEN event_type = 'auth.login_failed' THEN COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(context, '$.count')) AS UNSIGNED), 1) ELSE 0 END) AS failed_logins,
					SUM(severity >= 3) AS critical,
					COUNT(DISTINCT NULLIF(user_id, 0)) AS users
				FROM $table WHERE created_at BETWEEN %s AND %s$extra",
				$from,
				$to
			),
			ARRAY_A
		);

		return array(
			'events'        => (int) ( $row['events'] ?? 0 ),
			'failed_logins' => (int) ( $row['failed_logins'] ?? 0 ),
			'critical'      => (int) ( $row['critical'] ?? 0 ),
			'users'         => (int) ( $row['users'] ?? 0 ),
		);
	}

	/**
	 * Returns counts per local day and category.
	 *
	 * @param string $from           Start (UTC).
	 * @param string $to             End (UTC).
	 * @param int    $offset_seconds Site timezone offset.
	 * @param string $bucket         "day" ("Y-m-d") or "hour" ("Y-m-d H:00").
	 * @param bool   $hide_system    Leave out System events.
	 *
	 * @return array<int, array{day: string, category: string, count: int}> Rows, oldest day first.
	 */
	public function by_day( string $from, string $to, int $offset_seconds, string $bucket = 'day', bool $hide_system = false ): array {
		$table  = Schema::events_table();
		$extra  = self::extra( $hide_system );
		$format = 'hour' === $bucket ? '%Y-%m-%d %H:00' : '%Y-%m-%d';
		$rows   = $this->db->get_results(
			$this->db->prepare(
				"SELECT DATE_FORMAT(DATE_ADD(created_at, INTERVAL %d SECOND), %s) AS day, category, COUNT(*) AS count
				FROM $table WHERE created_at BETWEEN %s AND %s$extra
				GROUP BY day, category ORDER BY day, category",
				$offset_seconds,
				$format,
				$from,
				$to
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $r ): array => array(
				'day'      => (string) $r['day'],
				'category' => (string) $r['category'],
				'count'    => (int) $r['count'],
			),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Returns the 5 most frequent values of a column (or column pair).
	 *
	 * @param string $columns "event_type" or "user_id, user_login" (fixed, never user input).
	 * @param string $from    Start (UTC).
	 * @param string $to          End (UTC).
	 * @param bool   $hide_system Leave out System events.
	 *
	 * @return array<int, array<string, int|string>> Rows with a "count".
	 */
	private function top( string $columns, string $from, string $to, bool $hide_system ): array {
		$table    = Schema::events_table();
		$extra    = self::extra( $hide_system );
		$group_by = 'event_type' === $columns ? 'event_type' : 'user_id';
		$select   = 'event_type' === $columns ? 'event_type' : 'user_id, MAX(user_login) AS user_login';
		$rows     = $this->db->get_results(
			$this->db->prepare(
				"SELECT $select, COUNT(*) AS count FROM $table WHERE created_at BETWEEN %s AND %s$extra GROUP BY $group_by ORDER BY count DESC LIMIT 5",
				$from,
				$to
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'type_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Returns the 5 most recent critical events.
	 *
	 * @param string $from Start (UTC).
	 * @param string $to   End (UTC).
	 *
	 * @return array<int, array<string, mixed>> Typed events.
	 */
	private function recent_critical( string $from, string $to ): array {
		$table = Schema::events_table();
		$rows  = $this->db->get_results(
			$this->db->prepare( "SELECT * FROM $table WHERE severity >= 3 AND created_at BETWEEN %s AND %s ORDER BY id DESC LIMIT 5", $from, $to ),
			ARRAY_A
		);

		return array_map( array( RowHydrator::class, 'event' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Returns the extra WHERE condition for hidden System events (fixed SQL, no user input).
	 *
	 * @param bool $hide_system Leave out System events.
	 *
	 * @return string SQL starting with " AND ", or ''.
	 */
	private static function extra( bool $hide_system ): string {
		return $hide_system ? ' AND ' . QueryBuilder::system_condition() : '';
	}

	/**
	 * Casts numeric columns of a "top" row.
	 *
	 * @param array<string, mixed> $row Row.
	 *
	 * @return array<string, int|string> Typed row.
	 */
	private static function type_row( array $row ): array {
		$typed = array();

		foreach ( $row as $key => $value ) {
			$typed[ $key ] = in_array( $key, array( 'count', 'user_id' ), true ) ? (int) $value : (string) $value;
		}

		return $typed;
	}
}
