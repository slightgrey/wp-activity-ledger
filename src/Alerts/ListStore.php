<?php
/**
 * Base for option-backed lists of alert items.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Alerts;

/**
 * Stores a list of items with UUID IDs in one non-autoloaded option. Subclasses validate
 * submitted fields; nothing is saved while any field is invalid.
 */
abstract class ListStore {

	/**
	 * Returns the option name.
	 *
	 * @return string Option.
	 */
	abstract protected function option(): string;

	/**
	 * Validates submitted fields, merged over an existing item when updating.
	 *
	 * @param array<string, mixed>      $input    Submitted fields.
	 * @param array<string, mixed>|null $existing Stored item (null when creating).
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, string>} Clean item (without id) and errors by field.
	 */
	abstract protected function sanitize( array $input, ?array $existing ): array;

	/**
	 * Returns every item.
	 *
	 * @return array<int, array<string, mixed>> Items.
	 */
	public function all(): array {
		$items = get_option( $this->option(), array() );

		return is_array( $items ) ? array_values( array_filter( $items, 'is_array' ) ) : array();
	}

	/**
	 * Returns one item.
	 *
	 * @param string $id Item ID.
	 *
	 * @return array<string, mixed>|null Item, or null.
	 */
	public function get( string $id ): ?array {
		foreach ( $this->all() as $item ) {
			if ( ( $item['id'] ?? '' ) === $id ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Creates an item.
	 *
	 * @param array<string, mixed> $input Submitted fields.
	 *
	 * @return array{0: array<string, mixed>|null, 1: array<string, string>} Created item (null on errors) and errors.
	 */
	public function create( array $input ): array {
		[ $item, $errors ] = $this->sanitize( $input, null );

		if ( array() !== $errors ) {
			return array( null, $errors );
		}

		$item  = array_merge( array( 'id' => wp_generate_uuid4() ), $item );
		$items = $this->all();

		$items[] = $item;
		$this->save( $items );

		return array( $item, array() );
	}

	/**
	 * Updates an item (fields not submitted keep their values).
	 *
	 * @param string               $id    Item ID.
	 * @param array<string, mixed> $input Submitted fields.
	 *
	 * @return array{0: array<string, mixed>|null, 1: array<string, string>} Updated item (null when unknown or invalid) and errors.
	 */
	public function update( string $id, array $input ): array {
		$existing = $this->get( $id );

		if ( null === $existing ) {
			return array( null, array() );
		}

		[ $item, $errors ] = $this->sanitize( $input, $existing );

		if ( array() !== $errors ) {
			return array( null, $errors );
		}

		$item = array_merge( array( 'id' => $id ), $item );
		$this->save( array_map( static fn( array $old ): array => $old['id'] === $id ? $item : $old, $this->all() ) );

		return array( $item, array() );
	}

	/**
	 * Deletes an item.
	 *
	 * @param string $id Item ID.
	 *
	 * @return bool True when it existed.
	 */
	public function delete( string $id ): bool {
		$items = $this->all();
		$kept  = array_values( array_filter( $items, static fn( array $item ): bool => ( $item['id'] ?? '' ) !== $id ) );

		if ( count( $kept ) === count( $items ) ) {
			return false;
		}

		$this->save( $kept );

		return true;
	}

	/**
	 * Stores the list.
	 *
	 * @param array<int, array<string, mixed>> $items Items.
	 *
	 * @return void
	 */
	protected function save( array $items ): void {
		update_option( $this->option(), array_values( $items ), false );
	}

	/**
	 * Returns a submitted field, or the stored one when not submitted.
	 *
	 * @param array<string, mixed>      $input    Submitted fields.
	 * @param array<string, mixed>|null $existing Stored item.
	 * @param string                    $key      Field.
	 * @param mixed                     $fallback Default for new items.
	 *
	 * @return mixed Value.
	 */
	protected static function field( array $input, ?array $existing, string $key, mixed $fallback ): mixed {
		if ( array_key_exists( $key, $input ) ) {
			return $input[ $key ];
		}

		return null === $existing ? $fallback : ( $existing[ $key ] ?? $fallback );
	}

	/**
	 * Validates a name (1–100 characters of plain text).
	 *
	 * @param mixed                 $value  Value.
	 * @param array<string, string> $errors Errors (added to).
	 *
	 * @return string Name.
	 */
	protected static function name( mixed $value, array &$errors ): string {
		$name = is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';

		if ( '' === $name ) {
			$errors['name'] = __( 'Enter a name.', 'wp-activity-ledger' );
		}

		return mb_substr( $name, 0, 100 );
	}
}
