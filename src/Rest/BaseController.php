<?php
/**
 * Base REST controller.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Rest;

/**
 * Shared namespace, permission checks and error format for the plugin's REST controllers.
 *
 * Errors are WP_Error objects with an HTTP status and, for validation failures, an
 * "errors" map of field => message in the data.
 */
abstract class BaseController extends \WP_REST_Controller {

	/**
	 * REST namespace.
	 */
	public const NAMESPACE = 'wpal/v1';

	/**
	 * Route namespace.
	 *
	 * @var string
	 */
	protected $namespace = self::NAMESPACE;

	/**
	 * Returns a permission callback that requires a capability.
	 *
	 * Logged-out requests get 401, logged-in users without the capability 403.
	 *
	 * @param string $capability Capability.
	 *
	 * @return \Closure(): (true|\WP_Error) Permission callback.
	 */
	public static function permission( string $capability ): \Closure {
		return static function () use ( $capability ): bool|\WP_Error {
			if ( ! is_user_logged_in() ) {
				return self::error( 'rest_not_logged_in', __( 'You must be logged in.', 'wp-activity-ledger' ), 401 );
			}

			if ( ! current_user_can( $capability ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Plugin capabilities.
				return self::error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'wp-activity-ledger' ), 403 );
			}

			return true;
		};
	}

	/**
	 * Builds an error response.
	 *
	 * @param string               $code    Error code.
	 * @param string               $message Message.
	 * @param int                  $status  HTTP status.
	 * @param array<string, mixed> $data    Extra data (e.g. "errors" => field messages).
	 *
	 * @return \WP_Error Error.
	 */
	public static function error( string $code, string $message, int $status, array $data = array() ): \WP_Error {
		return new \WP_Error( $code, $message, array_merge( $data, array( 'status' => $status ) ) );
	}

	/**
	 * Returns the schema of a date argument ("Y-m-d" or "Y-m-d H:i:s").
	 *
	 * @param string $description Description.
	 *
	 * @return array<string, mixed> Argument schema.
	 */
	protected static function date_arg( string $description ): array {
		return array(
			'description' => $description,
			'type'        => 'string',
			'pattern'     => '^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$',
		);
	}
}
