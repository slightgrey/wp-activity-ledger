<?php
/**
 * Minimal WP-CLI declarations for PHPStan (php-stubs/wp-cli-stubs needs WordPress stubs ≤ 6).
 *
 * @package WPActivityLedger
 */

// phpcs:ignoreFile -- Stubs.

namespace {
	/**
	 * WP-CLI.
	 */
	class WP_CLI {
		/**
		 * @param string          $name     Command.
		 * @param callable|string $callable Handler.
		 * @param array<string, mixed> $args Options.
		 * @return bool
		 */
		public static function add_command( $name, $callable, $args = array() ) {}

		/**
		 * @param string $message Message.
		 * @return void
		 */
		public static function line( $message = '' ) {}

		/**
		 * @param string $message Message.
		 * @return void
		 */
		public static function success( $message ) {}

		/**
		 * @param string     $message Message.
		 * @param bool|int   $exit    Exit.
		 * @return never
		 */
		public static function error( $message, $exit = true ) {}

		/**
		 * @param string               $question   Question.
		 * @param array<string, mixed> $assoc_args Options.
		 * @return void
		 */
		public static function confirm( $question, $assoc_args = array() ) {}
	}
}

namespace WP_CLI\Utils {
	/**
	 * @param string                        $format Format.
	 * @param array<int, array<mixed>>      $items  Items.
	 * @param array<int, string>|string     $fields Fields.
	 * @return void
	 */
	function format_items( $format, $items, $fields ) {}
}
