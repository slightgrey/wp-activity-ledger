<?php
/**
 * Collects hook registrations and adds them to WordPress in one go.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger;

/**
 * Queues actions and filters so modules can declare their hooks without touching
 * WordPress directly, then registers them all when run() is called.
 *
 * @phpstan-type Hook array{hook: string, callback: callable, priority: int, args: int}
 */
final class Loader {

	/**
	 * Queued actions.
	 *
	 * @var array<int, array{hook: string, callback: callable, priority: int, args: int}>
	 */
	private array $actions = array();

	/**
	 * Queued filters.
	 *
	 * @var array<int, array{hook: string, callback: callable, priority: int, args: int}>
	 */
	private array $filters = array();

	/**
	 * Whether run() has already registered the queue.
	 *
	 * @var bool
	 */
	private bool $ran = false;

	/**
	 * Queues an action, or registers it at once when the loader has already run.
	 *
	 * @param string   $hook     Action name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Number of accepted arguments.
	 *
	 * @return void
	 */
	public function add_action( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
		$entry = self::entry( $hook, $callback, $priority, $args );

		if ( $this->ran ) {
			add_action( $hook, $callback, $priority, $args );
			return;
		}

		$this->actions[] = $entry;
	}

	/**
	 * Queues a filter, or registers it at once when the loader has already run.
	 *
	 * @param string   $hook     Filter name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Number of accepted arguments.
	 *
	 * @return void
	 */
	public function add_filter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
		$entry = self::entry( $hook, $callback, $priority, $args );

		if ( $this->ran ) {
			add_filter( $hook, $callback, $priority, $args );
			return;
		}

		$this->filters[] = $entry;
	}

	/**
	 * Registers every queued action and filter with WordPress.
	 *
	 * @return void
	 */
	public function run(): void {
		if ( $this->ran ) {
			return;
		}

		foreach ( $this->actions as $action ) {
			add_action( $action['hook'], $action['callback'], $action['priority'], $action['args'] );
		}

		foreach ( $this->filters as $filter ) {
			add_filter( $filter['hook'], $filter['callback'], $filter['priority'], $filter['args'] );
		}

		$this->ran = true;
	}

	/**
	 * Returns the queued actions (for tests and debugging).
	 *
	 * @return array<int, array{hook: string, callback: callable, priority: int, args: int}> Queued actions.
	 */
	public function actions(): array {
		return $this->actions;
	}

	/**
	 * Returns the queued filters (for tests and debugging).
	 *
	 * @return array<int, array{hook: string, callback: callable, priority: int, args: int}> Queued filters.
	 */
	public function filters(): array {
		return $this->filters;
	}

	/**
	 * Builds one queue entry.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Number of accepted arguments.
	 *
	 * @return array{hook: string, callback: callable, priority: int, args: int} Entry.
	 */
	private static function entry( string $hook, callable $callback, int $priority, int $args ): array {
		return array(
			'hook'     => $hook,
			'callback' => $callback,
			'priority' => $priority,
			'args'     => $args,
		);
	}
}
