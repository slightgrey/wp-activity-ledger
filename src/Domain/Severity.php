<?php
/**
 * Event severity levels.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Domain;

/**
 * How important an event is. Stored as a TINYINT in wpal_events.severity.
 */
enum Severity: int {

	case Info     = 0;
	case Notice   = 1;
	case Warning  = 2;
	case Critical = 3;

	/**
	 * Returns the translated label.
	 *
	 * @return string Label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Info     => __( 'Info', 'wp-activity-ledger' ),
			self::Notice   => __( 'Notice', 'wp-activity-ledger' ),
			self::Warning  => __( 'Warning', 'wp-activity-ledger' ),
			self::Critical => __( 'Critical', 'wp-activity-ledger' ),
		};
	}

	/**
	 * Returns the lowercase slug used by the admin app and filters.
	 *
	 * @return string Slug, e.g. "critical".
	 */
	public function slug(): string {
		return strtolower( $this->name );
	}

	/**
	 * Finds a severity by its slug.
	 *
	 * @param string $slug Slug, e.g. "warning".
	 *
	 * @return Severity|null The severity, or null when unknown.
	 */
	public static function from_slug( string $slug ): ?Severity {
		foreach ( self::cases() as $severity ) {
			if ( $severity->slug() === strtolower( $slug ) ) {
				return $severity;
			}
		}

		return null;
	}
}
