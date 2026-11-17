<?php
/**
 * Plugin management sensor.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Sensors;

use WPActivityLedger\Domain\Event;
use WPActivityLedger\Loader;
use WPActivityLedger\Recording\Recorder;

/**
 * Records plugin activation, deactivation and deletion.
 *
 * WP Activity Ledger's own deactivation is left to the self sensor (system.deactivated).
 */
final class PluginSensor implements SensorInterface {

	/**
	 * Plugin details captured before deletion: plugin file => [name, version].
	 *
	 * @var array<string, array{name: string, version: string}>
	 */
	private array $deleting = array();

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
		$loader->add_action( 'activated_plugin', array( $this, 'on_activated' ), 10, 2 );
		$loader->add_action( 'deactivated_plugin', array( $this, 'on_deactivated' ), 10, 2 );
		$loader->add_action( 'delete_plugin', array( $this, 'before_delete' ), 10, 1 );
		$loader->add_action( 'deleted_plugin', array( $this, 'on_deleted' ), 10, 2 );
	}

	/**
	 * Records an activation (except this plugin's own; the Activator logs system.activated).
	 *
	 * @param string $plugin       Plugin file, e.g. "akismet/akismet.php".
	 * @param bool   $network_wide Whether activated network-wide.
	 *
	 * @return void
	 */
	public function on_activated( $plugin, $network_wide = false ): void {
		if ( self::is_self( (string) $plugin ) ) {
			return;
		}

		$this->record( 'plugin.activated', (string) $plugin, self::details( (string) $plugin ), array( 'network_wide' => (bool) $network_wide ) );
	}

	/**
	 * Records a deactivation (except this plugin's own).
	 *
	 * @param string $plugin               Plugin file.
	 * @param bool   $network_deactivating Whether deactivated network-wide.
	 *
	 * @return void
	 */
	public function on_deactivated( $plugin, $network_deactivating = false ): void {
		if ( self::is_self( (string) $plugin ) ) {
			return;
		}

		$this->record( 'plugin.deactivated', (string) $plugin, self::details( (string) $plugin ), array( 'network_wide' => (bool) $network_deactivating ) );
	}

	/**
	 * Remembers a plugin's name and version before its files are removed.
	 *
	 * @param string $plugin Plugin file.
	 *
	 * @return void
	 */
	public function before_delete( $plugin ): void {
		$this->deleting[ (string) $plugin ] = self::details( (string) $plugin );
	}

	/**
	 * Records a deletion when it succeeded.
	 *
	 * @param string $plugin  Plugin file.
	 * @param bool   $deleted Whether the files were deleted.
	 *
	 * @return void
	 */
	public function on_deleted( $plugin, $deleted = true ): void {
		$details = $this->deleting[ (string) $plugin ] ?? self::details( (string) $plugin );

		unset( $this->deleting[ (string) $plugin ] );

		if ( $deleted ) {
			$this->record( 'plugin.deleted', (string) $plugin, $details );
		}
	}

	/**
	 * Records a plugin event.
	 *
	 * @param string                               $type    Event type.
	 * @param string                               $plugin  Plugin file.
	 * @param array{name: string, version: string} $details Name and version.
	 * @param array<string, mixed>                 $context Extra context.
	 *
	 * @return void
	 */
	private function record( string $type, string $plugin, array $details, array $context = array() ): void {
		$this->recorder->record(
			new Event(
				$type,
				object_type: 'plugin',
				object_id: $plugin,
				object_label: $details['name'],
				context: array_merge( array( 'version' => $details['version'] ), $context )
			)
		);
	}

	/**
	 * Reads a plugin's name and version from its header (falls back to the file name).
	 *
	 * @param string $plugin Plugin file.
	 *
	 * @return array{name: string, version: string} Details.
	 */
	public static function details( string $plugin ): array {
		$file = WP_PLUGIN_DIR . '/' . $plugin;

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data = validate_file( $plugin ) === 0 && is_file( $file ) ? get_plugin_data( $file, false, false ) : array();
		$name = trim( (string) ( $data['Name'] ?? '' ) );

		return array(
			'name'    => '' === $name ? $plugin : $name,
			'version' => (string) ( $data['Version'] ?? '' ),
		);
	}

	/**
	 * Tells whether a plugin file is WP Activity Ledger itself.
	 *
	 * @param string $plugin Plugin file.
	 *
	 * @return bool True for this plugin.
	 */
	private static function is_self( string $plugin ): bool {
		return defined( 'WPAL_FILE' ) && plugin_basename( WPAL_FILE ) === $plugin;
	}
}
