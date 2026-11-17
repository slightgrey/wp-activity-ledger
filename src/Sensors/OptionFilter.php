<?php
/**
 * Decides which options are noise.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Sensors;

use WPActivityLedger\Settings\Settings;
use WPActivityLedger\Support\FilterValue;
use WPActivityLedger\Support\PatternMatcher;

/**
 * Matches options that are never recorded as changes: Activity Ledger's own, values that
 * WordPress or common plugins rewrite on their own (transients, cron, caches), and the
 * patterns from the settings and the wpal_ignored_option_patterns filter.
 *
 * The built-in list is in code, not settings, so updates of it reach existing sites.
 */
final class OptionFilter {

	/**
	 * Built-in noise patterns.
	 */
	public const NOISE = array(
		'wpal_*',
		'_transient_*',
		'_site_transient_*',
		'cron',
		'*_lock',
		'rewrite_rules',
		'user_count',
		'recently_activated',
		'active_plugins',
		'uninstall_plugins',
		'db_upgraded',
		'finished_updating_comment_type',
		'auto_core_update_*',
		'can_compress_scripts',
		'*_last_checked',
		'*_cache*',
		'action_scheduler_*',
		'jetpack_*sync*',
		'_elementor_*',
		'*_batch_*', // WP background-processing queues.
	);

	/**
	 * Matcher, built on first use.
	 *
	 * @var PatternMatcher|null
	 */
	private ?PatternMatcher $matcher = null;

	/**
	 * Creates the filter.
	 *
	 * @param Settings $settings Settings (ignored option patterns).
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * Tells whether an option is noise.
	 *
	 * @param string $option Option name.
	 *
	 * @return bool True when the option is never recorded.
	 */
	public function is_noise( string $option ): bool {
		return '' === $option || $this->matcher()->matches( $option );
	}

	/**
	 * Returns the matcher.
	 *
	 * @return PatternMatcher Matcher.
	 */
	private function matcher(): PatternMatcher {
		if ( null === $this->matcher ) {
			$defaults = array_map( 'strval', (array) $this->settings->get( 'ignored_option_patterns' ) );

			/**
			 * Filters the ignored option name patterns.
			 *
			 * @param array<int, string> $patterns Glob patterns.
			 */
			$patterns      = FilterValue::strings( apply_filters( 'wpal_ignored_option_patterns', $defaults ), $defaults );
			$this->matcher = new PatternMatcher( array_merge( self::NOISE, $patterns ) );
		}

		return $this->matcher;
	}
}
