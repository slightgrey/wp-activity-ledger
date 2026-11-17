<?php
/**
 * Plugin bootstrap.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger;

/**
 * Builds the plugin's modules once per request and hands their hooks to the Loader.
 */
final class Plugin {

	/**
	 * The single instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Creates the plugin.
	 *
	 * @param Loader $loader Hook loader.
	 */
	private function __construct( private readonly Loader $loader ) {
	}

	/**
	 * Returns the single instance, creating it on first use.
	 *
	 * @return Plugin The plugin.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self( new Loader() );
		}

		return self::$instance;
	}

	/**
	 * Boots the plugin on `plugins_loaded`: registers every module's hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		self::instance()->register();
	}

	/**
	 * Returns the hook loader.
	 *
	 * @return Loader The loader.
	 */
	public function loader(): Loader {
		return $this->loader;
	}

	/**
	 * Registers the modules once, then hands their hooks to WordPress.
	 *
	 * @return void
	 */
	private function register(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		/**
		 * Fires after the core modules queued their hooks and before they are registered.
		 *
		 * @param Loader $loader The hook loader.
		 */
		do_action( 'wpal_register', $this->loader );

		$this->loader->run();
	}
}
