<?php
/**
 * Contract for third-party plugin integrations.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Integrations;

use WPActivityLedger\Domain\EventType;
use WPActivityLedger\Loader;

/**
 * An integration records activity of another plugin (WooCommerce, Elementor…). It is
 * only registered when that plugin is active.
 */
interface IntegrationInterface {

	/**
	 * Returns the integration's slug, e.g. "woocommerce".
	 *
	 * @return string Slug.
	 */
	public function slug(): string;

	/**
	 * Returns the name shown in the admin, e.g. "WooCommerce".
	 *
	 * @return string Name.
	 */
	public function name(): string;

	/**
	 * Tells whether the other plugin is active.
	 *
	 * @return bool True when available.
	 */
	public function is_available(): bool;

	/**
	 * Queues the integration's hooks.
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public function register( Loader $loader ): void;

	/**
	 * Returns the event types the integration records (called lazily, after init).
	 *
	 * @return array<int, EventType> Types.
	 */
	public function event_types(): array;
}
