<?php
/**
 * WordPress dashboard widget.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Admin;

use WPActivityLedger\Database\EventRepository;
use WPActivityLedger\Loader;
use WPActivityLedger\Security\Capabilities;
use WPActivityLedger\Settings\Settings;

/**
 * A server-rendered widget on Dashboard → Home: the number of events in the last 24 hours,
 * the ten latest events and a link to the log. Shown to users who can view the log, unless
 * turned off in the settings.
 */
final class DashboardWidget {

	/**
	 * Widget ID.
	 */
	public const ID = 'wpal_activity';

	/**
	 * Events listed.
	 */
	private const LIMIT = 10;

	/**
	 * Creates the widget.
	 *
	 * @param EventRepository $repository Event storage.
	 * @param Settings        $settings   Settings.
	 */
	public function __construct(
		private readonly EventRepository $repository,
		private readonly Settings $settings
	) {
	}

	/**
	 * Queues the hook.
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_action( 'wp_dashboard_setup', array( $this, 'add' ), 10, 0 );
	}

	/**
	 * Adds the widget for users who may see it.
	 *
	 * @return void
	 */
	public function add(): void {
		if ( ! $this->settings->get( 'dashboard_widget' ) || ! current_user_can( Capabilities::VIEW ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Plugin capability.
			return;
		}

		wp_add_dashboard_widget( self::ID, __( 'Activity Ledger', 'wp-activity-ledger' ), array( $this, 'render' ) );
	}

	/**
	 * Renders the widget.
	 *
	 * @return void
	 */
	public function render(): void {
		$day    = $this->repository->query(
			array(
				'from'     => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				'per_page' => self::LIMIT,
			)
		);
		$latest = $this->repository->query( array( 'per_page' => self::LIMIT ) )['items'];

		echo '<p class="wpal-widget-count">';
		/* translators: %s: number of events. */
		echo esc_html( sprintf( _n( '%s event in the last 24 hours', '%s events in the last 24 hours', $day['total'], 'wp-activity-ledger' ), number_format_i18n( $day['total'] ) ) );
		echo '</p>';

		if ( array() === $latest ) {
			echo '<p>' . esc_html__( 'No activity recorded yet.', 'wp-activity-ledger' ) . '</p>';
		} else {
			echo '<ul class="wpal-widget-list">';
			array_map( array( self::class, 'render_item' ), $latest );
			echo '</ul>';
		}

		echo '<p class="community-events-footer"><a href="' . esc_url( Menu::url( '/log' ) ) . '">' . esc_html__( 'View the activity log', 'wp-activity-ledger' ) . '</a></p>';
	}

	/**
	 * Renders one event line: time, who and what.
	 *
	 * @param array<string, mixed> $event Event.
	 *
	 * @return void
	 */
	private static function render_item( array $event ): void {
		$time = strtotime( $event['created_at'] . ' UTC' );
		$who  = '' === $event['user_login'] ? __( 'System', 'wp-activity-ledger' ) : $event['user_login'];

		printf(
			'<li style="display:flex;gap:8px;margin-bottom:6px"><span style="color:#646970;white-space:nowrap" title="%1$s">%2$s</span><span><strong>%3$s</strong> %4$s</span></li>',
			esc_attr( (string) wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), (int) $time ) ),
			esc_html( (string) wp_date( (string) get_option( 'time_format' ), (int) $time ) ),
			esc_html( $who ),
			esc_html( (string) $event['message'] )
		);
	}
}
