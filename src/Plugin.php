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
use WPActivityLedger\Admin\ExportHandler;
use WPActivityLedger\Admin\Menu;
use WPActivityLedger\Admin\NoticeSuppressor;
use WPActivityLedger\Admin\PluginLinks;
use WPActivityLedger\Cli\Command;
use WPActivityLedger\Update\Updater;
use WPActivityLedger\Lifecycle\Network;
use WPActivityLedger\Maintenance\Pruner;
use WPActivityLedger\Privacy\PrivacyTools;
use WPActivityLedger\Security\Capabilities;
use WPActivityLedger\Sensors\AuthSensor;
use WPActivityLedger\Sensors\AutoUpdateSensor;
use WPActivityLedger\Sensors\CommentSensor;
use WPActivityLedger\Sensors\CustomizerSensor;
use WPActivityLedger\Sensors\MediaSensor;
use WPActivityLedger\Sensors\MenuSensor;
use WPActivityLedger\Sensors\MetaWatcher;
use WPActivityLedger\Sensors\MultisiteSensor;
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
			'multisite'  => new MultisiteSensor( $this->recorder(), $this->differ(), $noise ),
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
		$this->loader->add_action( 'init', array( self::class, 'load_textdomain' ), 1, 0 );
		$this->loader->add_action( 'admin_notices', array( Installer::class, 'error_notice' ) );
		$this->loader->add_action( 'network_admin_notices', array( Installer::class, 'error_notice' ) );
		Capabilities::register( $this->loader );
		$this->recorder()->register( $this->loader );
		$this->loader->add_action( 'wpal_log', 'wpal_log', 10, 2 );
		( new SensorRegistry( $this->core_sensors() ) )->register( $this->loader );
		$this->integrations()->load( $this->loader, $this->types() );
		( new Routes( $this ) )->register( $this->loader );
		Network::register( $this->loader );
		$this->register_alerts();
		$this->register_maintenance();

		// Exporters/erasers can be run outside wp-admin too; the class loads when they run.
		$privacy = fn(): PrivacyTools => new PrivacyTools( $this->repository() );
		$this->loader->add_filter( 'wp_privacy_personal_data_exporters', fn( $exporters ) => $privacy()->add_exporter( $exporters ), 10, 1 );
		$this->loader->add_filter( 'wp_privacy_personal_data_erasers', fn( $erasers ) => $privacy()->add_eraser( $erasers ), 10, 1 );

		if ( is_admin() ) {
			$this->loader->add_action( 'admin_init', fn() => $privacy()->add_policy_text(), 10, 0 );
			$this->register_admin();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Command::register();
		}

		$this->register_updater();

		if ( is_multisite() ) {
			$this->loader->add_action( 'switch_blog', array( $this, 'on_switch_blog' ), 10, 2 );
		}

		/**
		 * Fires after the core modules queued their hooks and before they are registered.
		 *
		 * @param Loader $loader The hook loader.
		 */
		do_action( 'wpal_register', $this->loader );

		$this->loader->run();
	}

	/**
	 * Loads the plugin's translations from its languages/ folder (WordPress loads the ones in
	 * wp-content/languages/plugins/ on its own).
	 *
	 * @return void
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'wp-activity-ledger', false, dirname( plugin_basename( WPAL_FILE ) ) . '/languages' );
	}

	/**
	 * Queues the alert hooks. The alert services are built on first use: most requests store
	 * no events, so they never load them.
	 *
	 * @return void
	 */
	private function register_alerts(): void {
		$this->loader->add_action( 'wpal_events_flushed', fn( $events = array() ) => $this->dispatcher()->on_flushed( $events ), 10, 1 );
		$this->loader->add_action( 'wpal_event_repeated', fn( $event = null ) => $this->dispatcher()->on_repeated( $event ), 10, 1 );
		$this->loader->add_action( 'update_option_wpal_alert_rules', fn() => $this->dispatcher?->forget_rules(), 10, 0 );
		$this->loader->add_action( 'wpal_deliver_alert', fn( $job = null ) => $this->deliverer()->deliver( $job ), 10, 1 );
	}

	/**
	 * Queues the retention hooks; the daily event is repaired in wp-admin and cron requests.
	 *
	 * @return void
	 */
	private function register_maintenance(): void {
		$run = fn() => ( new Pruner( $this->repository(), $this->settings(), $this->recorder() ) )->run();

		$this->loader->add_action( 'wpal_daily_maintenance', $run, 10, 0 );
		$this->loader->add_action( 'wpal_maintenance_continue', $run, 10, 0 );

		if ( is_admin() || wp_doing_cron() ) {
			$this->loader->add_action( 'init', array( Pruner::class, 'schedule' ), 20, 0 );
		}
	}

	/**
	 * Registers the GitHub release updater (not admin-only: WordPress also checks from cron).
	 * The updater is built on first use, so the plugin header is only read when needed.
	 *
	 * @return void
	 */
	private function register_updater(): void {
		$updater = null;
		$get     = static function () use ( &$updater ): ?Updater {
			$updater ??= Updater::create() ?? false;

			return $updater instanceof Updater ? $updater : null;
		};

		$this->loader->add_filter( 'update_plugins_github.com', static fn( $update, $data, $file ) => null === $get() ? $update : $get()->check_update( $update, (array) $data, (string) $file ), 10, 3 );
		$this->loader->add_filter( 'plugins_api', static fn( $result, $action, $args ) => null === $get() ? $result : $get()->plugin_info( $result, (string) $action, $args ), 20, 3 );
	}

	/**
	 * Records what the sensors and integrations collected for the end of the request.
	 *
	 * @return void
	 */
	public function record_pending_activity(): void {
		foreach ( $this->core_sensors() as $sensor ) {
			foreach ( array( 'record_pending', 'record_role_deltas' ) as $method ) {
				if ( method_exists( $sensor, $method ) ) {
					$sensor->$method();
				}
			}
		}

		foreach ( array_keys( $this->integrations()->active() ) as $slug ) {
			$integration = $this->integrations()->get( $slug );

			if ( null !== $integration && method_exists( $integration, 'record_pending' ) ) {
				$integration->record_pending();
			}
		}
	}

	/**
	 * Before the request moves to another site, records what was collected on the one it
	 * leaves, so that activity is stored in that site's log (multisite).
	 *
	 * @param int $new_site_id      Site switched to.
	 * @param int $previous_site_id Site switched from.
	 *
	 * @return void
	 */
	public function on_switch_blog( $new_site_id, $previous_site_id ): void {
		static $running = false;

		if ( $running || (int) $new_site_id === (int) $previous_site_id || ! did_action( 'init' ) ) {
			return;
		}

		$running = true;

		try {
			$this->recorder()->as_site( (int) $previous_site_id, fn() => $this->record_pending_activity() );
		} finally {
			$running = false;
		}
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
		( new NoticeSuppressor( $menu, $this->settings() ) )->register( $this->loader );
		( new ExportHandler( $this->repository(), $this->recorder() ) )->register( $this->loader );
		( new PluginLinks() )->register( $this->loader );
	}
}
