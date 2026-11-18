<?php
/**
 * Shared helpers for alert formatters.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Alerts\Formatters;

use WPActivityLedger\Alerts\AlertMessage;

/**
 * Helpers the channel formatters share. Formatters are pure: no WordPress calls.
 */
abstract class Formatter {

	/**
	 * Severity colors (status palette): slug => hex.
	 */
	protected const COLORS = array(
		'info'     => '#6b6a66',
		'notice'   => '#2a78d6',
		'warning'  => '#fab219',
		'critical' => '#d03b3b',
	);

	/**
	 * Builds the payload for a channel.
	 *
	 * @param AlertMessage $alert Alert.
	 *
	 * @return array<string, mixed> Payload.
	 */
	abstract public function format( AlertMessage $alert ): array;

	/**
	 * Returns the facts shown in every alert, without empty ones.
	 *
	 * @param AlertMessage $alert Alert.
	 *
	 * @return array<string, string> Label => value.
	 */
	protected static function facts( AlertMessage $alert ): array {
		return array_filter(
			array(
				'Severity' => $alert->severity,
				'Category' => $alert->category,
				'User'     => '' === $alert->user ? 'System' : $alert->user,
				'Object'   => $alert->object_label,
				'IP'       => $alert->ip,
				'Time'     => '' === $alert->created_at ? '' : $alert->created_at . ' UTC',
				'Rule'     => $alert->rule,
			),
			static fn( string $value ): bool => '' !== $value
		);
	}

	/**
	 * Returns the severity color of an alert.
	 *
	 * @param AlertMessage $alert Alert.
	 *
	 * @return string Hex color.
	 */
	protected static function color( AlertMessage $alert ): string {
		return self::COLORS[ $alert->severity ] ?? self::COLORS['info'];
	}

	/**
	 * Returns the subject/title line: "[Site] Title".
	 *
	 * @param AlertMessage $alert Alert.
	 *
	 * @return string Subject.
	 */
	protected static function subject( AlertMessage $alert ): string {
		return '[' . $alert->site_name . '] ' . $alert->title;
	}
}
