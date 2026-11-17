<?php
/**
 * Collects field changes of WooCommerce data objects.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Integrations;

use WPActivityLedger\Diff\Differ;
use WPActivityLedger\Domain\Change;
use WPActivityLedger\Domain\Event;
use WPActivityLedger\Recording\Recorder;

/**
 * Diffs WooCommerce objects (orders, products, coupons) on save and records one event per
 * object and request at shutdown, because WooCommerce saves an object several times
 * during one admin edit.
 *
 * Before a save, get_data() holds the stored values and get_changes() the pending ones.
 * Nested groups (billing, shipping, dimensions) are diffed per key ("billing:city").
 */
final class WooObjectTracker {

	/**
	 * Bookkeeping fields that are never recorded.
	 */
	private const SKIP = array( 'date_modified', 'date_created', 'version', 'cart_hash', 'order_key', 'prices_include_tax', 'meta_data', 'id' );

	/**
	 * Pending objects by "object_type:spl id": object, object type, event type, fields.
	 *
	 * @var array<string, array{object: \WC_Data, object_type: string, kind: string, fields: array<string, array{0: mixed, 1: mixed}>}>
	 */
	private array $pending = array();

	/**
	 * Creates the tracker.
	 *
	 * @param Recorder $recorder Recorder.
	 * @param Differ   $differ   Differ.
	 */
	public function __construct(
		private readonly Recorder $recorder,
		private readonly Differ $differ
	) {
	}

	/**
	 * Collects the pending changes of an object that is about to be saved.
	 *
	 * @param \WC_Data           $data_object Object.
	 * @param string             $object_type Stored object type, e.g. "shop_order", "product".
	 * @param string             $kind        Event type.
	 * @param array<int, string> $skip        Extra fields to ignore (e.g. order status).
	 *
	 * @return void
	 */
	public function collect( \WC_Data $data_object, string $object_type, string $kind, array $skip = array() ): void {
		$key     = $object_type . ':' . spl_object_id( $data_object );
		$old     = $data_object->get_data();
		$changes = $data_object->get_changes();

		$this->pending[ $key ] ??= array(
			'object'      => $data_object,
			'object_type' => $object_type,
			'kind'        => $kind,
			'fields'      => array(),
		);

		foreach ( $changes as $field => $new ) {
			if ( in_array( $field, self::SKIP, true ) || in_array( $field, $skip, true ) || self::is_flattened_alias( (string) $field, $changes ) ) {
				continue;
			}

			$stored = $old[ $field ] ?? null;
			$first  = array_key_exists( $field, $this->pending[ $key ]['fields'] ) ? $this->pending[ $key ]['fields'][ $field ][0] : $stored;

			// Groups (billing, shipping…) list only their changed keys; compare complete groups.
			$full = is_array( $new ) && is_array( $stored ) ? array_merge( $stored, $new ) : $new;

			$this->pending[ $key ]['fields'][ $field ] = array( $first, $full );
		}
	}

	/**
	 * Marks an object as created in this request, even if no field changes follow.
	 *
	 * @param \WC_Data $data_object Object.
	 * @param string   $object_type Stored object type.
	 * @param string   $kind        "*_created" event type.
	 *
	 * @return void
	 */
	public function mark( \WC_Data $data_object, string $object_type, string $kind ): void {
		$this->pending[ $object_type . ':' . spl_object_id( $data_object ) ] ??= array(
			'object'      => $data_object,
			'object_type' => $object_type,
			'kind'        => $kind,
			'fields'      => array(),
		);
	}

	/**
	 * Tells whether a change key is a flattened copy of a grouped one.
	 *
	 * WooCommerce reports address changes twice: "billing" => ["city" => …] and "billing_city".
	 *
	 * @param string               $field   Change key.
	 * @param array<string, mixed> $changes All changes.
	 *
	 * @return bool True when "{group}_{key}" duplicates $changes[group][key].
	 */
	private static function is_flattened_alias( string $field, array $changes ): bool {
		$parts = explode( '_', $field, 2 );

		return 2 === count( $parts ) && is_array( $changes[ $parts[0] ] ?? null ) && array_key_exists( $parts[1], $changes[ $parts[0] ] );
	}

	/**
	 * Records one event per object. Runs on shutdown (priority 0).
	 *
	 * @param callable(\WC_Data): string $label Returns an object's label.
	 *
	 * @return void
	 */
	public function record_pending( callable $label ): void {
		$pending       = $this->pending;
		$this->pending = array();

		foreach ( self::merge_by_id( $pending ) as $item ) {
			$changes = $this->changes( $item['fields'] );

			if ( array() === $changes && ! str_ends_with( $item['kind'], '_created' ) ) {
				continue;
			}

			$this->recorder->record(
				new Event( $item['kind'], object_type: $item['object_type'], object_id: (string) $item['object']->get_id(), object_label: $label( $item['object'] ), changes: $changes )
			);
		}
	}

	/**
	 * Diffs the collected fields.
	 *
	 * @param array<string, array{0: mixed, 1: mixed}> $fields Field => [old, new].
	 *
	 * @return array<int, Change> Changes.
	 */
	private function changes( array $fields ): array {
		$changes = array();

		foreach ( $fields as $field => [ $old, $new ] ) {
			array_push( $changes, ...$this->differ->diff_value( (string) $field, self::plain( $old ), self::plain( $new ) ) );
		}

		return $changes;
	}

	/**
	 * Merges entries for the same stored object (several instances saved in one request).
	 *
	 * @param array<string, array{object: \WC_Data, object_type: string, kind: string, fields: array<string, array{0: mixed, 1: mixed}>}> $pending Pending entries.
	 *
	 * @return array<string, array{object: \WC_Data, object_type: string, kind: string, fields: array<string, array{0: mixed, 1: mixed}>}> Entries by "type:ID".
	 */
	private static function merge_by_id( array $pending ): array {
		$merged = array();

		foreach ( $pending as $item ) {
			$id = $item['object_type'] . ':' . $item['object']->get_id();

			if ( ! isset( $merged[ $id ] ) ) {
				$merged[ $id ] = $item;
				continue;
			}

			// The latest instance carries the current name for the label.
			$merged[ $id ]['object'] = $item['object'];

			foreach ( $item['fields'] as $field => [ $old, $new ] ) {
				$merged[ $id ]['fields'][ $field ] = array( $merged[ $id ]['fields'][ $field ][0] ?? $old, $new );
			}

			if ( str_ends_with( $item['kind'], '_created' ) ) {
				$merged[ $id ]['kind'] = $item['kind'];
			}
		}

		return $merged;
	}

	/**
	 * Turns WooCommerce value objects (dates) into plain values.
	 *
	 * @param mixed $value Value.
	 *
	 * @return mixed Plain value.
	 */
	private static function plain( mixed $value ): mixed {
		if ( $value instanceof \DateTimeInterface ) {
			return $value->format( 'Y-m-d H:i:s' );
		}

		return is_array( $value ) ? array_map( array( self::class, 'plain' ), $value ) : $value;
	}
}
