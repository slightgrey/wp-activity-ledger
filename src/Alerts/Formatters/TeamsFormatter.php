<?php
/**
 * Microsoft Teams formatter.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Alerts\Formatters;

use WPActivityLedger\Alerts\AlertMessage;

/**
 * Builds a Teams message carrying an Adaptive Card (accepted by Workflows webhooks and
 * incoming webhooks): title, message, a fact set and a link button.
 */
final class TeamsFormatter extends Formatter {

	/**
	 * Teams text colors by severity.
	 */
	private const TEXT_COLORS = array(
		'info'     => 'Default',
		'notice'   => 'Accent',
		'warning'  => 'Warning',
		'critical' => 'Attention',
	);

	/**
	 * Builds the message.
	 *
	 * @param AlertMessage $alert Alert.
	 *
	 * @return array<string, mixed> Message.
	 */
	public function format( AlertMessage $alert ): array {
		$facts = array();

		foreach ( self::facts( $alert ) as $label => $value ) {
			$facts[] = array(
				'title' => $label,
				'value' => $value,
			);
		}

		return array(
			'type'        => 'message',
			'attachments' => array(
				array(
					'contentType' => 'application/vnd.microsoft.card.adaptive',
					'contentUrl'  => null,
					'content'     => array(
						'$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
						'type'    => 'AdaptiveCard',
						'version' => '1.4',
						'body'    => array(
							array(
								'type'   => 'TextBlock',
								'size'   => 'Medium',
								'weight' => 'Bolder',
								'color'  => self::TEXT_COLORS[ $alert->severity ] ?? 'Default',
								'text'   => self::subject( $alert ),
								'wrap'   => true,
							),
							array(
								'type' => 'TextBlock',
								'text' => $alert->message,
								'wrap' => true,
							),
							array(
								'type'  => 'FactSet',
								'facts' => $facts,
							),
						),
						'actions' => array(
							array(
								'type'  => 'Action.OpenUrl',
								'title' => 'View in the activity log',
								'url'   => $alert->log_url,
							),
						),
					),
				),
			),
		);
	}
}
