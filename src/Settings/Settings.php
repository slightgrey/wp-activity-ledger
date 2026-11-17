<?php
/**
 * Plugin settings.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Settings;

/**
 * Reads and writes the wpal_settings option, always merged with the defaults.
 */
final class Settings {

	/**
	 * Option name.
	 */
	public const OPTION = 'wpal_settings';

	/**
	 * Current settings, merged with defaults.
	 *
	 * @var array<string, mixed>
	 */
	private array $values;

	/**
	 * Creates the settings.
	 *
	 * @param array<string, mixed>|null $stored Stored option value (default: read from the database).
	 */
	public function __construct( ?array $stored = null ) {
		$stored       = $stored ?? get_option( self::OPTION, array() );
		$this->values = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Returns the default settings.
	 *
	 * @return array<string, mixed> Defaults.
	 */
	public static function defaults(): array {
		return array(
			'logged_roles'                => array( '*' ),
			'excluded_users'              => array(),
			'log_anonymous'               => true,
			'disabled_event_types'        => array(),
			'retention_days'              => 90,
			'max_rows'                    => 0,
			'anonymize_ip'                => false,
			'store_user_agent'            => true,
			'trusted_proxy_header'        => '',
			'max_value_size'              => 10240,
			'ignored_option_patterns'     => array( '_transient_*', '_site_transient_*', 'cron', '*_lock', 'rewrite_rules', 'recently_activated', 'active_plugins', 'uninstall_plugins', 'auto_updater.lock', 'can_compress_scripts', '*_cache*', '*_last_checked', 'jetpack_*sync*', 'action_scheduler_*', 'wpal_db_*', 'wpal_github_release' ),
			'ignored_meta_keys'           => array( '_edit_lock', '_edit_last', 'session_tokens', '_wp_old_slug', '_wp_old_date', '_encloseme', '_pingme', '_wp_trash_meta_*', '_wp_desired_post_slug', 'wp_*user-settings*', 'wp_*dashboard_quick_press_last_post_id', 'community-events-location', '_elementor_css', '_elementor_page_assets', '_elementor_element_cache' ),
			'redact_patterns'             => array( '*pass*', '*secret*', '*token*', '*api_key*', '*apikey*', '*license*', '*private_key*', '*auth_key*', '*salt*' ),
			'delete_data_on_uninstall'    => false,
			'dashboard_widget'            => true,
			'failed_login_dedupe_minutes' => 5,
			'view_roles'                  => array(),
			'export_roles'                => array(),
		);
	}

	/**
	 * Returns every setting.
	 *
	 * @return array<string, mixed> Settings.
	 */
	public function all(): array {
		return $this->values;
	}

	/**
	 * Returns one setting.
	 *
	 * @param string $key Setting.
	 *
	 * @return mixed Value, or null when unknown.
	 */
	public function get( string $key ): mixed {
		return $this->values[ $key ] ?? null;
	}

	/**
	 * Validates and saves submitted fields; nothing is saved when any field is invalid.
	 *
	 * @param array<string, mixed> $input Submitted fields.
	 *
	 * @return array<string, string> Errors by field (empty on success).
	 */
	public function update( array $input ): array {
		[ $settings, $errors ] = SettingsValidator::validate( $input, $this->values );

		if ( array() !== $errors ) {
			return $errors;
		}

		$this->values = $settings;
		update_option( self::OPTION, $settings, true );

		return array();
	}
}
