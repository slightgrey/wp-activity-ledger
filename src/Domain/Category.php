<?php
/**
 * Event categories.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Domain;

/**
 * The area of the site an event belongs to. Stored in wpal_events.category.
 */
enum Category: string {

	case Auth        = 'auth';
	case User        = 'user';
	case Content     = 'content';
	case Media       = 'media';
	case Comment     = 'comment';
	case Taxonomy    = 'taxonomy';
	case Menu        = 'menu';
	case Plugin      = 'plugin';
	case Theme       = 'theme';
	case Core        = 'core';
	case Settings    = 'settings';
	case Widget      = 'widget';
	case Multisite   = 'multisite';
	case WooCommerce = 'woocommerce';
	case Elementor   = 'elementor';
	case System      = 'system';

	/**
	 * Returns the translated label.
	 *
	 * @return string Label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Auth        => __( 'Authentication', 'wp-activity-ledger' ),
			self::User        => __( 'Users', 'wp-activity-ledger' ),
			self::Content     => __( 'Content', 'wp-activity-ledger' ),
			self::Media       => __( 'Media', 'wp-activity-ledger' ),
			self::Comment     => __( 'Comments', 'wp-activity-ledger' ),
			self::Taxonomy    => __( 'Taxonomies', 'wp-activity-ledger' ),
			self::Menu        => __( 'Menus', 'wp-activity-ledger' ),
			self::Plugin      => __( 'Plugins', 'wp-activity-ledger' ),
			self::Theme       => __( 'Themes', 'wp-activity-ledger' ),
			self::Core        => __( 'WordPress core', 'wp-activity-ledger' ),
			self::Settings    => __( 'Settings', 'wp-activity-ledger' ),
			self::Widget      => __( 'Widgets & Customizer', 'wp-activity-ledger' ),
			self::Multisite   => __( 'Multisite', 'wp-activity-ledger' ),
			self::WooCommerce => __( 'WooCommerce', 'wp-activity-ledger' ),
			self::Elementor   => __( 'Elementor', 'wp-activity-ledger' ),
			self::System      => __( 'Activity Ledger', 'wp-activity-ledger' ),
		};
	}
}
