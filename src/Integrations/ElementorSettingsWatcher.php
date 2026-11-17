<?php
/**
 * Elementor settings activity.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Integrations;

use WPActivityLedger\Diff\Differ;
use WPActivityLedger\Domain\Change;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Loader;
use WPActivityLedger\Recording\Recorder;
use WPActivityLedger\Sensors\OptionFilter;
use WPActivityLedger\Support\PatternMatcher;

/**
 * Records changes of "elementor_*" options: one "elementor.settings_changed" event per
 * request, and Elementor Pro's theme-builder display conditions as their own event.
 *
 * Options Elementor maintains on its own (logs, versions, caches, remote data) are ignored.
 */
final class ElementorSettingsWatcher {

	/**
	 * Elementor Pro's theme-builder display conditions.
	 */
	public const CONDITIONS_OPTION = 'elementor_pro_theme_builder_conditions';

	/**
	 * Options Elementor rewrites on its own (never recorded).
	 */
	public const NOISE_OPTIONS = array(
		'elementor_log',
		'elementor_version',
		'elementor_pro_version',
		'elementor_*db_version',
		'elementor_*_assets_data',
		'elementor_remote_info_*',
		'elementor_pro_remote_info_*',
		'elementor_atomic_cache_validity_*',
		'elementor_*install_history',
		'elementor_onboard*',
		'elementor_checklist',
		'elementor_angie_*',
		'elementor_connect_*',
		'elementor_controls_usage',
		'elementor_events_*',
		'elementor_tracker_*',
		'elementor_landing_pages_activation',
		'elementor_custom_icon_sets_config',
		'elementor_fonts_manager_*',
		'elementor_pro_license_data*',
		'elementor_scheme_*',
		'elementor_library_category_*',
	);

	/**
	 * Collects the Elementor option changes.
	 *
	 * @var OptionCollector
	 */
	private OptionCollector $options;

	/**
	 * Creates the watcher.
	 *
	 * @param Recorder     $recorder Recorder.
	 * @param Differ       $differ   Differ.
	 * @param OptionFilter $noise    Decides which options are noise.
	 */
	public function __construct(
		private readonly Recorder $recorder,
		private readonly Differ $differ,
		OptionFilter $noise
	) {
		$own = new PatternMatcher( self::NOISE_OPTIONS );

		$this->options = new OptionCollector(
			static fn( string $option ): bool => str_starts_with( $option, 'elementor_' ),
			static fn( string $option ): bool => ! $own->matches( $option ) && ! $noise->is_noise( $option )
		);
	}

	/**
	 * Queues the hooks.
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$this->options->register( $loader );
	}

	/**
	 * Records the collected changes (called by the integration on shutdown).
	 *
	 * @return void
	 */
	public function record_pending(): void {
		$settings   = array();
		$conditions = array();

		foreach ( $this->options->take() as $option => [ $old_value, $new_value ] ) {
			$changes = $this->differ->diff_value( $option, $old_value, $new_value );

			if ( self::CONDITIONS_OPTION === $option ) {
				$conditions = $changes;
				continue;
			}

			array_push( $settings, ...$changes );
		}

		$this->record( 'elementor.settings_changed', $settings );
		$this->record( 'elementor.conditions_changed', $conditions );
	}

	/**
	 * Records one settings event, unless there are no changes.
	 *
	 * @param string             $type    Event type.
	 * @param array<int, Change> $changes Changes.
	 *
	 * @return void
	 */
	private function record( string $type, array $changes ): void {
		if ( array() === $changes ) {
			return;
		}

		$this->recorder->record(
			new Event(
				$type,
				object_type: 'settings',
				object_id: 'elementor',
				object_label: 'Elementor',
				context: array( 'count' => count( $changes ) ),
				changes: $changes
			)
		);
	}
}
