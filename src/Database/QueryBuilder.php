<?php
/**
 * Turns log filters into SQL clauses.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Database;

use WPActivityLedger\Domain\EventType;
use WPActivityLedger\Domain\Severity;

/**
 * Builds the WHERE, ORDER BY and LIMIT clauses of an event query from a filter array.
 *
 * Every value becomes a $wpdb->prepare() placeholder; invalid filter values are
 * dropped rather than rejected, so a bad URL parameter just widens the result.
 *
 * Filters: from, to (UTC "Y-m-d" or "Y-m-d H:i:s"), user (ID or IDs), role, category
 * (slug or slugs), type (key or keys), severity (minimum, int or slug), object_type,
 * object_id, ip, session (hash), search, hide_system, orderby, order, page, per_page (max 100).
 */
final class QueryBuilder {

	/**
	 * Columns the result may be sorted by.
	 */
	public const ORDERBY = array( 'created_at', 'id', 'user_login', 'event_type', 'severity', 'category', 'ip', 'object_label' );

	/**
	 * Largest allowed page size.
	 */
	public const MAX_PER_PAGE = 100;

	/**
	 * WHERE conditions with placeholders.
	 *
	 * @var array<int, string>
	 */
	private array $conditions = array();

	/**
	 * Placeholder arguments in condition order.
	 *
	 * @var array<int, int|string>
	 */
	private array $args = array();

	/**
	 * User IDs of the user filter.
	 *
	 * @var array<int, int>
	 */
	private array $user_ids = array();

	/**
	 * Creates the builder and parses the filters.
	 *
	 * @param array<string, mixed> $filters Filters.
	 */
	public function __construct( private readonly array $filters ) {
		$this->add_dates();
		$this->add_users();
		$this->add_role();
		$this->add_in( 'category', self::slugs( $filters['category'] ?? null, '/^[a-z0-9_]{1,32}$/' ) );
		$this->add_in( 'event_type', self::slugs( $filters['type'] ?? null, null ) );
		$this->add_severity();
		$this->add_equals( 'object_type', $filters['object_type'] ?? null, 32 );
		$this->add_equals( 'object_id', $filters['object_id'] ?? null, 64 );
		$this->add_equals( 'ip', $filters['ip'] ?? null, 45 );
		$this->add_equals( 'session_hash', $filters['session'] ?? null, 16 );
		$this->add_search();
		$this->add_hide_system();
	}

	/**
	 * Returns the condition that drops every System event (user 0), failed logins included.
	 *
	 * @return string SQL without placeholders.
	 */
	public static function system_condition(): string {
		return 'user_id <> 0';
	}

	/**
	 * Returns the WHERE clause ('' when unfiltered) and its arguments.
	 *
	 * @return array{0: string, 1: array<int, int|string>} [SQL, args].
	 */
	public function where(): array {
		if ( array() === $this->conditions ) {
			return array( '', array() );
		}

		return array( 'WHERE ' . implode( ' AND ', $this->conditions ), $this->args );
	}

	/**
	 * Returns the ORDER BY clause (whitelisted column, id as a tie-breaker).
	 *
	 * @return string SQL.
	 */
	public function order_by(): string {
		$column    = in_array( $this->filters['orderby'] ?? null, self::ORDERBY, true ) ? (string) $this->filters['orderby'] : 'created_at';
		$direction = 'asc' === strtolower( (string) ( $this->filters['order'] ?? '' ) ) ? 'ASC' : 'DESC';

		return 'id' === $column ? "ORDER BY id $direction" : "ORDER BY $column $direction, id $direction";
	}

	/**
	 * Returns the 1-based page number.
	 *
	 * @return int Page.
	 */
	public function page(): int {
		return max( 1, (int) ( $this->filters['page'] ?? 1 ) );
	}

	/**
	 * Returns the page size (1 to MAX_PER_PAGE, default 25).
	 *
	 * @return int Rows per page.
	 */
	public function per_page(): int {
		$per_page = (int) ( $this->filters['per_page'] ?? 25 );

		return min( self::MAX_PER_PAGE, max( 1, $per_page ) );
	}

	/**
	 * Returns the LIMIT clause for the current page (integers only, no placeholders needed).
	 *
	 * @return string SQL.
	 */
	public function limit(): string {
		return sprintf( 'LIMIT %d OFFSET %d', $this->per_page(), ( $this->page() - 1 ) * $this->per_page() );
	}

	/**
	 * Escapes LIKE wildcards like wpdb::esc_like().
	 *
	 * @param string $text Text.
	 *
	 * @return string Escaped text.
	 */
	public static function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * Adds the from/to date range.
	 *
	 * @return void
	 */
	private function add_dates(): void {
		$from = self::datetime( $this->filters['from'] ?? null, '00:00:00' );
		$to   = self::datetime( $this->filters['to'] ?? null, '23:59:59' );

		if ( null !== $from ) {
			$this->add( 'created_at >= %s', $from );
		}

		if ( null !== $to ) {
			$this->add( 'created_at <= %s', $to );
		}
	}

	/**
	 * Adds the user ID filter (one ID or a list).
	 *
	 * @return void
	 */
	private function add_users(): void {
		$raw = $this->filters['user'] ?? null;
		$ids = array();

		foreach ( is_array( $raw ) ? $raw : array( $raw ) as $value ) {
			if ( is_numeric( $value ) && (int) $value >= 0 ) {
				$ids[ (int) $value ] = (int) $value;
			}
		}

		if ( array() === $ids ) {
			return;
		}

		$this->user_ids     = $ids;
		$this->conditions[] = 'user_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
		array_push( $this->args, ...array_values( $ids ) );
	}

	/**
	 * Hides System events, unless the user filter asks for System (user 0).
	 *
	 * @return void
	 */
	private function add_hide_system(): void {
		$raw = $this->filters['hide_system'] ?? false;
		$on  = true === $raw || 1 === $raw || ( is_string( $raw ) && in_array( strtolower( $raw ), array( '1', 'true' ), true ) );

		if ( $on && ! isset( $this->user_ids[0] ) ) {
			$this->conditions[] = self::system_condition();
		}
	}

	/**
	 * Adds the role filter (matches one role in the comma-separated snapshot).
	 *
	 * @return void
	 */
	private function add_role(): void {
		$role = $this->filters['role'] ?? null;

		if ( is_string( $role ) && 1 === preg_match( '/^[a-z0-9_-]{1,60}$/', $role ) ) {
			$this->add( 'FIND_IN_SET(%s, user_roles) > 0', $role );
		}
	}

	/**
	 * Adds the minimum severity filter.
	 *
	 * @return void
	 */
	private function add_severity(): void {
		$raw      = $this->filters['severity'] ?? null;
		$severity = is_numeric( $raw ) ? Severity::tryFrom( (int) $raw ) : ( is_string( $raw ) ? Severity::from_slug( $raw ) : null );

		if ( null !== $severity && Severity::Info !== $severity ) {
			$this->add( 'severity >= %d', $severity->value );
		}
	}

	/**
	 * Adds the free-text search over message and object label.
	 *
	 * @return void
	 */
	private function add_search(): void {
		$search = $this->filters['search'] ?? null;

		if ( ! is_string( $search ) || '' === trim( $search ) ) {
			return;
		}

		$like               = '%' . self::esc_like( mb_substr( trim( $search ), 0, 100 ) ) . '%';
		$this->conditions[] = '(message LIKE %s OR object_label LIKE %s)';
		array_push( $this->args, $like, $like );
	}

	/**
	 * Adds an "IN (...)" condition for a list of strings.
	 *
	 * @param string             $column Column.
	 * @param array<int, string> $values Values (none = no condition).
	 *
	 * @return void
	 */
	private function add_in( string $column, array $values ): void {
		if ( array() === $values ) {
			return;
		}

		$this->conditions[] = $column . ' IN (' . implode( ',', array_fill( 0, count( $values ), '%s' ) ) . ')';
		array_push( $this->args, ...$values );
	}

	/**
	 * Adds an equality condition for a non-empty string.
	 *
	 * @param string $column     Column.
	 * @param mixed  $value      Value.
	 * @param int    $max_length Longest valid value.
	 *
	 * @return void
	 */
	private function add_equals( string $column, mixed $value, int $max_length ): void {
		if ( is_int( $value ) ) {
			$value = (string) $value;
		}

		if ( is_string( $value ) && '' !== $value && strlen( $value ) <= $max_length ) {
			$this->add( $column . ' = %s', $value );
		}
	}

	/**
	 * Adds one condition with one argument.
	 *
	 * @param string     $condition Condition with one placeholder.
	 * @param int|string $arg       Argument.
	 *
	 * @return void
	 */
	private function add( string $condition, int|string $arg ): void {
		$this->conditions[] = $condition;
		$this->args[]       = $arg;
	}

	/**
	 * Normalises a list of slugs or type keys, dropping invalid ones.
	 *
	 * @param mixed       $raw     One value, a comma-separated string or an array.
	 * @param string|null $pattern Validation regex, or null to validate as event type keys.
	 *
	 * @return array<int, string> Valid unique values.
	 */
	private static function slugs( mixed $raw, ?string $pattern ): array {
		$values = is_array( $raw ) ? $raw : ( is_string( $raw ) ? explode( ',', $raw ) : array() );
		$valid  = array();

		foreach ( $values as $value ) {
			$value = is_string( $value ) ? trim( $value ) : '';
			$ok    = null === $pattern ? EventType::is_valid_key( $value ) : 1 === preg_match( $pattern, $value );

			if ( $ok ) {
				$valid[ $value ] = $value;
			}
		}

		return array_values( $valid );
	}

	/**
	 * Validates a date or datetime string, adding a default time to bare dates.
	 *
	 * @param mixed  $raw          Value.
	 * @param string $default_time Time for "Y-m-d" values.
	 *
	 * @return string|null "Y-m-d H:i:s", or null when invalid.
	 */
	private static function datetime( mixed $raw, string $default_time ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}

		$raw = trim( str_replace( 'T', ' ', $raw ) );

		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
			$raw .= ' ' . $default_time;
		}

		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $raw, new \DateTimeZone( 'UTC' ) );

		return false !== $date && $date->format( 'Y-m-d H:i:s' ) === $raw ? $raw : null;
	}
}
