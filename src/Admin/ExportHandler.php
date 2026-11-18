<?php
/**
 * Streamed log export.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Admin;

use WPActivityLedger\Database\EventRepository;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Loader;
use WPActivityLedger\Recording\Recorder;
use WPActivityLedger\Rest\EventsController;
use WPActivityLedger\Security\Capabilities;

/**
 * Handles admin-post.php?action=wpal_export: streams the filtered log as CSV or JSON, reading the
 * events in batches of 1,000 so large exports don't exhaust memory, and records the export.
 *
 * Takes the same filters as GET /events (validated with the same schema). CSV has one row
 * per event with context and changes as JSON columns; cells that a spreadsheet would run
 * as a formula are prefixed with an apostrophe.
 */
final class ExportHandler {

	/**
	 * The admin-post action and nonce action.
	 */
	public const ACTION = 'wpal_export';

	/**
	 * Formats.
	 */
	public const FORMATS = array( 'csv', 'json' );

	/**
	 * CSV columns.
	 */
	private const COLUMNS = array( 'id', 'created_at', 'user_id', 'user_login', 'user_roles', 'ip', 'user_agent', 'category', 'event_type', 'severity', 'object_type', 'object_id', 'object_label', 'message', 'session_hash', 'context', 'changes' );

	/**
	 * Filters that are not passed to the query (paging is the export's own).
	 */
	private const NOT_FILTERS = array( 'page', 'per_page' );

	/**
	 * Creates the handler.
	 *
	 * @param EventRepository $repository Event storage.
	 * @param Recorder        $recorder   Recorder.
	 */
	public function __construct(
		private readonly EventRepository $repository,
		private readonly Recorder $recorder
	) {
	}

	/**
	 * Queues the hook.
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ), 10, 0 );
	}

	/**
	 * Returns the export URL for the given filters and format.
	 *
	 * @param string $format "csv" or "json".
	 *
	 * @return string URL with a nonce.
	 */
	public static function url( string $format ): string {
		return wp_nonce_url( add_query_arg( 'format', $format, admin_url( 'admin-post.php?action=' . self::ACTION ) ), self::ACTION );
	}

	/**
	 * Handles the request: checks the nonce and capability, then streams the file.
	 *
	 * @return void
	 */
	public function handle(): void {
		check_admin_referer( self::ACTION );

		if ( ! current_user_can( Capabilities::EXPORT ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Plugin capability.
			wp_die( esc_html__( 'Sorry, you are not allowed to export the activity log.', 'wp-activity-ledger' ), 403 );
		}

		$format  = isset( $_GET['format'] ) && 'json' === $_GET['format'] ? 'json' : 'csv'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Compared to a constant.
		$filters = self::filters( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated against the schema below.

		if ( $filters instanceof \WP_Error ) {
			wp_die( esc_html( $filters->get_error_message() ), 400 );
		}

		self::send_headers( $format );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming the response.

		if ( false !== $out ) {
			$this->export( $filters, $format, $out );
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		exit;
	}

	/**
	 * Writes the export and records it.
	 *
	 * @param array<string, mixed> $filters Query filters.
	 * @param string               $format  "csv" or "json".
	 * @param resource             $out     Output stream.
	 *
	 * @return int Events written.
	 */
	public function export( array $filters, string $format, $out ): int {
		$count = 'json' === $format ? $this->write_json( $filters, $out ) : $this->write_csv( $filters, $out );

		$this->recorder->record_now(
			new Event(
				'system.logs_exported',
				object_type: 'export',
				object_label: strtoupper( $format ),
				context: array(
					'count'   => $count,
					'format'  => strtoupper( $format ),
					'filters' => $filters,
				)
			)
		);

		return $count;
	}

	/**
	 * Validates and sanitizes export filters with the GET /events schema.
	 *
	 * @param array<string, mixed> $input Query parameters.
	 *
	 * @return array<string, mixed>|\WP_Error Filters, or an error for an invalid one.
	 */
	public static function filters( array $input ): array|\WP_Error {
		$filters = array();

		foreach ( EventsController::filter_args() as $key => $schema ) {
			if ( in_array( $key, self::NOT_FILTERS, true ) || ! isset( $input[ $key ] ) || '' === $input[ $key ] ) {
				continue;
			}

			$value = 'array' === $schema['type'] && is_string( $input[ $key ] ) ? wp_parse_list( $input[ $key ] ) : $input[ $key ];
			$valid = rest_validate_value_from_schema( $value, $schema, $key );

			if ( is_wp_error( $valid ) ) {
				return $valid;
			}

			$filters[ $key ] = rest_sanitize_value_from_schema( $value, $schema, $key );
		}

		return $filters;
	}

	/**
	 * Sends the download headers.
	 *
	 * @param string $format "csv" or "json".
	 *
	 * @return void
	 */
	private static function send_headers( string $format ): void {
		$name = 'activity-log-' . gmdate( 'Y-m-d-His' ) . '.' . $format;

		nocache_headers();
		header( 'Content-Type: ' . ( 'json' === $format ? 'application/json' : 'text/csv' ) . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
	}

	/**
	 * Streams CSV rows.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param resource             $out     Output stream.
	 *
	 * @return int Rows written.
	 */
	private function write_csv( array $filters, $out ): int {
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM so spreadsheets read accents.
		fputcsv( $out, self::COLUMNS, ',', '"', '' );
		$count = 0;

		foreach ( $this->repository->iterate( $filters, 1000 ) as $event ) {
			fputcsv( $out, self::csv_row( $event ), ',', '"', '' );
			self::maybe_flush( ++$count );
		}

		return $count;
	}

	/**
	 * Streams a JSON array of events (with nested changes).
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param resource             $out     Output stream.
	 *
	 * @return int Events written.
	 */
	private function write_json( array $filters, $out ): int {
		$count = 0;

		fwrite( $out, '[' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming.

		foreach ( $this->repository->iterate( $filters, 1000 ) as $event ) {
			fwrite( $out, ( 0 === $count ? "\n" : ",\n" ) . (string) wp_json_encode( $event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			self::maybe_flush( ++$count );
		}

		fwrite( $out, "\n]\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		return $count;
	}

	/**
	 * Builds one CSV row.
	 *
	 * @param array<string, mixed> $event Event with changes.
	 *
	 * @return array<int, string> Cells.
	 */
	private static function csv_row( array $event ): array {
		$cells = array();

		foreach ( self::COLUMNS as $column ) {
			$value = $event[ $column ] ?? '';

			if ( 'user_roles' === $column ) {
				$value = implode( ',', (array) $value );
			} elseif ( is_array( $value ) ) {
				$value = (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}

			$cells[] = self::safe_cell( (string) $value );
		}

		return $cells;
	}

	/**
	 * Neutralises spreadsheet formulas (cells starting with = + - @ or a control character).
	 *
	 * @param string $value Cell.
	 *
	 * @return string Safe cell.
	 */
	public static function safe_cell( string $value ): string {
		return '' !== $value && 1 === preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	/**
	 * Pushes output to the client every 1,000 rows.
	 *
	 * @param int $count Rows written.
	 *
	 * @return void
	 */
	private static function maybe_flush( int $count ): void {
		if ( 0 === $count % 1000 && ! defined( 'WPAL_EXPORT_NO_FLUSH' ) ) {
			flush();
		}
	}
}
