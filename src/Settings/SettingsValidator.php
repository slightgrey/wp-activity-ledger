<?php
/**
 * Validation of plugin settings.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Settings;

use WPActivityLedger\Domain\EventType;
use WPActivityLedger\Support\IpResolver;

/**
 * Validates submitted settings field by field.
 *
 * Numbers that are not numeric, toggles that are not boolean-like and unknown proxy
 * headers are errors; numbers outside their range are clamped; invalid items in
 * lists are dropped; unknown keys are ignored.
 */
final class SettingsValidator {

	/**
	 * Integer fields: key => [min, max].
	 */
	public const INTEGERS = array(
		'retention_days'              => array( 0, 3650 ),
		'max_rows'                    => array( 0, 100000000 ),
		'max_value_size'              => array( 1024, 1048576 ),
		'failed_login_dedupe_minutes' => array( 0, 1440 ),
	);

	/**
	 * Boolean fields.
	 */
	public const BOOLEANS = array( 'log_anonymous', 'anonymize_ip', 'store_user_agent', 'delete_data_on_uninstall', 'dashboard_widget' );

	/**
	 * Role list fields ("*" allowed in logged_roles only).
	 */
	public const ROLE_LISTS = array( 'logged_roles', 'view_roles', 'export_roles' );

	/**
	 * Glob pattern list fields.
	 */
	public const PATTERN_LISTS = array( 'ignored_option_patterns', 'ignored_meta_keys', 'redact_patterns' );

	/**
	 * Validates the submitted fields on top of a base (current) settings array.
	 *
	 * @param array<string, mixed> $input Submitted fields.
	 * @param array<string, mixed> $base  Current settings, used for fields not submitted.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, string>} [settings, errors by field].
	 */
	public static function validate( array $input, array $base ): array {
		$settings = $base;
		$errors   = array();

		foreach ( $input as $key => $value ) {
			$result = self::field( (string) $key, $value );

			if ( null === $result ) {
				continue;
			}

			if ( is_string( $result[1] ) ) {
				$errors[ (string) $key ] = $result[1];
				continue;
			}

			$settings[ (string) $key ] = $result[0];
		}

		return array( $settings, $errors );
	}

	/**
	 * Validates one field.
	 *
	 * @param string $key   Field.
	 * @param mixed  $value Submitted value.
	 *
	 * @return array{0: mixed, 1: string|null}|null [clean value, error], or null for unknown keys.
	 */
	private static function field( string $key, mixed $value ): ?array {
		return match ( true ) {
			isset( self::INTEGERS[ $key ] )             => self::integer( $value, ...self::INTEGERS[ $key ] ),
			in_array( $key, self::BOOLEANS, true )      => self::boolean( $value ),
			in_array( $key, self::ROLE_LISTS, true )    => self::list_of( $value, static fn( string $v ): bool => 1 === preg_match( '/^[a-z0-9_-]{1,60}$/', $v ) || ( '*' === $v && 'logged_roles' === $key ) ),
			in_array( $key, self::PATTERN_LISTS, true ) => self::list_of( $value, static fn( string $v ): bool => 1 === preg_match( '/^[A-Za-z0-9_*?.:\-]{1,100}$/', $v ) ),
			'excluded_users' === $key                   => self::user_ids( $value ),
			'disabled_event_types' === $key             => self::list_of( $value, array( EventType::class, 'is_valid_key' ) ),
			'trusted_proxy_header' === $key             => self::header( $value ),
			default                                     => null,
		};
	}

	/**
	 * Validates a bounded integer.
	 *
	 * @param mixed $value Value.
	 * @param int   $min   Minimum.
	 * @param int   $max   Maximum.
	 *
	 * @return array{0: int|null, 1: string|null} Result.
	 */
	private static function integer( mixed $value, int $min, int $max ): array {
		if ( ! is_numeric( $value ) ) {
			return array( null, __( 'Must be a number.', 'wp-activity-ledger' ) );
		}

		return array( min( $max, max( $min, (int) $value ) ), null );
	}

	/**
	 * Validates a boolean-like value (true/false, 1/0, "yes"/"no", "on"/"off").
	 *
	 * @param mixed $value Value.
	 *
	 * @return array{0: bool|null, 1: string|null} Result.
	 */
	private static function boolean( mixed $value ): array {
		$bool = is_bool( $value ) ? $value : filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

		return null === $bool ? array( null, __( 'Must be on or off.', 'wp-activity-ledger' ) ) : array( $bool, null );
	}

	/**
	 * Validates a list of strings, keeping valid, unique, trimmed items (max 200).
	 *
	 * @param mixed                 $value Value (array, or a newline/comma separated string).
	 * @param callable(string):bool $valid Item validator.
	 *
	 * @return array{0: array<int, string>|null, 1: string|null} Result.
	 */
	private static function list_of( mixed $value, callable $valid ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value );
		}

		if ( ! is_array( $value ) ) {
			return array( null, __( 'Must be a list.', 'wp-activity-ledger' ) );
		}

		$clean = array();

		foreach ( $value as $item ) {
			$item = is_scalar( $item ) ? trim( (string) $item ) : '';

			if ( '' !== $item && $valid( $item ) ) {
				$clean[ $item ] = $item;
			}
		}

		return array( array_slice( array_values( $clean ), 0, 200 ), null );
	}

	/**
	 * Validates a list of user IDs.
	 *
	 * @param mixed $value Value.
	 *
	 * @return array{0: array<int, int>|null, 1: string|null} Result.
	 */
	private static function user_ids( mixed $value ): array {
		[ $items, $error ] = self::list_of( $value, static fn( string $v ): bool => ctype_digit( $v ) && (int) $v > 0 );

		return null === $items ? array( null, $error ) : array( array_map( 'intval', $items ), null );
	}

	/**
	 * Validates the trusted proxy header.
	 *
	 * @param mixed $value Value.
	 *
	 * @return array{0: string|null, 1: string|null} Result.
	 */
	private static function header( mixed $value ): array {
		if ( '' === $value || null === $value ) {
			return array( '', null );
		}

		if ( is_string( $value ) && in_array( $value, IpResolver::ALLOWED_HEADERS, true ) ) {
			return array( $value, null );
		}

		return array( null, __( 'Unknown proxy header.', 'wp-activity-ledger' ) );
	}
}
