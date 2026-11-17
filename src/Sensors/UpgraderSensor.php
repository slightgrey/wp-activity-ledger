<?php
/**
 * Install and update sensor (plugins, themes, core).
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Sensors;

use WPActivityLedger\Domain\Event;
use WPActivityLedger\Loader;
use WPActivityLedger\Recording\Recorder;

/**
 * Records plugin/theme installs and updates (from → to versions) and core updates.
 *
 * "From" versions are captured on upgrader_pre_install, which runs for each item before
 * its files are replaced (bulk updates included).
 */
final class UpgraderSensor implements SensorInterface {

	/**
	 * Versions before an update: "plugin:file" or "theme:slug" => version.
	 *
	 * @var array<string, string>
	 */
	private array $old_versions = array();

	/**
	 * WordPress version when the request started.
	 *
	 * @var string
	 */
	private string $initial_wp_version;

	/**
	 * Creates the sensor.
	 *
	 * @param Recorder $recorder Recorder.
	 */
	public function __construct( private readonly Recorder $recorder ) {
		global $wp_version;

		$this->initial_wp_version = (string) $wp_version;
	}

	/**
	 * Queues the hooks.
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_filter( 'upgrader_pre_install', array( $this, 'capture_old_version' ), 10, 2 );
		$loader->add_action( 'upgrader_process_complete', array( $this, 'on_complete' ), 10, 2 );
		$loader->add_action( '_core_updated_successfully', array( $this, 'on_core_updated' ), 10, 1 );
	}

	/**
	 * Remembers the installed version of the item about to be replaced (filter pass-through).
	 *
	 * @param mixed $response   Response (returned unchanged).
	 * @param mixed $hook_extra Item details: plugin or theme.
	 *
	 * @return mixed The unchanged $response.
	 */
	public function capture_old_version( $response, $hook_extra = array() ) {
		$extra = (array) $hook_extra;

		if ( isset( $extra['plugin'] ) ) {
			$this->old_versions[ 'plugin:' . $extra['plugin'] ] = PluginSensor::details( (string) $extra['plugin'] )['version'];
		}

		if ( isset( $extra['theme'] ) ) {
			$this->old_versions[ 'theme:' . $extra['theme'] ] = (string) wp_get_theme( (string) $extra['theme'] )->get( 'Version' );
		}

		return $response;
	}

	/**
	 * Records the result of an install or update.
	 *
	 * @param mixed $upgrader   The upgrader.
	 * @param mixed $hook_extra Details: action, type, plugin(s)/theme(s).
	 *
	 * @return void
	 */
	public function on_complete( $upgrader, $hook_extra = array() ): void {
		$extra  = (array) $hook_extra;
		$type   = (string) ( $extra['type'] ?? '' );
		$action = (string) ( $extra['action'] ?? '' );

		if ( 'install' === $action ) {
			$this->record_install( $type, $upgrader );
			return;
		}

		if ( 'update' === $action && in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			foreach ( self::items( $extra, $type ) as $item ) {
				$this->record_update( $type, $item );
			}
		}
	}

	/**
	 * Records a WordPress core update.
	 *
	 * @param string $new_version Version now installed.
	 *
	 * @return void
	 */
	public function on_core_updated( $new_version ): void {
		$this->recorder->record(
			new Event(
				'core.updated',
				object_type: 'core',
				object_id: 'WordPress',
				object_label: 'WordPress',
				context: array(
					'old_version' => $this->initial_wp_version,
					'new_version' => (string) $new_version,
				)
			)
		);
	}

	/**
	 * Records a plugin or theme install.
	 *
	 * @param string $type     "plugin" or "theme".
	 * @param mixed  $upgrader The upgrader.
	 *
	 * @return void
	 */
	private function record_install( string $type, mixed $upgrader ): void {
		$context = array( 'source' => self::install_source( $upgrader ) );

		if ( 'plugin' === $type && is_object( $upgrader ) && method_exists( $upgrader, 'plugin_info' ) ) {
			$file = (string) $upgrader->plugin_info();

			if ( '' !== $file ) {
				$details = PluginSensor::details( $file );
				$this->record( 'plugin.installed', 'plugin', $file, $details['name'], $context + array( 'version' => $details['version'] ) );
			}

			return;
		}

		if ( 'theme' === $type && is_object( $upgrader ) && method_exists( $upgrader, 'theme_info' ) ) {
			$theme = $upgrader->theme_info();

			if ( $theme instanceof \WP_Theme ) {
				$this->record( 'theme.installed', 'theme', $theme->get_stylesheet(), (string) $theme->get( 'Name' ), $context + array( 'version' => (string) $theme->get( 'Version' ) ) );
			}
		}
	}

	/**
	 * Records one updated plugin or theme with from → to versions.
	 *
	 * @param string $type "plugin" or "theme".
	 * @param string $item Plugin file or theme slug.
	 *
	 * @return void
	 */
	private function record_update( string $type, string $item ): void {
		if ( 'plugin' === $type ) {
			$details = PluginSensor::details( $item );
			$name    = $details['name'];
			$version = $details['version'];
		} else {
			$theme   = wp_get_theme( $item );
			$name    = (string) $theme->get( 'Name' );
			$version = (string) $theme->get( 'Version' );
		}

		$this->record(
			"$type.updated",
			$type,
			$item,
			'' === $name ? $item : $name,
			array(
				'old_version' => $this->old_versions[ "$type:$item" ] ?? '?',
				'new_version' => $version,
			)
		);
	}

	/**
	 * Records an event about a plugin or theme.
	 *
	 * @param string               $event_type  Event type.
	 * @param string               $object_type "plugin" or "theme".
	 * @param string               $object_id   Plugin file or theme slug.
	 * @param string               $label       Name.
	 * @param array<string, mixed> $context     Context.
	 *
	 * @return void
	 */
	private function record( string $event_type, string $object_type, string $object_id, string $label, array $context ): void {
		$this->recorder->record( new Event( $event_type, object_type: $object_type, object_id: $object_id, object_label: $label, context: $context ) );
	}

	/**
	 * Returns the updated items listed in the hook details.
	 *
	 * @param array<string, mixed> $extra Hook details.
	 * @param string               $type  "plugin" or "theme".
	 *
	 * @return array<int, string> Plugin files or theme slugs.
	 */
	private static function items( array $extra, string $type ): array {
		$items = $extra[ $type . 's' ] ?? ( isset( $extra[ $type ] ) ? array( $extra[ $type ] ) : array() );

		return array_values( array_map( 'strval', (array) $items ) );
	}

	/**
	 * Tells whether an install came from an uploaded zip or from WordPress.org.
	 *
	 * @param mixed $upgrader The upgrader.
	 *
	 * @return string "upload", "repo" or "unknown".
	 */
	private static function install_source( mixed $upgrader ): string {
		$skin = is_object( $upgrader ) && isset( $upgrader->skin ) && is_object( $upgrader->skin ) ? $upgrader->skin : null;
		$type = null !== $skin && isset( $skin->type ) ? (string) $skin->type : '';

		return match ( $type ) {
			'upload' => 'upload',
			'web'    => 'repo',
			default  => 'unknown',
		};
	}
}
