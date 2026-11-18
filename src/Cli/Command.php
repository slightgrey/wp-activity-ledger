<?php
/**
 * WP-CLI commands.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Cli;

use WPActivityLedger\Admin\ExportHandler;
use WPActivityLedger\Database\EventStats;
use WPActivityLedger\Maintenance\Pruner;
use WPActivityLedger\Maintenance\Purger;
use WPActivityLedger\Plugin;
use WP_CLI;
use WP_CLI\Utils;

// phpcs:disable WordPress.WP.AlternativeFunctions -- CLI output files.

/**
 * Reads, exports and maintains the activity log.
 *
 * ## EXAMPLES
 *
 *     wp activity-ledger list --type=auth.login_failed --limit=10
 *     wp activity-ledger show 123
 *     wp activity-ledger export --format=csv --file=log.csv --from=2026-01-01
 *     wp activity-ledger purge --before=2026-01-01 --yes
 *     wp activity-ledger alerts test <channel-id>
 *
 * On multisite, use --url to choose the site.
 */
final class Command {

	/**
	 * Default columns of `list`.
	 */
	private const LIST_FIELDS = 'id,created_at,user_login,event_type,object_label,message';

	/**
	 * Registers the commands (when WP-CLI is running).
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command( 'activity-ledger', self::class );
		WP_CLI::add_command( 'activity-ledger alerts', AlertsCommand::class );
	}

	/**
	 * Lists events, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--user=<id>]
	 * : Acting user ID.
	 *
	 * [--type=<types>]
	 * : Event types, comma-separated (e.g. post.updated,auth.login).
	 *
	 * [--category=<categories>]
	 * : Categories, comma-separated.
	 *
	 * [--severity=<level>]
	 * : Minimum severity: notice, warning or critical.
	 *
	 * [--from=<date>]
	 * : Start, UTC ("Y-m-d" or "Y-m-d H:i:s").
	 *
	 * [--to=<date>]
	 * : End, UTC.
	 *
	 * [--search=<text>]
	 * : Text in the message or object.
	 *
	 * [--limit=<n>]
	 * : Most events to show.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Columns to show.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 *
	 * @return void
	 */
	public function list_( array $args, array $assoc_args ): void {
		unset( $args );
		$filters = self::filters( $assoc_args );
		$limit   = max( 1, (int) ( $assoc_args['limit'] ?? 20 ) );
		$items   = array();

		foreach ( Plugin::instance()->repository()->iterate( $filters, min( $limit, 1000 ) ) as $event ) {
			$event['user_roles'] = implode( ',', $event['user_roles'] );
			$event['changes']    = count( $event['changes'] );
			$items[]             = $event;

			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		Utils\format_items( $assoc_args['format'] ?? 'table', $items, $assoc_args['fields'] ?? self::LIST_FIELDS );
	}

	/**
	 * Shows one event with its changes.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Event ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 *
	 * @return void
	 */
	public function show( array $args, array $assoc_args ): void {
		$event = Plugin::instance()->repository()->find( (int) ( $args[0] ?? 0 ) );

		if ( null === $event ) {
			WP_CLI::error( 'Event not found.' );
		}

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $event, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

			return;
		}

		$rows = array();

		foreach ( array( 'id', 'created_at', 'user_login', 'user_roles', 'ip', 'category', 'event_type', 'severity', 'object_type', 'object_id', 'object_label', 'message', 'session_hash' ) as $key ) {
			$rows[] = array(
				'field' => $key,
				'value' => is_array( $event[ $key ] ) ? implode( ',', $event[ $key ] ) : (string) $event[ $key ],
			);
		}

		Utils\format_items( 'table', $rows, array( 'field', 'value' ) );

		if ( array() === $event['changes'] ) {
			WP_CLI::line( 'No field changes.' );

			return;
		}

		$changes = array_map(
			static fn( array $change ): array => array(
				'field' => $change['field'],
				'old'   => self::scalar( $change['old'] ),
				'new'   => self::scalar( $change['new'] ),
			),
			$event['changes']
		);

		WP_CLI::line( '' );
		Utils\format_items( 'table', $changes, array( 'field', 'old', 'new' ) );
	}

	/**
	 * Shows totals and the top users and event types for a period.
	 *
	 * ## OPTIONS
	 *
	 * [--from=<date>]
	 * : Start, UTC. Default: 7 days ago.
	 *
	 * [--to=<date>]
	 * : End, UTC. Default: now.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 *
	 * @return void
	 */
	public function stats( array $args, array $assoc_args ): void {
		unset( $args );
		$from    = self::datetime( $assoc_args['from'] ?? gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ), '00:00:00' );
		$to      = self::datetime( $assoc_args['to'] ?? gmdate( 'Y-m-d H:i:s' ), '23:59:59' );
		$summary = ( new EventStats() )->summary( $from, $to );

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $summary, JSON_PRETTY_PRINT ) );

			return;
		}

		WP_CLI::line( "Events from $from to $to (UTC):" );
		Utils\format_items( 'table', array( $summary['totals'] ), array_keys( $summary['totals'] ) );
		WP_CLI::line( '' );
		Utils\format_items( 'table', $summary['top_types'], array( 'event_type', 'count' ) );
		WP_CLI::line( '' );
		Utils\format_items( 'table', $summary['top_users'], array( 'user_id', 'user_login', 'count' ) );
	}

	/**
	 * Exports events as CSV or JSON (streamed, any size).
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : File format.
	 * ---
	 * default: csv
	 * options:
	 *   - csv
	 *   - json
	 * ---
	 *
	 * [--file=<path>]
	 * : Write to this file instead of the screen.
	 *
	 * [--user=<id>]
	 * : Acting user ID.
	 *
	 * [--type=<types>]
	 * : Event types, comma-separated.
	 *
	 * [--category=<categories>]
	 * : Categories, comma-separated.
	 *
	 * [--from=<date>]
	 * : Start, UTC.
	 *
	 * [--to=<date>]
	 * : End, UTC.
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 *
	 * @return void
	 */
	public function export( array $args, array $assoc_args ): void {
		unset( $args );
		$format = 'json' === ( $assoc_args['format'] ?? 'csv' ) ? 'json' : 'csv';
		$file   = $assoc_args['file'] ?? '';
		$out    = '' === $file ? STDOUT : fopen( $file, 'w' );

		if ( false === $out ) {
			WP_CLI::error( "Can't write to $file." );
		}

		$plugin = Plugin::instance();
		$count  = ( new ExportHandler( $plugin->repository(), $plugin->recorder() ) )->export( self::filters( $assoc_args ), $format, $out );

		if ( '' !== $file ) {
			fclose( $out );
			WP_CLI::success( "Exported $count events to $file." );
		}
	}

	/**
	 * Deletes events. The purge itself is recorded.
	 *
	 * ## OPTIONS
	 *
	 * [--before=<date>]
	 * : Delete events before this day (Y-m-d, site timezone).
	 *
	 * [--all]
	 * : Delete every event.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 *
	 * @param array<int, string>         $args       Positional arguments.
	 * @param array<string, string|bool> $assoc_args Options.
	 *
	 * @return void
	 */
	public function purge( array $args, array $assoc_args ): void {
		unset( $args );
		$before = (string) ( $assoc_args['before'] ?? '' );
		$all    = ! empty( $assoc_args['all'] );

		if ( '' === $before && ! $all ) {
			WP_CLI::error( 'Use --before=<Y-m-d> or --all.' );
		}

		if ( '' !== $before && 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $before ) ) {
			WP_CLI::error( 'Use --before=<Y-m-d>.' );
		}

		WP_CLI::confirm( $all ? 'Delete ALL logged events?' : "Delete events before $before?", $assoc_args );

		$plugin  = Plugin::instance();
		$deleted = ( new Purger( $plugin->repository(), $plugin->recorder() ) )->purge( $all ? array() : Purger::before( $before ), $all ? 'all' : 'before' );

		WP_CLI::success( "Deleted $deleted events." );
	}

	/**
	 * Applies the retention settings now (all batches).
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 *
	 * @return void
	 */
	public function prune( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		$plugin = Plugin::instance();
		$pruner = new Pruner( $plugin->repository(), $plugin->settings(), $plugin->recorder() );
		$total  = 0;

		do {
			$result = $pruner->run();
			$total += $result['expired'] + $result['excess'];
		} while ( $result['more'] );

		wp_unschedule_hook( Pruner::CONTINUE_HOOK );
		WP_CLI::success( "Pruned $total events." );
	}

	/**
	 * Turns CLI options into query filters.
	 *
	 * @param array<string, mixed> $assoc_args Options.
	 *
	 * @return array<string, mixed> Filters.
	 */
	private static function filters( array $assoc_args ): array {
		$filters = array();

		foreach ( array( 'user', 'type', 'category' ) as $list ) {
			if ( isset( $assoc_args[ $list ] ) ) {
				$filters[ $list ] = array_filter( array_map( 'trim', explode( ',', (string) $assoc_args[ $list ] ) ) );
			}
		}

		foreach ( array( 'severity', 'search' ) as $key ) {
			if ( isset( $assoc_args[ $key ] ) ) {
				$filters[ $key ] = (string) $assoc_args[ $key ];
			}
		}

		if ( isset( $assoc_args['from'] ) ) {
			$filters['from'] = self::datetime( (string) $assoc_args['from'], '00:00:00' );
		}

		if ( isset( $assoc_args['to'] ) ) {
			$filters['to'] = self::datetime( (string) $assoc_args['to'], '23:59:59' );
		}

		return $filters;
	}

	/**
	 * Completes a date to "Y-m-d H:i:s".
	 *
	 * @param string $value        Date or datetime.
	 * @param string $default_time Time for bare dates.
	 *
	 * @return string Datetime.
	 */
	private static function datetime( string $value, string $default_time ): string {
		return 10 === strlen( $value ) ? $value . ' ' . $default_time : $value;
	}

	/**
	 * Shows a change value on one line.
	 *
	 * @param mixed $value Value.
	 *
	 * @return string Text.
	 */
	private static function scalar( mixed $value ): string {
		$text = is_scalar( $value ) || null === $value ? (string) $value : (string) wp_json_encode( $value );

		return mb_strlen( $text ) > 80 ? mb_substr( $text, 0, 77 ) . '...' : $text;
	}
}
