<?php
/**
 * WP-CLI alert commands.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Cli;

use WPActivityLedger\Alerts\ChannelStore;
use WPActivityLedger\Plugin;
use WP_CLI;
use WP_CLI\Utils;

/**
 * Lists alert channels and sends test alerts.
 */
final class AlertsCommand {

	/**
	 * Lists the alert channels (credentials masked).
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @subcommand channels
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 *
	 * @return void
	 */
	public function channels( array $args, array $assoc_args ): void {
		unset( $args );
		$rows = array_map(
			static function ( array $channel ): array {
				$channel = ChannelStore::masked( $channel );

				return array(
					'id'     => $channel['id'],
					'type'   => $channel['type'],
					'name'   => $channel['name'],
					'target' => 'email' === $channel['type'] ? implode( ', ', (array) $channel['config']['recipients'] ) : (string) ( $channel['config']['url'] ?? '' ),
				);
			},
			Plugin::instance()->alert_channels()->all()
		);

		Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'id', 'type', 'name', 'target' ) );
	}

	/**
	 * Sends a test alert through a channel.
	 *
	 * ## OPTIONS
	 *
	 * <channel-id>
	 * : Channel ID (see `wp activity-ledger alerts channels`).
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 *
	 * @return void
	 */
	public function test( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		$plugin  = Plugin::instance();
		$channel = $plugin->alert_channels()->get( (string) ( $args[0] ?? '' ) );

		if ( null === $channel ) {
			WP_CLI::error( 'Channel not found.' );
		}

		$error = $plugin->deliverer()->send( $channel, $plugin->deliverer()->sample_message() );

		null === $error ? WP_CLI::success( "Test alert sent to \"{$channel['name']}\"." ) : WP_CLI::error( "Test alert failed: $error" );
	}
}
