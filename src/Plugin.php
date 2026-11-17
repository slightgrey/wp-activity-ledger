<?php
/**
 * Plugin bootstrap.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger;

use WPActivityLedger\Database\EventRepository;
use WPActivityLedger\Diff\Differ;
use WPActivityLedger\Diff\Redactor;
use WPActivityLedger\Support\PatternMatcher;
use WPActivityLedger\Database\Installer;
use WPActivityLedger\Domain\CoreEventTypes;
use WPActivityLedger\Domain\EventTypeRegistry;
use WPActivityLedger\Recording\Recorder;
use WPActivityLedger\Security\Capabilities;
use WPActivityLedger\Sensors\AuthSensor;
use WPActivityLedger\Sensors\MetaWatcher;
use WPActivityLedger\Sensors\PostSensor;
use WPActivityLedger\Sensors\SensorInterface;
use WPActivityLedger\Sensors\SensorRegistry;
use WPActivityLedger\Sensors\UserProfileSensor;
use WPActivityLedger\Sensors\UserSensor;
use WPActivityLedger\Settings\Settings;

/**
 * Builds the plugin's services once per request and hands their hooks to the Loader.
 *
 * Services are created lazily, because some code paths (activation, deactivation) run
 * without plugins_loaded firing for this plugin.
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
	 * Settings service.
	 *
	 * @var Settings|null
	 */
	private ?Settings $settings = null;

	/**
	 * Event type registry.
	 *
	 * @var EventTypeRegistry|null
	 */
	private ?EventTypeRegistry $types = null;

	/**
	 * Event storage.
	 *
	 * @var EventRepository|null
	 */
	private ?EventRepository $repository = null;

	/**
	 * Event recorder.
	 *
	 * @var Recorder|null
	 */
	private ?Recorder $recorder = null;

	/**
	 * Differ configured from the settings.
	 *
	 * @var Differ|null
	 */
	private ?Differ $differ = null;

	/**
	 * Core sensors, keyed by slug.
	 *
	 * @var array<string, SensorInterface>|null
	 */
	private ?array $sensors = null;

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
	 * Returns the settings.
	 *
	 * @return Settings Settings.
	 */
	public function settings(): Settings {
		$this->settings ??= new Settings();

		return $this->settings;
	}

	/**
	 * Returns the event type registry, with the core types queued as a lazy provider.
	 *
	 * @return EventTypeRegistry Registry.
	 */
	public function types(): EventTypeRegistry {
		if ( null === $this->types ) {
			$this->types = new EventTypeRegistry();
			$this->types->add_provider( array( CoreEventTypes::class, 'register' ) );
		}

		return $this->types;
	}

	/**
	 * Returns the event repository.
	 *
	 * @return EventRepository Repository.
	 */
	public function repository(): EventRepository {
		$this->repository ??= new EventRepository();

		return $this->repository;
	}

	/**
	 * Returns a Differ configured with the redaction patterns and size limit from the settings.
	 *
	 * @return Differ Differ.
	 */
	public function differ(): Differ {
		if ( null === $this->differ ) {
			$settings     = $this->settings();
			$redactor     = new Redactor( new PatternMatcher( (array) $settings->get( 'redact_patterns' ) ) );
			$this->differ = new Differ( $redactor, (int) $settings->get( 'max_value_size' ) );
		}

		return $this->differ;
	}

	/**
	 * Returns the recorder.
	 *
	 * @return Recorder Recorder.
	 */
	public function recorder(): Recorder {
		$this->recorder ??= new Recorder( $this->repository(), $this->types(), $this->settings() );

		return $this->recorder;
	}

	/**
	 * Returns the core sensors, keyed by slug.
	 *
	 * @return array<string, SensorInterface> Sensors.
	 */
	public function core_sensors(): array {
		$this->sensors ??= array(
			'auth'    => new AuthSensor( $this->recorder(), $this->repository(), $this->settings() ),
			'users'   => new UserSensor( $this->recorder(), $this->differ() ),
			'profile' => new UserProfileSensor( $this->recorder(), $this->differ(), $this->settings() ),
			'posts'   => new PostSensor( $this->recorder(), $this->differ(), new MetaWatcher( $this->settings() ) ),
		);

		return $this->sensors;
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

		Installer::maybe_upgrade();
		$this->loader->add_action( 'admin_notices', array( Installer::class, 'error_notice' ) );
		$this->loader->add_action( 'network_admin_notices', array( Installer::class, 'error_notice' ) );
		Capabilities::register( $this->loader );
		$this->recorder()->register( $this->loader );
		$this->loader->add_action( 'wpal_log', 'wpal_log', 10, 2 );
		( new SensorRegistry( $this->core_sensors() ) )->register( $this->loader );

		/**
		 * Fires after the core modules queued their hooks and before they are registered.
		 *
		 * @param Loader $loader The hook loader.
		 */
		do_action( 'wpal_register', $this->loader );

		$this->loader->run();
	}
}
