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
use WPActivityLedger\Integrations\IntegrationManager;
use WPActivityLedger\Integrations\Elementor;
use WPActivityLedger\Integrations\WooCommerce;
use WPActivityLedger\Recording\Recorder;
use WPActivityLedger\Rest\Routes;
use WPActivityLedger\Alerts\ChannelStore;
use WPActivityLedger\Alerts\Deliverer;
use WPActivityLedger\Alerts\Dispatcher;
use WPActivityLedger\Alerts\RuleMatcher;
use WPActivityLedger\Alerts\RuleStore;
use WPActivityLedger\Alerts\TransientAlertState;
use WPActivityLedger\Admin\Assets;
use WPActivityLedger\Admin\DashboardWidget;
use WPActivityLedger\Admin\Menu;
use WPActivityLedger\Security\Capabilities;
use WPActivityLedger\Sensors\AuthSensor;
use WPActivityLedger\Sensors\AutoUpdateSensor;
use WPActivityLedger\Sensors\CommentSensor;
use WPActivityLedger\Sensors\CustomizerSensor;
use WPActivityLedger\Sensors\MediaSensor;
use WPActivityLedger\Sensors\MenuSensor;
use WPActivityLedger\Sensors\MetaWatcher;
use WPActivityLedger\Sensors\OptionFilter;
use WPActivityLedger\Sensors\OptionSensor;
use WPActivityLedger\Sensors\PluginSensor;
use WPActivityLedger\Sensors\PostSensor;
use WPActivityLedger\Sensors\SelfSensor;
use WPActivityLedger\Sensors\SensorInterface;
use WPActivityLedger\Sensors\SensorRegistry;
use WPActivityLedger\Sensors\TermSensor;
use WPActivityLedger\Sensors\ThemeSensor;
use WPActivityLedger\Sensors\ToolsSensor;
use WPActivityLedger\Sensors\UpgraderSensor;
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
	 * Integration manager.
	 *
	 * @var IntegrationManager|null
	 */
	private ?IntegrationManager $integrations = null;

	/**
	 * Option noise filter shared by the option sensors.
	 *
	 * @var OptionFilter|null
	 */
	private ?OptionFilter $option_filter = null;

	/**
	 * Alert channels.
	 *
	 * @var ChannelStore|null
	 */
	private ?ChannelStore $alert_channels = null;

	/**
	 * Alert rules.
	 *
	 * @var RuleStore|null
	 */
	private ?RuleStore $alert_rules = null;

	/**
	 * Alert deliverer.
	 *
	 * @var Deliverer|null
	 */
	private ?Deliverer $deliverer = null;

	/**
	 * Alert dispatcher.
	 *
	 * @var Dispatcher|null
	 */
	private ?Dispatcher $dispatcher = null;

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
	 * Returns the integration manager with the built-in integrations.
	 *
	 * @return IntegrationManager Manager.
	 */
	public function integrations(): IntegrationManager {
		$this->integrations ??= new IntegrationManager(
			array(
				new WooCommerce( $this->recorder(), $this->differ(), $this->option_filter() ),
				new Elementor( $this->recorder(), $this->differ(), $this->option_filter() ),
			)
		);

		return $this->integrations;
	}

	/**
	 * Returns the alert channel store.
	 *
	 * @return ChannelStore Channels.
	 */
	public function alert_channels(): ChannelStore {
		$this->alert_channels ??= new ChannelStore();

		return $this->alert_channels;
	}

	/**
	 * Returns the alert rule store.
	 *
	 * @return RuleStore Rules.
	 */
	public function alert_rules(): RuleStore {
		$this->alert_rules ??= new RuleStore( $this->alert_channels() );

		return $this->alert_rules;
	}

	/**
	 * Returns the alert deliverer.
	 *
	 * @return Deliverer Deliverer.
	 */
	public function deliverer(): Deliverer {
		$this->deliverer ??= new Deliverer( $this->alert_channels(), $this->recorder() );

		return $this->deliverer;
	}

	/**
	 * Returns the alert dispatcher.
	 *
	 * @return Dispatcher Dispatcher.
	 */
	public function dispatcher(): Dispatcher {
		$this->dispatcher ??= new Dispatcher( $this->alert_rules(), new RuleMatcher( new TransientAlertState() ), $this->types(), $this->deliverer() );

		return $this->dispatcher;
	}

	/**
	 * Returns the shared option noise filter.
	 *
	 * @return OptionFilter Filter.
	 */
	public function option_filter(): OptionFilter {
		$this->option_filter ??= new OptionFilter( $this->settings() );

		return $this->option_filter;
	}

	/**
	 * Returns the core sensors, keyed by slug.
	 *
	 * @return array<string, SensorInterface> Sensors.
	 */
	public function core_sensors(): array {
		if ( null !== $this->sensors ) {
			return $this->sensors;
		}

		$noise = $this->option_filter();

		$this->sensors = array(
			'auth'       => new AuthSensor( $this->recorder(), $this->repository(), $this->settings() ),
			'users'      => new UserSensor( $this->recorder(), $this->differ() ),
			'profile'    => new UserProfileSensor( $this->recorder(), $this->differ(), $this->settings() ),
			'posts'      => new PostSensor( $this->recorder(), $this->differ(), new MetaWatcher( $this->settings() ) ),
			'media'      => new MediaSensor( $this->recorder(), $this->differ() ),
			'comments'   => new CommentSensor( $this->recorder(), $this->differ() ),
			'terms'      => new TermSensor( $this->recorder(), $this->differ() ),
			'menus'      => new MenuSensor( $this->recorder(), $this->differ() ),
			'customizer' => new CustomizerSensor( $this->recorder(), $this->differ(), $noise ),
			'plugins'    => new PluginSensor( $this->recorder() ),
			'upgrader'   => new UpgraderSensor( $this->recorder() ),
			'themes'     => new ThemeSensor( $this->recorder() ),
			'autoupdate' => new AutoUpdateSensor( $this->recorder(), $this->differ() ),
			'options'    => new OptionSensor( $this->recorder(), $this->differ(), $noise ),
			'tools'      => new ToolsSensor( $this->recorder() ),
			'self'       => new SelfSensor( $this->recorder(), $this->differ() ),
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
		$this->integrations()->load( $this->loader, $this->types() );
		( new Routes( $this ) )->register( $this->loader );
		$this->dispatcher()->register( $this->loader );
		$this->deliverer()->register( $this->loader );
		$this->register_admin();

		/**
		 * Fires after the core modules queued their hooks and before they are registered.
		 *
		 * @param Loader $loader The hook loader.
		 */
		do_action( 'wpal_register', $this->loader );

		$this->loader->run();
	}

	/**
	 * Queues the admin screen hooks (menu and app assets).
	 *
	 * @return void
	 */
	private function register_admin(): void {
		$menu = new Menu();
		$menu->register( $this->loader );
		( new Assets( $menu ) )->register( $this->loader );
		( new DashboardWidget( $this->repository(), $this->settings() ) )->register( $this->loader );
	}
}
