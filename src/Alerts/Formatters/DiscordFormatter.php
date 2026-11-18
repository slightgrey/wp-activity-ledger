<?php
/**
 * Discord formatter.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Alerts\Formatters;

use WPActivityLedger\Alerts\AlertMessage;

/**
 * Builds a Discord webhook message with one embed (severity color, inline fields).
 */
final class DiscordFormatter extends Formatter {

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
				'name'   => $label,
				'value'  => mb_substr( $value, 0, 1024 ),
				'inline' => true,
			);
		}

		$embed = array(
			'title'       => mb_substr( self::subject( $alert ), 0, 256 ),
			'description' => mb_substr( $alert->message, 0, 4096 ),
			'url'         => $alert->log_url,
			'color'       => (int) hexdec( ltrim( self::color( $alert ), '#' ) ),
			'fields'      => $fields,
			'footer'      => array( 'text' => 'WP Activity Ledger · ' . $alert->site_name ),
		);

		if ( '' !== $alert->created_at ) {
			$embed['timestamp'] = str_replace( ' ', 'T', $alert->created_at ) . 'Z';
		}

		return array(
			'username'         => 'Activity Ledger',
			'embeds'           => array( $embed ),
			'allowed_mentions' => array( 'parse' => array() ),
		);
	}
}
