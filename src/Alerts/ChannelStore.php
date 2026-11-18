<?php
/**
 * Alert channels.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Alerts;

/**
 * Alert channels (option "wpal_alert_channels"): { id, type, name, config }.
 *
 * Types and their config: email { recipients[] }, webhook { url, secret }, slack, discord
 * and teams { url }. Webhook URLs and secrets are credentials: they are masked when read
 * through the API, and a masked value sent back keeps the stored one.
 */
final class ChannelStore extends ListStore {

	/**
	 * Option name.
	 */
	public const OPTION = 'wpal_alert_channels';

	/**
	 * Channel types.
	 */
	public const TYPES = array( 'email', 'webhook', 'slack', 'discord', 'teams' );

	/**
	 * Config fields that are credentials, by type.
	 */
	private const SECRETS = array(
		'webhook' => array( 'url', 'secret' ),
		'slack'   => array( 'url' ),
		'discord' => array( 'url' ),
		'teams'   => array( 'url' ),
	);

	/**
	 * Character that marks a masked value.
	 */
	private const MASK = '•';

	/**
	 * Returns the option name.
	 *
	 * @return string Option.
	 */
	protected function option(): string {
		return self::OPTION;
	}

	/**
	 * Returns a channel with its credentials masked.
	 *
	 * @param array<string, mixed> $channel Channel.
	 *
	 * @return array<string, mixed> Masked channel.
	 */
	public static function masked( array $channel ): array {
		foreach ( self::SECRETS[ $channel['type'] ?? '' ] ?? array() as $key ) {
			if ( isset( $channel['config'][ $key ] ) && '' !== $channel['config'][ $key ] ) {
				$channel['config'][ $key ] = self::mask( (string) $channel['config'][ $key ], 'url' === $key );
			}
		}

		return $channel;
	}

	/**
	 * Masks a credential: URLs keep their scheme and host, all keep their last four characters.
	 *
	 * @param string $value  Value.
	 * @param bool   $is_url Whether it is a URL.
	 *
	 * @return string Masked value.
	 */
	public static function mask( string $value, bool $is_url ): string {
		$prefix = '';

		if ( $is_url ) {
			$parts  = wp_parse_url( $value );
			$prefix = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . '/';
		}

		return $prefix . str_repeat( self::MASK, 8 ) . substr( $value, -4 );
	}

	/**
	 * Validates a channel.
	 *
	 * @param array<string, mixed>      $input    Submitted fields.
	 * @param array<string, mixed>|null $existing Stored channel.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, string>} Channel and errors.
	 */
	protected function sanitize( array $input, ?array $existing ): array {
		$errors = array();
		$type   = (string) self::field( $input, $existing, 'type', '' );

		if ( ! in_array( $type, self::TYPES, true ) ) {
			return array( array(), array( 'type' => __( 'Choose a channel type.', 'wp-activity-ledger' ) ) );
		}

		$stored = null !== $existing && ( $existing['type'] ?? '' ) === $type ? (array) ( $existing['config'] ?? array() ) : array();
		$config = array_merge( $stored, is_array( $input['config'] ?? null ) ? $input['config'] : array() );
		$config = $this->keep_masked( $type, $config, $stored );

		$channel = array(
			'type'   => $type,
			'name'   => self::name( self::field( $input, $existing, 'name', '' ), $errors ),
			'config' => 'email' === $type ? self::email_config( $config, $errors ) : self::url_config( $type, $config, $errors ),
		);

		return array( $channel, $errors );
	}

	/**
	 * Replaces masked values sent back by the stored originals.
	 *
	 * @param string               $type   Channel type.
	 * @param array<string, mixed> $config Submitted config.
	 * @param array<string, mixed> $stored Stored config.
	 *
	 * @return array<string, mixed> Config.
	 */
	private function keep_masked( string $type, array $config, array $stored ): array {
		foreach ( self::SECRETS[ $type ] ?? array() as $key ) {
			if ( is_string( $config[ $key ] ?? null ) && str_contains( $config[ $key ], self::MASK ) ) {
				$config[ $key ] = $stored[ $key ] ?? '';
			}
		}

		return $config;
	}

	/**
	 * Validates an email channel's recipients.
	 *
	 * @param array<string, mixed>  $config Config.
	 * @param array<string, string> $errors Errors (added to).
	 *
	 * @return array{recipients: array<int, string>} Config.
	 */
	private static function email_config( array $config, array &$errors ): array {
		$raw        = $config['recipients'] ?? array();
		$list       = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$recipients = array();

		foreach ( $list as $address ) {
			$address = sanitize_email( trim( (string) $address ) );

			if ( '' !== $address && is_email( $address ) ) {
				$recipients[] = $address;
			}
		}

		if ( array() === $recipients ) {
			$errors['config.recipients'] = __( 'Enter at least one valid email address.', 'wp-activity-ledger' );
		}

		return array( 'recipients' => array_values( array_unique( array_slice( $recipients, 0, 20 ) ) ) );
	}

	/**
	 * Validates a webhook-style channel: a URL, and a signing secret for generic webhooks.
	 *
	 * @param string                $type   Channel type.
	 * @param array<string, mixed>  $config Config.
	 * @param array<string, string> $errors Errors (added to).
	 *
	 * @return array<string, string> Config.
	 */
	private static function url_config( string $type, array $config, array &$errors ): array {
		$url = esc_url_raw( trim( (string) ( $config['url'] ?? '' ) ), array( 'http', 'https' ) );

		if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			$errors['config.url'] = __( 'Enter a valid http(s) URL.', 'wp-activity-ledger' );
		}

		if ( 'webhook' !== $type ) {
			return array( 'url' => $url );
		}

		$secret = trim( (string) ( $config['secret'] ?? '' ) );

		return array(
			'url'    => $url,
			'secret' => '' === $secret ? wp_generate_password( 32, false ) : mb_substr( sanitize_text_field( $secret ), 0, 200 ),
		);
	}
}
