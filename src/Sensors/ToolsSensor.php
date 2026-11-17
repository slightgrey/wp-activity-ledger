<?php
/**
 * Tools sensor: export, import and privacy requests.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Sensors;

use WPActivityLedger\Domain\Event;
use WPActivityLedger\Loader;
use WPActivityLedger\Recording\Recorder;

/**
 * Records Tools → Export / Import runs and personal data export / erase requests.
 */
final class ToolsSensor implements SensorInterface {

	/**
	 * Creates the sensor.
	 *
	 * @param Recorder $recorder Recorder.
	 */
	public function __construct( private readonly Recorder $recorder ) {
	}

	/**
	 * Queues the hooks.
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'export_wp', array( $this, 'on_export' ), 10, 1 );
		$loader->add_action( 'import_end', array( $this, 'on_import' ), 10, 0 );
		$loader->add_action( 'wp_privacy_personal_data_export_file_created', array( $this, 'on_privacy_export' ), 10, 4 );
		$loader->add_action( 'wp_privacy_personal_data_erased', array( $this, 'on_privacy_erase' ), 10, 1 );
	}

	/**
	 * Records a content export (WXR download).
	 *
	 * @param array<string, mixed> $args Export arguments.
	 *
	 * @return void
	 */
	public function on_export( $args = array() ): void {
		$args    = (array) $args;
		$content = sanitize_key( (string) ( $args['content'] ?? 'all' ) );

		$this->recorder->record_now( new Event( 'core.export_run', object_type: 'export', object_label: $content, context: array( 'content' => $content ) ) );
	}

	/**
	 * Records the end of an import.
	 *
	 * @return void
	 */
	public function on_import(): void {
		$this->recorder->record( new Event( 'core.import_run', object_type: 'import' ) );
	}

	/**
	 * Records a personal data export file being generated.
	 *
	 * @param string $archive_pathname Archive path.
	 * @param string $archive_url      Archive URL.
	 * @param string $html_pathname    Report path.
	 * @param int    $request_id       Request ID.
	 *
	 * @return void
	 */
	public function on_privacy_export( $archive_pathname, $archive_url, $html_pathname, $request_id ): void {
		unset( $archive_pathname, $archive_url, $html_pathname );
		$this->record_request( 'core.privacy_export', (int) $request_id );
	}

	/**
	 * Records personal data being erased.
	 *
	 * @param int $request_id Request ID.
	 *
	 * @return void
	 */
	public function on_privacy_erase( $request_id ): void {
		$this->record_request( 'core.privacy_erase', (int) $request_id );
	}

	/**
	 * Records an event about a privacy request, labelled with its email address.
	 *
	 * @param string $type       Event type.
	 * @param int    $request_id Request ID.
	 *
	 * @return void
	 */
	private function record_request( string $type, int $request_id ): void {
		$request = wp_get_user_request( $request_id );
		$email   = $request instanceof \WP_User_Request ? $request->email : '';

		$this->recorder->record( new Event( $type, object_type: 'user_request', object_id: (string) $request_id, object_label: '' === $email ? "#$request_id" : $email ) );
	}
}
