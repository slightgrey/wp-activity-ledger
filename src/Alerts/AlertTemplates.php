<?php
/**
 * Built-in alert rule templates.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Alerts;

/**
 * Seeds the built-in rules on activation, disabled and without channels, so admins only
 * have to pick a channel and switch them on. Idempotent: a template is added once (by its
 * "template" slug), and rules the admin deleted are not re-added on reactivation.
 */
final class AlertTemplates {

	/**
	 * Option listing the templates already installed.
	 */
	public const INSTALLED_OPTION = 'wpal_alert_templates_installed';

	/**
	 * Returns the templates: slug => rule fields.
	 *
	 * @return array<string, array<string, mixed>> Templates.
	 */
	public static function all(): array {
		return array(
			'new-admin'          => array(
				'name'  => __( 'New administrator created', 'wp-activity-ledger' ),
				'match' => array(
					'event_types'  => array( 'user.created' ),
					'min_severity' => 3,
				),
			),
			'promoted-admin'     => array(
				'name'  => __( 'User promoted to administrator', 'wp-activity-ledger' ),
				'match' => array(
					'event_types'  => array( 'user.role_changed', 'user.role_added', 'user.super_admin_granted' ),
					'min_severity' => 3,
				),
			),
			'plugin-changes'     => array(
				'name'  => __( 'Plugin installed, activated or deleted', 'wp-activity-ledger' ),
				'match' => array( 'event_types' => array( 'plugin.installed', 'plugin.activated', 'plugin.deleted' ) ),
			),
			'ledger-deactivated' => array(
				'name'  => __( 'Activity Ledger deactivated', 'wp-activity-ledger' ),
				'match' => array( 'event_types' => array( 'system.deactivated' ) ),
			),
			'logs-purged'        => array(
				'name'  => __( 'Activity logs purged', 'wp-activity-ledger' ),
				'match' => array( 'event_types' => array( 'system.logs_purged' ) ),
			),
			'brute-force'        => array(
				'name'      => __( 'Possible brute-force attack (5 failed logins in 10 minutes from one IP)', 'wp-activity-ledger' ),
				'match'     => array( 'event_types' => array( 'auth.login_failed' ) ),
				'threshold' => array(
					'count'    => 5,
					'minutes'  => 10,
					'group_by' => 'ip',
				),
			),
			'file-edited'        => array(
				'name'  => __( 'Plugin or theme file edited', 'wp-activity-ledger' ),
				'match' => array( 'event_types' => array( 'plugin.file_edited', 'theme.file_edited' ) ),
			),
		);
	}

	/**
	 * Adds the templates not installed before.
	 *
	 * @param RuleStore $rules Rules.
	 *
	 * @return int Templates added.
	 */
	public static function install( RuleStore $rules ): int {
		$installed = (array) get_option( self::INSTALLED_OPTION, array() );
		$present   = array_column( $rules->all(), 'template' );
		$added     = 0;

		foreach ( self::all() as $slug => $template ) {
			if ( in_array( $slug, $installed, true ) || in_array( $slug, $present, true ) ) {
				continue;
			}

			[ $rule ] = $rules->create(
				array_merge(
					$template,
					array(
						'enabled'  => false,
						'channels' => array(),
						'template' => $slug,
					)
				)
			);

			if ( null !== $rule ) {
				$installed[] = $slug;
				++$added;
			}
		}

		update_option( self::INSTALLED_OPTION, array_values( array_unique( $installed ) ), false );

		return $added;
	}
}
