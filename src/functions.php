<?php
/**
 * Public developer API.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

use WPActivityLedger\Domain\Change;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Domain\EventType;
use WPActivityLedger\Plugin;

if ( ! function_exists( 'wpal_log' ) ) {
	/**
	 * Records a custom activity event.
	 *
	 * Example:
	 *     wpal_log( 'acme.invoice_sent', array(
	 *         'object_type'  => 'invoice',
	 *         'object_id'    => 42,
	 *         'object_label' => 'INV-0042',
	 *         'severity'     => 1,
	 *         'context'      => array( 'amount' => '99.00' ),
	 *         'changes'      => array( 'status' => array( 'draft', 'sent' ) ),
	 *     ) );
	 *
	 * Or, without a hard dependency on this plugin: do_action( 'wpal_log', 'acme.invoice_sent', $args ).
	 * Register the type with the wpal_event_types filter to give it a label and message.
	 *
	 * @param string               $event_type Type key, "area.action" (lowercase, dot-separated).
	 * @param array<string, mixed> $args       object_type, object_id, object_label, severity, context,
	 *                                         changes ([field => [old, new]] or Change objects), user_id.
	 *
	 * @return void
	 */
	function wpal_log( string $event_type, array $args = array() ): void {
		if ( ! EventType::is_valid_key( $event_type ) ) {
			_doing_it_wrong( __FUNCTION__, esc_html( "Invalid event type \"$event_type\"; use lowercase \"area.action\"." ), '0.1.0' );
			return;
		}

		Plugin::instance()->recorder()->record( wpal_build_event( $event_type, $args ) );
	}
}

if ( ! function_exists( 'wpal_build_event' ) ) {
	/**
	 * Builds an Event from wpal_log() arguments, ignoring unknown keys.
	 *
	 * @param string               $event_type Type key.
	 * @param array<string, mixed> $args       Arguments.
	 *
	 * @return Event Event.
	 */
	function wpal_build_event( string $event_type, array $args ): Event {
		$context = $args['context'] ?? array();

		return new Event(
			$event_type,
			severity: isset( $args['severity'] ) ? max( 0, min( 3, (int) $args['severity'] ) ) : null,
			object_type: sanitize_key( (string) ( $args['object_type'] ?? '' ) ),
			object_id: (string) ( $args['object_id'] ?? '' ),
			object_label: sanitize_text_field( (string) ( $args['object_label'] ?? '' ) ),
			context: is_array( $context ) ? $context : array(),
			changes: wpal_build_changes( $args['changes'] ?? array() ),
			user_id: max( 0, (int) ( $args['user_id'] ?? 0 ) )
		);
	}
}

if ( ! function_exists( 'wpal_build_changes' ) ) {
	/**
	 * Turns wpal_log() changes into Change objects, passing values through the Differ
	 * so redaction and size limits apply. Unchanged values are dropped.
	 *
	 * @param mixed $changes Change objects, or [field => [old, new]].
	 *
	 * @return array<int, Change> Changes.
	 */
	function wpal_build_changes( mixed $changes ): array {
		if ( ! is_array( $changes ) ) {
			return array();
		}

		$differ = Plugin::instance()->differ();
		$built  = array();

		foreach ( $changes as $field => $change ) {
			if ( $change instanceof Change ) {
				$built[] = $change;
				continue;
			}

			if ( is_array( $change ) && 2 === count( $change ) ) {
				[ $old, $new ] = array_values( $change );
				$compared      = $differ->compare( (string) $field, $old, $new );

				if ( null !== $compared ) {
					$built[] = $compared;
				}
			}
		}

		return $built;
	}
}
