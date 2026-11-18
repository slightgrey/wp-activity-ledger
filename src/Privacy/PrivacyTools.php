<?php
/**
 * WordPress privacy tools integration.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Privacy;

use WPActivityLedger\Database\EventRepository;
use WPActivityLedger\Loader;

/**
 * Hooks the activity log into Tools → Export/Erase Personal Data and the privacy policy
 * guide.
 *
 * Export lists the events a person performed. Erasure anonymises their login, IP address
 * and browser in those events but keeps the rows: an audit trail is kept for the site's
 * legitimate interest in security, and WordPress reports the rows as retained.
 */
final class PrivacyTools {

	/**
	 * Export page size.
	 */
	private const PAGE_SIZE = 100;

	/**
	 * Group ID in the export.
	 */
	private const GROUP = 'wpal-activity';

	/**
	 * Creates the integration.
	 *
	 * @param EventRepository $repository Event storage.
	 */
	public function __construct( private readonly EventRepository $repository ) {
	}

	/**
	 * Queues the hooks.
	 *
	 * @param Loader $loader Hook loader.
	 *
	 * @return void
	 */
	public function register( Loader $loader ): void {
		$loader->add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ), 10, 1 );
		$loader->add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ), 10, 1 );
		$loader->add_action( 'admin_init', array( $this, 'add_policy_text' ), 10, 0 );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param mixed $exporters Exporters.
	 *
	 * @return array<string, mixed> Exporters.
	 */
	public function add_exporter( $exporters ): array {
		$exporters                       = (array) $exporters;
		$exporters['wp-activity-ledger'] = array(
			'exporter_friendly_name' => __( 'Activity Ledger', 'wp-activity-ledger' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param mixed $erasers Erasers.
	 *
	 * @return array<string, mixed> Erasers.
	 */
	public function add_eraser( $erasers ): array {
		$erasers                       = (array) $erasers;
		$erasers['wp-activity-ledger'] = array(
			'eraser_friendly_name' => __( 'Activity Ledger', 'wp-activity-ledger' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exports one page of the person's events.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (1-based).
	 *
	 * @return array{data: array<int, array<string, mixed>>, done: bool} Export page.
	 */
	public function export( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );

		if ( ! $user instanceof \WP_User ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$result = $this->repository->query(
			array(
				'user'     => array( $user->ID ),
				'page'     => max( 1, (int) $page ),
				'per_page' => self::PAGE_SIZE,
				'orderby'  => 'id',
				'order'    => 'asc',
			)
		);

		return array(
			'data' => array_map( array( self::class, 'item' ), $result['items'] ),
			'done' => (int) $page * self::PAGE_SIZE >= $result['total'],
		);
	}

	/**
	 * Anonymises the person's events.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (unused: one update covers all rows).
	 *
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool} Result.
	 */
	public function erase( $email, $page = 1 ): array {
		unset( $page );
		$user = get_user_by( 'email', (string) $email );

		if ( ! $user instanceof \WP_User ) {
			return self::erase_result( false, array() );
		}

		$this->repository->anonymize_user( $user->ID );
		$kept    = $this->repository->query(
			array(
				'user'     => array( $user->ID ),
				'per_page' => 1,
			)
		)['total'];

		$messages = array();

		if ( $kept > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: number of log entries. */
				_n(
					'Activity Ledger keeps %d log entry for security auditing. The login, IP address and browser details in it were anonymised.',
					'Activity Ledger keeps %d log entries for security auditing. The login, IP address and browser details in them were anonymised.',
					$kept,
					'wp-activity-ledger'
				),
				$kept
			);
		}


		return self::erase_result( $kept > 0, $messages );
	}

	/**
	 * Adds the suggested privacy policy text.
	 *
	 * @return void
	 */
	public function add_policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$text = '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text for sites that use WP Activity Ledger:', 'wp-activity-ledger' ) . '</p>'
			. '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'wp-activity-ledger' ) . ' </strong>'
			. esc_html__( 'For security and accountability, this site keeps an activity log of actions taken by logged-in users and of failed login attempts: what was done, when, by which account, from which IP address and with which browser. The log is only visible to administrators, is kept for a limited time and is deleted automatically afterwards. If you ask us to erase your personal data, your entries in the log are anonymised but kept as part of the security record.', 'wp-activity-ledger' )
			. '</p>';

		wp_add_privacy_policy_content( 'WP Activity Ledger', wp_kses_post( $text ) );
	}

	/**
	 * Turns an event into an export item.
	 *
	 * @param array<string, mixed> $event Event.
	 *
	 * @return array<string, mixed> Item.
	 */
	private static function item( array $event ): array {
		return array(
			'group_id'    => self::GROUP,
			'group_label' => __( 'Activity Ledger', 'wp-activity-ledger' ),
			'item_id'     => 'wpal-event-' . $event['id'],
			'data'        => array(
				array(
					'name'  => __( 'Time (UTC)', 'wp-activity-ledger' ),
					'value' => $event['created_at'],
				),
				array(
					'name'  => __( 'Event', 'wp-activity-ledger' ),
					'value' => $event['message'],
				),
				array(
					'name'  => __( 'Object', 'wp-activity-ledger' ),
					'value' => $event['object_label'],
				),
				array(
					'name'  => __( 'IP address', 'wp-activity-ledger' ),
					'value' => $event['ip'],
				),
				array(
					'name'  => __( 'Browser', 'wp-activity-ledger' ),
					'value' => $event['user_agent'],
				),
			),
		);
	}

	/**
	 * Builds an eraser result.
	 *
	 * @param bool               $retained Whether rows were kept.
	 * @param array<int, string> $messages Messages.
	 *
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool} Result.
	 */
	private static function erase_result( bool $retained, array $messages ): array {
		return array(
			'items_removed'  => false,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}
