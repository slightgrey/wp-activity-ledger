<?php
/**
 * Slack formatter.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Alerts\Formatters;

use WPActivityLedger\Alerts\AlertMessage;

/**
 * Builds a Slack incoming-webhook message with Block Kit: header, message, facts, button.
 */
final class SlackFormatter extends Formatter {

	/**
	 * Builds the message.
	 *
	 * @param AlertMessage $alert Alert.
	 *
	 * @return array<string, mixed> Message.
	 */
	public function format( AlertMessage $alert ): array {
		$fields = array();

		foreach ( self::facts( $alert ) as $label => $value ) {
			$fields[] = array(
				'type' => 'mrkdwn',
				'text' => '*' . $label . "*\n" . self::escape( $value ),
			);
		}

		return array(
			'text'   => self::subject( $alert ) . ': ' . $alert->message,
			'blocks' => array(
				array(
					'type' => 'header',
					'text' => array(
						'type' => 'plain_text',
						'text' => mb_substr( self::subject( $alert ), 0, 150 ),
					),
				),
				array(
					'type' => 'section',
					'text' => array(
						'type' => 'mrkdwn',
						'text' => self::escape( $alert->message ),
					),
				),
				array(
					'type'   => 'section',
					'fields' => array_slice( $fields, 0, 10 ),
				),
				array(
					'type'     => 'actions',
					'elements' => array(
						array(
							'type' => 'button',
							'text' => array(
								'type' => 'plain_text',
								'text' => 'View in the activity log',
							),
							'url'  => $alert->log_url,
						),
					),
				),
			),
		);
	}

	/**
	 * Escapes Slack's control characters.
	 *
	 * @param string $text Text.
	 *
	 * @return string Escaped text.
	 */
	private static function escape( string $text ): string {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $text );
	}
}
