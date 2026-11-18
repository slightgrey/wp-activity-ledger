<?php
/**
 * Per-user activity aggregates.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Database;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; values are prepared, the table name is ours.

/**
 * Summarises the activity of a set of users: last activity and last login (all time), and
 * event and failed-login counts within a range.
 *
 * Failed logins are recorded anonymously about the targeted account (object_type "user"),
 * and repeated attempts are folded into one row with a "count" in the context.
 */
final class UserActivity {

	/**
	 * Database connection.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Creates the query.
	 *
	 * @param \wpdb|null $db Connection (default: the global $wpdb).
	 */
	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;

		$this->db = $db ?? $wpdb;
	}

	/**
	 * Returns the activity of each user.
	 *
	 * @param array<int, int> $user_ids User IDs.
	 * @param string          $from     Range start, UTC "Y-m-d H:i:s".
	 * @param string          $to       Range end, UTC "Y-m-d H:i:s".
	 *
	 * @return array<int, array{last_activity: string|null, last_login: string|null, events: int, failed_logins: int}> By user ID.
	 */
	public function for_users( array $user_ids, string $from, string $to ): array {
		$user_ids = array_values( array_filter( array_map( 'intval', $user_ids ), static fn( int $id ): bool => $id > 0 ) );
		$result   = array();

		foreach ( $user_ids as $id ) {
			$result[ $id ] = array(
				'last_activity' => null,
				'last_login'    => null,
				'events'        => 0,
				'failed_logins' => 0,
			);
		}

		if ( array() === $user_ids ) {
			return $result;
		}

		foreach ( $this->activity_rows( $user_ids, $from, $to ) as $row ) {
			$id                             = (int) $row['user_id'];
			$result[ $id ]['last_activity'] = $row['last_activity'];
			$result[ $id ]['last_login']    = $row['last_login'];
			$result[ $id ]['events']        = (int) $row['events'];
		}

		foreach ( $this->failed_rows( $user_ids, $from, $to ) as $row ) {
			$result[ (int) $row['object_id'] ]['failed_logins'] = (int) $row['failed'];
		}

		return $result;
	}

	/**
	 * Aggregates the events done by the users.
	 *
	 * @param array<int, int> $user_ids User IDs.
	 * @param string          $from     Range start (UTC).
	 * @param string          $to       Range end (UTC).
	 *
	 * @return array<int, array<string, string|null>> Rows.
	 */
	private function activity_rows( array $user_ids, string $from, string $to ): array {
		$table = Schema::events_table();
		$in    = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		$rows  = $this->db->get_results(
			$this->db->prepare(
				"SELECT user_id, MAX(created_at) AS last_activity,
					MAX(CASE WHEN event_type = 'auth.login' THEN created_at END) AS last_login,
					SUM(created_at BETWEEN %s AND %s) AS events
				FROM $table WHERE user_id IN ($in) GROUP BY user_id",
				array_merge( array( $from, $to ), $user_ids )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Sums the failed logins against the users' accounts in the range.
	 *
	 * @param array<int, int> $user_ids User IDs.
	 * @param string          $from     Range start (UTC).
	 * @param string          $to       Range end (UTC).
	 *
	 * @return array<int, array<string, string>> Rows (object_id, failed).
	 */
	private function failed_rows( array $user_ids, string $from, string $to ): array {
		$table = Schema::events_table();
		$in    = implode( ',', array_fill( 0, count( $user_ids ), '%s' ) );
		$rows  = $this->db->get_results(
			$this->db->prepare(
				"SELECT object_id, SUM(COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(context, '$.count')) AS UNSIGNED), 1)) AS failed
				FROM $table
				WHERE event_type = 'auth.login_failed' AND object_type = 'user' AND object_id IN ($in) AND created_at BETWEEN %s AND %s
				GROUP BY object_id",
				array_merge( array_map( 'strval', $user_ids ), array( $from, $to ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
