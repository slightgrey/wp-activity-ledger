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
				'value' => self::escape( $value ),
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
								'text'   => self::escape( self::subject( $alert ) ),
								'wrap'   => true,
							),
							array(
								'type' => 'TextBlock',
								'text' => self::escape( $alert->message ),
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

	/**
	 * Escapes the Markdown that Adaptive Card text renders (links, emphasis, code, HTML-like
	 * tags), so values from users and site content, like a failed-login username, can't
	 * show up as a disguised link.
	 *
	 * @param string $text Text.
	 *
	 * @return string Escaped text.
	 */
	private static function escape( string $text ): string {
		return (string) preg_replace( '/([\\\\`*_\[\]<>])/', '\\\\$1', $text );
	}
}
