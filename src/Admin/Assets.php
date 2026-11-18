<?php
/**
 * Admin app assets.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Admin;

use WPActivityLedger\Loader;
use WPActivityLedger\Rest\BaseController;
use WPActivityLedger\Security\Capabilities;

/**
 * Enqueues the Vite build of the admin app (read from its manifest) on the plugin page only,
 * with the app config passed inline as `window.wpalConfig`.
 */
final class Assets {

	/**
	 * Script handle.
	 */
	public const HANDLE = 'wpal-admin';

	/**
	 * Build folder, relative to the plugin.
	 */
	private const BUILD_DIR = 'dist/admin/';

	/**
	 * Manifest entry of the app.
	 */
	private const ENTRY = 'main.jsx';

	/**
	 * Creates the asset loader.
	 *
	 * @param Menu $menu Admin menu (gives the page's hook suffix).
	 */
	public function __construct( private readonly Menu $menu ) {
	}

	/**
	 * Queues the hooks.
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 10, 1 );
		$loader->add_filter( 'script_loader_tag', array( $this, 'module_tag' ), 10, 2 );
		$loader->add_action( 'admin_notices', array( $this, 'missing_build_notice' ), 10, 0 );
	}

	/**
	 * Tells whether the app has been built.
	 *
	 * @return bool True when the manifest exists.
	 */
	public static function is_built(): bool {
		return null !== self::entry();
	}

	/**
	 * Enqueues the app on its page.
	 *
	 * @param string $hook_suffix Current admin page.
	 *
	 * @return void
	 */
	public function enqueue( $hook_suffix ): void {
		$entry = self::entry();

		if ( '' === $this->menu->hook_suffix() || $this->menu->hook_suffix() !== $hook_suffix || null === $entry ) {
			return;
		}

		$version = WPAL_VERSION . '-' . (string) filemtime( WPAL_DIR . self::BUILD_DIR . $entry['file'] );

		// No ?ver on the script: its file name is content-hashed, and the lazy-loaded chunks
		// import it by its bare URL; a different URL would load a second copy of the app.
		wp_enqueue_script( self::HANDLE, WPAL_URL . self::BUILD_DIR . $entry['file'], array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Hashed file name.
		wp_add_inline_script( self::HANDLE, 'window.wpalConfig = ' . wp_json_encode( self::config() ) . ';', 'before' );

		foreach ( $entry['css'] as $index => $css ) {
			wp_enqueue_style( self::HANDLE . ( 0 === $index ? '' : '-' . $index ), WPAL_URL . self::BUILD_DIR . $css, array(), $version );
		}
	}

	/**
	 * Loads the app script as an ES module.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Script handle.
	 *
	 * @return string Script tag.
	 */
	public function module_tag( $tag, $handle ): string {
		if ( self::HANDLE !== $handle || str_contains( (string) $tag, 'type="module"' ) ) {
			return (string) $tag;
		}

		return (string) preg_replace( '/<script (?=[^>]*\bsrc=)/', '<script type="module" ', (string) $tag, 1 );
	}

	/**
	 * Shows a notice on the plugin page when the app has not been built.
	 *
	 * @return void
	 */
	public function missing_build_notice(): void {
		$screen = get_current_screen();

		if ( null === $screen || $screen->id !== $this->menu->hook_suffix() || self::is_built() ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>' . esc_html__( 'WP Activity Ledger: the admin app build is missing (dist/admin).', 'wp-activity-ledger' ) . '</p></div>';
	}

	/**
	 * Returns the config the app reads from `window.wpalConfig`.
	 *
	 * @return array<string, mixed> Config.
	 */
	public static function config(): array {
		return array(
			'restUrl'        => esc_url_raw( rest_url( BaseController::NAMESPACE . '/' ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'nonceUrl'       => admin_url( 'admin-ajax.php?action=rest-nonce' ),
			'adminUrl'       => admin_url(),
			'pageUrl'        => is_network_admin() ? network_admin_url( 'admin.php?page=' . Menu::SLUG ) : admin_url( 'admin.php?page=' . Menu::SLUG ),
			'network'        => is_network_admin(),
			'settingsLocked' => is_multisite() && Capabilities::network_locked(),
			'siteId'         => get_current_blog_id(),
			'i18n'           => (object) self::translations(),
			'timezone'       => wp_timezone_string(),
			'dateFormat'     => (string) get_option( 'date_format' ),
			'timeFormat'     => (string) get_option( 'time_format' ),
			'version'        => WPAL_VERSION,
			'exportUrl'      => admin_url( 'admin-post.php' ),
			'exportNonce'    => wp_create_nonce( ExportHandler::ACTION ),
			'userId'         => get_current_user_id(),
			'caps'           => array(
				'view'            => current_user_can( Capabilities::VIEW ), // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Plugin capabilities.
				'export'          => current_user_can( Capabilities::EXPORT ), // phpcs:ignore WordPress.WP.Capabilities.Undetermined
				'manage_settings' => current_user_can( Capabilities::MANAGE_SETTINGS ), // phpcs:ignore WordPress.WP.Capabilities.Undetermined
				'delete'          => current_user_can( Capabilities::DELETE ), // phpcs:ignore WordPress.WP.Capabilities.Undetermined
				'manage_sessions' => current_user_can( Capabilities::MANAGE_SESSIONS ), // phpcs:ignore WordPress.WP.Capabilities.Undetermined
				'manage_options'  => current_user_can( 'manage_options' ),
			),
		);
	}

	/**
	 * Returns the admin app's translations for the user's locale: msgid => translation, read
	 * from the JSON files `wp i18n make-json` writes (languages/wp-activity-ledger-{locale}-*.json,
	 * or the same names in wp-content/languages/plugins/).
	 *
	 * @return array<string, string> Translations (empty for English or when none exist).
	 */
	public static function translations(): array {
		$locale = determine_locale();

		if ( 'en_US' === $locale ) {
			return array();
		}

		$strings = array();
		$pattern = 'wp-activity-ledger-' . $locale . '-*.json';

		foreach ( array_merge( (array) glob( WPAL_DIR . 'languages/' . $pattern ), (array) glob( WP_LANG_DIR . '/plugins/' . $pattern ) ) as $file ) {
			$data     = json_decode( (string) file_get_contents( (string) $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
			$messages = is_array( $data ) ? ( $data['locale_data']['messages'] ?? $data['locale_data']['wp-activity-ledger'] ?? array() ) : array();

			foreach ( (array) $messages as $msgid => $translation ) {
				if ( '' !== $msgid && is_array( $translation ) && is_string( $translation[0] ?? null ) && '' !== $translation[0] ) {
					$strings[ (string) $msgid ] = $translation[0];
				}
			}
		}

		return $strings;
	}

	/**
	 * Returns the app's manifest entry.
	 *
	 * @return array{file: string, css: array<int, string>}|null Entry, or null when not built.
	 */
	private static function entry(): ?array {
		$path = WPAL_DIR . self::BUILD_DIR . '.vite/manifest.json';

		if ( ! is_readable( $path ) ) {
			return null;
		}

		$manifest = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$entry    = is_array( $manifest ) ? ( $manifest[ self::ENTRY ] ?? null ) : null;

		if ( ! is_array( $entry ) || ! is_string( $entry['file'] ?? null ) ) {
			return null;
		}

		$css = (array) ( $entry['css'] ?? array() );

		// With CSS code splitting off, Vite lists the one stylesheet under "style.css".
		if ( is_string( $manifest['style.css']['file'] ?? null ) ) {
			$css[] = $manifest['style.css']['file'];
		}

		return array(
			'file' => $entry['file'],
			'css'  => array_values( array_unique( array_filter( $css, 'is_string' ) ) ),
		);
	}
}
