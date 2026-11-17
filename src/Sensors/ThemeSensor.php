<?php
/**
 * Theme sensor: switch, delete and file editor edits (themes and plugins).
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Sensors;

use WPActivityLedger\Domain\Event;
use WPActivityLedger\Loader;
use WPActivityLedger\Recording\Recorder;

/**
 * Records theme switches and deletions, and saves in the theme/plugin file editors.
 *
 * File editor saves go through admin-ajax (edit-theme-plugin-file). The edit is recorded
 * before core handles it, but only when the request carries a valid nonce for that file
 * and the user has the edit capability, i.e. when core will accept it too.
 */
final class ThemeSensor implements SensorInterface {

	/**
	 * Theme names captured before deletion: stylesheet => name.
	 *
	 * @var array<string, string>
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
		$loader->add_action( 'switch_theme', array( $this, 'on_switch' ), 10, 3 );
		$loader->add_action( 'delete_theme', array( $this, 'before_delete' ), 10, 1 );
		$loader->add_action( 'deleted_theme', array( $this, 'on_deleted' ), 10, 2 );
		$loader->add_action( 'wp_ajax_edit-theme-plugin-file', array( $this, 'on_file_edit' ), 1, 0 );
	}

	/**
	 * Records a theme switch.
	 *
	 * @param string $new_name  New theme name.
	 * @param mixed  $new_theme New theme.
	 * @param mixed  $old_theme Previous theme.
	 *
	 * @return void
	 */
	public function on_switch( $new_name, mixed $new_theme = null, mixed $old_theme = null ): void {
		$this->recorder->record(
			new Event(
				'theme.switched',
				object_type: 'theme',
				object_id: $new_theme instanceof \WP_Theme ? $new_theme->get_stylesheet() : '',
				object_label: (string) $new_name,
				context: array( 'old_theme' => $old_theme instanceof \WP_Theme ? (string) $old_theme->get( 'Name' ) : '' )
			)
		);
	}

	/**
	 * Remembers a theme's name before its files are removed.
	 *
	 * @param string $stylesheet Theme slug.
	 *
	 * @return void
	 */
	public function before_delete( $stylesheet ): void {
		$this->deleting[ (string) $stylesheet ] = (string) wp_get_theme( (string) $stylesheet )->get( 'Name' );
	}

	/**
	 * Records a successful theme deletion.
	 *
	 * @param string $stylesheet Theme slug.
	 * @param bool   $deleted    Whether the files were deleted.
	 *
	 * @return void
	 */
	public function on_deleted( $stylesheet, $deleted = true ): void {
		$name = $this->deleting[ (string) $stylesheet ] ?? (string) $stylesheet;

		unset( $this->deleting[ (string) $stylesheet ] );

		if ( $deleted ) {
			$this->recorder->record( new Event( 'theme.deleted', object_type: 'theme', object_id: (string) $stylesheet, object_label: '' === $name ? (string) $stylesheet : $name ) );
		}
	}

	/**
	 * Records a file editor save that core will accept (valid nonce and capability).
	 *
	 * @return void
	 */
	public function on_file_edit(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The nonce is verified below, the same way core does.
		$file   = isset( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : '';
		$theme  = isset( $_POST['theme'] ) ? sanitize_text_field( wp_unslash( $_POST['theme'] ) ) : '';
		$plugin = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';
		$nonce  = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		// phpcs:enable

		if ( '' === $file ) {
			return;
		}

		if ( '' !== $theme ) {
			$this->record_theme_edit( $theme, $file, $nonce );
			return;
		}

		$this->record_plugin_edit( $plugin, $file, $nonce );
	}

	/**
	 * Records a theme file edit.
	 *
	 * @param string $stylesheet Theme slug.
	 * @param string $file       File relative to the theme.
	 * @param string $nonce      Nonce sent with the request.
	 *
	 * @return void
	 */
	private function record_theme_edit( string $stylesheet, string $file, string $nonce ): void {
		if ( ! current_user_can( 'edit_themes' ) || ! wp_verify_nonce( $nonce, 'edit-theme_' . $stylesheet . '_' . $file ) ) {
			return;
		}

		$this->recorder->record(
			new Event( 'theme.file_edited', object_type: 'theme', object_id: $stylesheet, object_label: (string) wp_get_theme( $stylesheet )->get( 'Name' ), context: array( 'file' => "$stylesheet/$file" ) )
		);
	}

	/**
	 * Records a plugin file edit.
	 *
	 * @param string $plugin Plugin main file.
	 * @param string $file   Edited file relative to the plugins folder.
	 * @param string $nonce  Nonce sent with the request.
	 *
	 * @return void
	 */
	private function record_plugin_edit( string $plugin, string $file, string $nonce ): void {
		if ( ! current_user_can( 'edit_plugins' ) || ! wp_verify_nonce( $nonce, 'edit-plugin_' . $file ) ) {
			return;
		}

		$main = '' === $plugin ? $file : $plugin;

		$this->recorder->record(
			new Event( 'plugin.file_edited', object_type: 'plugin', object_id: $main, object_label: PluginSensor::details( $main )['name'], context: array( 'file' => $file ) )
		);
	}
}
