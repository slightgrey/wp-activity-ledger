<?php
/**
 * Generic webhook formatter.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Alerts\Formatters;

use WPActivityLedger\Alerts\AlertMessage;

/**
 * Builds the JSON body of a generic webhook: a versioned envelope with the event, the
 * rule and the site.
 */
final class WebhookFormatter extends Formatter {

	/**
	 * Builds the body.
	 *
	 * @param AlertMessage $alert Alert.
	 *
	 * @return array<string, mixed> Body.
	 */
	public function format( AlertMessage $alert ): array {
		return array(
			'version' => 1,
			'type'    => 'wpal.alert',
			'rule'    => $alert->rule,
			'event'   => array(
				'id'         => $alert->event_id,
				'type'       => $alert->event_type,
				'label'      => $alert->title,
				'message'    => $alert->message,
				'severity'   => $alert->severity,
				'category'   => $alert->category,
				'user'       => $alert->user,
				'ip'         => $alert->ip,
				'object'     => $alert->object_label,
				'created_at' => $alert->created_at,
				'url'        => $alert->log_url,
			),
			'site'    => array(
				'name' => $alert->site_name,
				'url'  => $alert->site_url,
			),
		);
	}
}
