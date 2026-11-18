<?php
/**
 * Integration request REST controller.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Rest;

use WPActivityLedger\Security\Capabilities;
use WPActivityLedger\Support\Author;

/**
 * POST /integration-request: emails a request for a new plugin integration to the author
 * (Author::REQUEST_EMAIL) with the site's wp_mail(). The requester's address is the
 * Reply-To. The site address and the Activity Ledger and WordPress versions are included,
 * as the form says. Each user may send a few requests per hour.
 */
final class IntegrationRequestController extends BaseController {

	/**
	 * Requests allowed per user per hour.
	 */
	public const LIMIT = 3;

	/**
	 * Transient prefix of the per-user counter.
	 */
	private const COUNTER = 'wpal_intreq_';

	/**
	 * Creates the controller.
	 */
	public function __construct() {
		$this->rest_base = 'integration-request';
	}

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send' ),
				'permission_callback' => array( self::class, 'can_send' ),
				'args'                => array(
					'name'       => array(
						'type'      => 'string',
						'maxLength' => 100,
						'default'   => '',
					),
					'email'      => array(
						'type'     => 'string',
						'required' => true,
					),
					'plugin'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'plugin_url' => array(
						'type'    => 'string',
						'default' => '',
					),
					'message'    => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Allows users who can see the settings (also read-only under a network lock).
	 *
	 * @return bool|\WP_Error True, or an error.
	 */
	public static function can_send(): bool|\WP_Error {
		if ( ! is_user_logged_in() ) {
			return self::error( 'rest_not_logged_in', __( 'You must be logged in.', 'wp-activity-ledger' ), 401 );
		}

		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Plugin capability.
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) && ! current_user_can( 'manage_options' ) ) {
			return self::error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'wp-activity-ledger' ), 403 );
		}

		return true;
	}

	/**
	 * Validates the form and sends the email.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error Response ({ sent: true }), 400 with field errors, 429 or 502.
	 */
	public function send( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		[ $fields, $errors ] = self::validate( $request );

		if ( array() !== $errors ) {
			return self::error( 'wpal_invalid_request', __( 'Check the highlighted fields.', 'wp-activity-ledger' ), 400, array( 'errors' => $errors ) );
		}

		$key   = self::COUNTER . get_current_user_id();
		$count = (int) get_transient( $key );

		if ( $count >= self::LIMIT ) {
			return self::error( 'wpal_too_many_requests', __( 'You have sent several requests in the last hour. Please try again later.', 'wp-activity-ledger' ), 429 );
		}

		$name    = '' !== $fields['name'] ? $fields['name'] : $fields['email'];
		$headers = array( 'Reply-To: ' . self::header_safe( $name ) . ' <' . $fields['email'] . '>' );
		/* translators: %s: plugin name. */
		$subject = sprintf( __( '[Activity Ledger] Integration request: %s', 'wp-activity-ledger' ), $fields['plugin'] );

		if ( ! wp_mail( Author::REQUEST_EMAIL, self::header_safe( $subject ), self::body( $fields ), $headers ) ) {
			return self::error( 'wpal_mail_failed', __( 'This site could not send the email.', 'wp-activity-ledger' ), 502, array( 'to' => Author::REQUEST_EMAIL ) );
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return rest_ensure_response( array( 'sent' => true ) );
	}

	/**
	 * Returns the email body.
	 *
	 * @param array{name: string, email: string, plugin: string, plugin_url: string, message: string} $fields Fields.
	 *
	 * @return string Plain text.
	 */
	public static function body( array $fields ): string {
		global $wp_version;

		$lines = array(
			'Plugin: ' . $fields['plugin'],
			'Plugin link: ' . ( '' !== $fields['plugin_url'] ? $fields['plugin_url'] : '-' ),
			'',
			'Message:',
			'' !== $fields['message'] ? $fields['message'] : '-',
			'',
			'From: ' . ( '' !== $fields['name'] ? $fields['name'] . ' <' . $fields['email'] . '>' : $fields['email'] ),
			'Site: ' . home_url( '/' ),
			'Activity Ledger: ' . WPAL_VERSION,
			'WordPress: ' . $wp_version,
		);

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Validates and cleans the submitted fields.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return array{0: array{name: string, email: string, plugin: string, plugin_url: string, message: string}, 1: array<string, string>} Fields and errors by field.
	 */
	public static function validate( \WP_REST_Request $request ): array {
		$errors = array();
		$fields = array(
			'name'       => mb_substr( trim( sanitize_text_field( (string) $request['name'] ) ), 0, 100 ),
			'email'      => trim( sanitize_email( (string) $request['email'] ) ),
			'plugin'     => mb_substr( trim( sanitize_text_field( (string) $request['plugin'] ) ), 0, 100 ),
			'plugin_url' => trim( (string) $request['plugin_url'] ),
			'message'    => mb_substr( trim( sanitize_textarea_field( (string) $request['message'] ) ), 0, 2000 ),
		);

		if ( ! is_email( $fields['email'] ) ) {
			$errors['email'] = __( 'Enter a valid email address so I can reply.', 'wp-activity-ledger' );
		}

		if ( '' === $fields['plugin'] ) {
			$errors['plugin'] = __( 'Enter the plugin you would like supported.', 'wp-activity-ledger' );
		}

		if ( '' !== $fields['plugin_url'] ) {
			$url = esc_url_raw( $fields['plugin_url'], array( 'http', 'https' ) );

			if ( '' === $url || false === wp_http_validate_url( $url ) ) {
				$errors['plugin_url'] = __( 'Enter a full link starting with https://, or leave it empty.', 'wp-activity-ledger' );
			}

			$fields['plugin_url'] = $url;
		}

		return array( $fields, $errors );
	}

	/**
	 * Removes line breaks so a value can't add email headers.
	 *
	 * @param string $value Value.
	 *
	 * @return string Single-line value.
	 */
	private static function header_safe( string $value ): string {
		return trim( (string) preg_replace( '/[\r\n]+/', ' ', $value ) );
	}
}
