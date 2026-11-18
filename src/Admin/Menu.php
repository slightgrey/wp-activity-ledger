<?php
/**
 * Admin menu.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Admin;

use WPActivityLedger\Loader;
use WPActivityLedger\Rest\SettingsController;
use WPActivityLedger\Security\Capabilities;

/**
 * Adds the "Activity Ledger" menu. Every submenu opens the same page; the React app shows
 * the screen named in the URL hash (admin.php?page=wp-activity-ledger#/log).
 */
final class Menu {

	/**
	 * Page slug.
	 */
	public const SLUG = 'wp-activity-ledger';

	/**
	 * Hook suffix of the page, set once the menu is added.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Queues the hooks.
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'admin_menu', array( $this, 'add_menu' ), 10, 0 );
		$loader->add_action( 'network_admin_menu', array( $this, 'add_network_menu' ), 10, 0 );
	}

	/**
	 * Returns the page's hook suffix ('' before the menu is added).
	 *
	 * @return string Hook suffix.
	 */
	public function hook_suffix(): string {
		return $this->hook_suffix;
	}

	/**
	 * Returns the URL of a screen of the app.
	 *
	 * @param string $route Route, e.g. "/log".
	 *
	 * @return string URL.
	 */
	public static function url( string $route = '/' ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . '#' . $route );
	}

	/**
	 * Adds the top-level menu and its submenus.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		$this->hook_suffix = (string) add_menu_page(
			__( 'Activity Ledger', 'wp-activity-ledger' ),
			__( 'Activity Ledger', 'wp-activity-ledger' ),
			Capabilities::VIEW,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-list-view',
			81
		);

		foreach ( self::screens() as $route => [ $title, $capability ] ) {
			add_submenu_page(
				self::SLUG,
				$title,
				$title,
				$capability,
				'/' === $route ? self::SLUG : 'admin.php?page=' . self::SLUG . '#' . $route
			);
		}
	}

	/**
	 * Adds the network admin page (multisite): the same app, reading any site's log.
	 *
	 * @return void
	 */
	public function add_network_menu(): void {
		$this->hook_suffix = (string) add_menu_page(
			__( 'Activity Ledger', 'wp-activity-ledger' ),
			__( 'Activity Ledger', 'wp-activity-ledger' ),
			'manage_network_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-list-view',
			81
		);

		foreach ( self::network_screens() as $route => $title ) {
			add_submenu_page(
				self::SLUG,
				$title,
				$title,
				'manage_network_options',
				'/' === $route ? self::SLUG : 'admin.php?page=' . self::SLUG . '#' . $route
			);
		}
	}

	/**
	 * Returns the network admin screens: route => menu title.
	 *
	 * @return array<string, string> Screens.
	 */
	public static function network_screens(): array {
		return array(
			'/'        => __( 'Dashboard', 'wp-activity-ledger' ),
			'/log'     => __( 'Activity Log', 'wp-activity-ledger' ),
			'/users'   => __( 'Users & Sessions', 'wp-activity-ledger' ),
			'/network' => __( 'Network Settings', 'wp-activity-ledger' ),
		);
	}

	/**
	 * Renders the page: the mount point of the React app.
	 *
	 * @return void
	 */
	public function render(): void {
		echo '<div class="wrap wpal-wrap"><h1 class="screen-reader-text">' . esc_html__( 'Activity Ledger', 'wp-activity-ledger' ) . '</h1>';
		echo '<div id="wpal-root" class="wpal-app">';

		if ( ! Assets::is_built() ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The Activity Ledger admin app has not been built. Run "npm run build" in the plugin folder.', 'wp-activity-ledger' ) . '</p></div>';
		}

		echo '</div></div>';
	}

	/**
	 * Returns the app screens: route => [menu title, capability].
	 *
	 * @return array<string, array{0: string, 1: string}> Screens.
	 */
	public static function screens(): array {
		return array(
			'/'         => array( __( 'Dashboard', 'wp-activity-ledger' ), Capabilities::VIEW ),
			'/log'      => array( __( 'Activity Log', 'wp-activity-ledger' ), Capabilities::VIEW ),
			'/users'    => array( __( 'Users & Sessions', 'wp-activity-ledger' ), Capabilities::VIEW ),
			'/alerts'   => array( __( 'Alerts', 'wp-activity-ledger' ), Capabilities::MANAGE_SETTINGS ),
			'/settings' => array( __( 'Settings', 'wp-activity-ledger' ), SettingsController::read_capability() ),
		);
	}
}
