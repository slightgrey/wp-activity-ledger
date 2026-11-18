<?php
/**
 * Links from events to the objects they are about.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Rest;

/**
 * Resolves the wp-admin edit link of an event's object, or null when the object no longer
 * exists (or has no edit screen).
 */
final class ObjectLinks {

	/**
	 * Object types that are never posts, even if a post type of that name exists.
	 */
	private const NOT_POSTS = array( 'user', 'comment', 'term', 'menu', 'plugin', 'theme', 'core', 'settings', 'shop_order' );

	/**
	 * Returns the edit link of an event's object.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id   Object ID.
	 *
	 * @return string|null URL, or null.
	 */
	public static function edit_link( string $object_type, string $object_id ): ?string {
		$link = self::resolve( $object_type, $object_id );

		/**
		 * Filters the edit link of an event's object.
		 *
		 * @param string|null $link        URL, or null when there is none.
		 * @param string      $object_type Object type.
		 * @param string      $object_id   Object ID.
		 */
		$link = apply_filters( 'wpal_object_edit_link', $link, $object_type, $object_id );

		return is_string( $link ) && '' !== $link ? $link : null;
	}

	/**
	 * Resolves the built-in edit links.
	 *
	 * @param string $object_type Object type.
	 * @param string $object_id   Object ID.
	 *
	 * @return string|null URL, or null.
	 */
	private static function resolve( string $object_type, string $object_id ): ?string {
		$id = ctype_digit( $object_id ) ? (int) $object_id : 0;

		return match ( true ) {
			'user' === $object_type       => $id > 0 && get_userdata( $id ) ? get_edit_user_link( $id ) : null,
			'comment' === $object_type    => $id > 0 && get_comment( $id ) ? get_edit_comment_link( $id ) : null,
			'term' === $object_type       => self::term( $id ),
			'menu' === $object_type       => $id > 0 && is_nav_menu( $id ) ? admin_url( 'nav-menus.php?action=edit&menu=' . $id ) : null,
			'plugin' === $object_type     => '' !== $object_id && file_exists( WP_PLUGIN_DIR . '/' . $object_id ) ? admin_url( 'plugins.php' ) : null,
			'theme' === $object_type      => '' !== $object_id && wp_get_theme( $object_id )->exists() ? admin_url( 'themes.php?theme=' . rawurlencode( $object_id ) ) : null,
			'shop_order' === $object_type => self::order( $id ),
			default                       => self::post( $object_type, $id ),
		};
	}

	/**
	 * Returns a term's edit link.
	 *
	 * @param int $id Term ID.
	 *
	 * @return string|null URL, or null.
	 */
	private static function term( int $id ): ?string {
		$term = $id > 0 ? get_term( $id ) : null;
		$link = $term instanceof \WP_Term ? get_edit_term_link( $term ) : null;

		return is_string( $link ) ? $link : null;
	}

	/**
	 * Returns a WooCommerce order's edit link (works with HPOS and posts storage).
	 *
	 * @param int $id Order ID.
	 *
	 * @return string|null URL, or null.
	 */
	private static function order( int $id ): ?string {
		$order = $id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : false;

		return $order instanceof \WC_Order ? $order->get_edit_order_url() : null;
	}

	/**
	 * Returns a post's edit link when the object type is a post type and the post exists.
	 *
	 * @param string $object_type Object type.
	 * @param int    $id          Post ID.
	 *
	 * @return string|null URL, or null.
	 */
	private static function post( string $object_type, int $id ): ?string {
		if ( $id <= 0 || in_array( $object_type, self::NOT_POSTS, true ) || ! post_type_exists( $object_type ) ) {
			return null;
		}

		$post = get_post( $id );

		return $post instanceof \WP_Post && $post->post_type === $object_type ? get_edit_post_link( $post, 'raw' ) : null;
	}
}
