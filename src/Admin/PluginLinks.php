<?php
/**
 * Links on the Plugins screen.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Admin;

use WPActivityLedger\Loader;
use WPActivityLedger\Support\Author;

/**
 * Adds "Documentation" and a low-key "Buy me a coffee" link to this plugin's row on the
 * Plugins screen (site and network). Nothing else: no notices, and no feature depends on it.
 */
final class PluginLinks {

	/**
	 * Queues the hook.
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_filter( 'plugin_row_meta', array( self::class, 'row_meta' ), 10, 2 );
	}

	/**
	 * Adds the links to this plugin's row.
	 *
	 * @param mixed  $links Row meta links (an array, unless another plugin broke the filter).
	 * @param string $file  Plugin file relative to the plugins directory.
	 *
	 * @return array<int|string, string> Links.
	 */
	public static function row_meta( $links, $file ): array {
		$links = is_array( $links ) ? $links : array();

		if ( plugin_basename( WPAL_FILE ) !== $file ) {
			return $links;
		}

		$links['wpal-docs']   = self::link( Author::REPOSITORY_URL . '#readme', __( 'Documentation', 'wp-activity-ledger' ) );
		$links['wpal-coffee'] = self::link( Author::paypal_url(), __( 'Buy me a coffee', 'wp-activity-ledger' ) );

		return $links;
	}

	/**
	 * Builds an external link that opens in a new tab.
	 *
	 * @param string $url   URL.
	 * @param string $label Text.
	 *
	 * @return string HTML.
	 */
	private static function link( string $url, string $label ): string {
		return sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span></a>',
			esc_url( $url ),
			esc_html( $label ),
			esc_html__( '(opens in a new tab)', 'wp-activity-ledger' )
		);
	}
}
