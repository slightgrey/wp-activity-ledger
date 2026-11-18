<?php
/**
 * Email alert formatter.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Alerts\Formatters;

use WPActivityLedger\Alerts\AlertMessage;

/**
 * Builds an email: subject, a simple table-based HTML body (inline styles for mail
 * clients) and a plain-text alternative.
 */
final class EmailFormatter extends Formatter {

	/**
	 * Builds the email.
	 *
	 * @param AlertMessage $alert Alert.
	 *
	 * @return array{subject: string, html: string, text: string} Email.
	 */
	public function format( AlertMessage $alert ): array {
		return array(
			'subject' => self::subject( $alert ),
			'html'    => self::html( $alert ),
			'text'    => self::text( $alert ),
		);
	}

	/**
	 * Builds the HTML body.
	 *
	 * @param AlertMessage $alert Alert.
	 *
	 * @return string HTML.
	 */
	private static function html( AlertMessage $alert ): string {
		$e    = static fn( string $text ): string => htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
		$rows = '';

		foreach ( self::facts( $alert ) as $label => $value ) {
			$rows .= '<tr><td style="padding:4px 12px 4px 0;color:#6b6a66">' . $e( $label ) . '</td><td style="padding:4px 0">' . $e( $value ) . '</td></tr>';
		}

		return '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;font-size:14px;color:#1d2327;max-width:560px">'
			. '<div style="border-left:4px solid ' . $e( self::color( $alert ) ) . ';padding:4px 12px;margin-bottom:12px">'
			. '<div style="font-size:16px;font-weight:600">' . $e( $alert->title ) . '</div>'
			. '<div>' . $e( $alert->message ) . '</div></div>'
			. '<table style="border-collapse:collapse">' . $rows . '</table>'
			. '<p style="margin-top:16px"><a href="' . $e( $alert->log_url ) . '">View in the activity log</a></p>'
			. '<p style="color:#6b6a66;font-size:12px">Sent by WP Activity Ledger on ' . $e( $alert->site_name ) . ' (' . $e( $alert->site_url ) . ').</p>'
			. '</div>';
	}

	/**
	 * Builds the plain-text body.
	 *
	 * @param AlertMessage $alert Alert.
	 *
	 * @return string Text.
	 */
	private static function text( AlertMessage $alert ): string {
		$lines = array( $alert->title, $alert->message, '' );

		foreach ( self::facts( $alert ) as $label => $value ) {
			$lines[] = $label . ': ' . $value;
		}

		$lines[] = '';
		$lines[] = 'View in the activity log: ' . $alert->log_url;

		return implode( "\n", $lines );
	}
}
