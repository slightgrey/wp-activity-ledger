<?php
/**
 * Threshold counters and cooldowns.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Alerts;

/**
 * Remembers recent matches (for thresholds) and cooldowns between alerts.
 */
interface AlertState {

	/**
	 * Adds a match and returns how many happened within the window, including this one.
	 *
	 * @param string $key    Counter key (rule and group).
	 * @param int    $now    Current Unix time.
	 * @param int    $window Window in seconds.
	 *
	 * @return int Matches in the window.
	 */
	public function hit( string $key, int $now, int $window ): int;

	/**
	 * Tells whether a cooldown is running.
	 *
	 * @param string $key Cooldown key.
	 * @param int    $now Current Unix time.
	 *
	 * @return bool True while cooling down.
	 */
	public function cooling( string $key, int $now ): bool;

	/**
	 * Starts a cooldown.
	 *
	 * @param string $key   Cooldown key.
	 * @param int    $until Unix time when it ends.
	 *
	 * @return void
	 */
	public function cool_until( string $key, int $until ): void;
}
