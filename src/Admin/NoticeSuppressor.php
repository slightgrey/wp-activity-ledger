<?php
/**
 * Hides other admin notices on the plugin's screens.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Admin;

use WPActivityLedger\Loader;
use WPActivityLedger\Settings\Settings;

/**
 * On the Activity Ledger page, removes the notices of WordPress and other plugins (unless
 * turned off in the settings). Callbacks defined in this plugin are kept, so its own errors
 * still show. Notices printed outside the notice hooks are hidden with CSS through the
 * "wpal-hide-notices" body class.
 */
final class NoticeSuppressor {

	/**
	 * Body class set on the page while notices are hidden.
	 */
	public const BODY_CLASS = 'wpal-hide-notices';

	/**
	 * Notice hooks emptied on the page.
	 */
	public const HOOKS = array( 'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices' );

	/**
	 * Creates the suppressor.
	 *
	 * @param Menu     $menu     Admin menu (gives the page's hook suffix).
	 * @param Settings $settings Settings.
	 */
	public function __construct(
		private readonly Menu $menu,
		private readonly Settings $settings
	) {
	}

	/**
	 * Queues the hooks.
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public function register( Loader $loader ): void {
		// in_admin_header runs after the screen is set and before the notice hooks fire.
		$loader->add_action( 'in_admin_header', array( $this, 'suppress' ), 1000, 0 );
		$loader->add_filter( 'admin_body_class', array( $this, 'body_class' ), 10, 1 );
	}

	/**
	 * Tells whether notices are hidden on the current screen.
	 *
	 * @return bool True on the plugin page with the setting on.
	 */
	public function active(): bool {
		if ( ! $this->settings->get( 'hide_admin_notices' ) || '' === $this->menu->hook_suffix() || ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return null !== $screen && $screen->id === $this->menu->hook_suffix();
	}

	/**
	 * Removes every notice callback that does not belong to this plugin.
	 *
	 * @return void
	 */
	public function suppress(): void {
		global $wp_filter;

		if ( ! $this->active() ) {
			return;
		}

		foreach ( self::HOOKS as $hook ) {
			if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof \WP_Hook ) {
				continue;
			}

			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( ! self::is_own( $callback['function'] ) ) {
						remove_action( $hook, $callback['function'], (int) $priority );
					}
				}
			}
		}
	}

	/**
	 * Adds the body class that hides notices printed outside the notice hooks.
	 *
	 * @param string $classes Space-separated body classes.
	 *
	 * @return string Body classes.
	 */
	public function body_class( $classes ): string {
		$classes = (string) $classes;

		return $this->active() ? $classes . ' ' . self::BODY_CLASS . ' ' : $classes;
	}

	/**
	 * Tells whether a callback is defined in this plugin's folder.
	 *
	 * @param mixed $callback Hook callback.
	 *
	 * @return bool True for the plugin's own callbacks.
	 */
	public static function is_own( mixed $callback ): bool {
		try {
			if ( is_string( $callback ) && str_contains( $callback, '::' ) ) {
				$callback = explode( '::', $callback, 2 );
			}

			if ( is_array( $callback ) && 2 === count( $callback ) ) {
				$reflection = new \ReflectionMethod( $callback[0], (string) $callback[1] );
			} elseif ( $callback instanceof \Closure || is_string( $callback ) ) {
				$reflection = new \ReflectionFunction( $callback );
			} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				$reflection = new \ReflectionMethod( $callback, '__invoke' );
			} else {
				return false;
			}
		} catch ( \ReflectionException ) {
			return false;
		}

		$file = $reflection->getFileName();

		return is_string( $file ) && str_starts_with( wp_normalize_path( $file ), wp_normalize_path( WPAL_DIR ) );
	}
}
