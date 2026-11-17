<?php
/**
 * Tests for the Elementor integration: templates, site kit and settings.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Integrations;

use WPActivityLedger\Integrations\Elementor;
use WPActivityLedger\Integrations\ElementorSettingsWatcher;
use WPActivityLedger\Plugin;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * @covers \WPActivityLedger\Integrations\ElementorLibrary
 * @covers \WPActivityLedger\Integrations\ElementorSettingsWatcher
 * @covers \WPActivityLedger\Integrations\OptionCollector
 */
final class ElementorLibraryTest extends IntegrationTestCase {

	/**
	 * Skips when Elementor isn't active; acts as an administrator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			$this->markTestSkipped( 'Elementor is not active.' );
		}

		$this->as_user( 'administrator' );
		self::settle();
	}

	/**
	 * A template is created, saved, trashed, restored and deleted: one event each.
	 *
	 * @return void
	 */
	public function test_template_lifecycle(): void {
		$start    = self::max_event_id();
		$document = \Elementor\Plugin::$instance->documents->create(
			'section',
			array(
				'post_title'  => 'Hero',
				'post_status' => 'publish',
			)
		);
		$id       = $document->get_main_id();
		self::settle();

		$document->save( array( 'elements' => array( self::widget(), self::widget() ) ) );
		self::settle();

		wp_trash_post( $id );
		wp_untrash_post( $id );
		wp_delete_post( $id, true );

		$events = self::events_since( $start, Elementor::LIBRARY_TYPE );

		$this->assertSame(
			array( 'elementor.template_created', 'elementor.template_updated', 'elementor.template_trashed', 'elementor.template_restored', 'elementor.template_deleted' ),
			array_column( $events, 'event_type' )
		);
		$this->assertSame( 'Created Elementor template "Hero"', $events[0]['message'] );
		$this->assertSame( 'elements', $events[1]['changes'][0]['field'] );
		$this->assertSame( '2', $events[1]['changes'][0]['new'] );
	}

	/**
	 * Saving Site Settings records one kit event with the changed setting.
	 *
	 * @return void
	 */
	public function test_kit_save_records_settings_diff(): void {
		$kit      = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
		$settings = (array) get_post_meta( $kit->get_id(), '_elementor_page_settings', true );
		$start    = self::max_event_id();

		$kit->save( array( 'settings' => array_merge( $settings, array( 'wpal_test_width' => 'w' . wp_rand() ) ) ) );

		$events = self::events_since( $start, Elementor::LIBRARY_TYPE );

		$this->assertSame( array( 'elementor.kit_updated' ), array_column( $events, 'event_type' ) );
		$this->assertContains( 'site_settings:wpal_test_width', array_column( $events[0]['changes'], 'field' ) );
	}

	/**
	 * Elementor settings give one settings event; conditions their own; noise nothing.
	 *
	 * @return void
	 */
	public function test_settings_conditions_and_noise(): void {
		$start = self::max_event_id();

		update_option( 'elementor_css_print_method', 'internal' === get_option( 'elementor_css_print_method' ) ? 'external' : 'internal' );
		update_option( 'elementor_font_display', 'swap' === get_option( 'elementor_font_display' ) ? 'block' : 'swap' );
		update_option( ElementorSettingsWatcher::CONDITIONS_OPTION, array( 'header' => array( 'x' . wp_rand() => array( 'include/general' ) ) ) );
		update_option( 'elementor_log', array( 'x' . wp_rand() ) );

		$events = self::events_since( $start, 'settings' );

		$this->assertSame( array( 'elementor.settings_changed', 'elementor.conditions_changed' ), array_column( $events, 'event_type' ) );
		$this->assertEqualsCanonicalizing( array( 'elementor_css_print_method', 'elementor_font_display' ), array_column( $events[0]['changes'], 'field' ) );
	}

	/**
	 * Builds a heading widget element.
	 *
	 * @return array<string, mixed> Element.
	 */
	private static function widget(): array {
		return array(
			'id'         => substr( md5( uniqid( '', true ) ), 0, 7 ),
			'elType'     => 'widget',
			'widgetType' => 'heading',
			'settings'   => array( 'title' => 'Hi' ),
			'elements'   => array(),
		);
	}

	/**
	 * Records pending core option and Elementor activity (in shutdown order) and flushes.
	 *
	 * @return void
	 */
	private static function settle(): void {
		foreach ( array( 'posts', 'options' ) as $slug ) {
			$sensor = Plugin::instance()->core_sensors()[ $slug ];
			self::assertTrue( method_exists( $sensor, 'record_pending' ) );
			$sensor->record_pending();
		}

		$elementor = Plugin::instance()->integrations()->get( 'elementor' );
		self::assertInstanceOf( Elementor::class, $elementor );
		$elementor->record_pending();

		Plugin::instance()->recorder()->flush();
	}

	/**
	 * Settles, then returns new events about an object type with their changes, oldest first.
	 *
	 * @param int    $after       ID boundary.
	 * @param string $object_type Object type.
	 *
	 * @return array<int, array<string, mixed>> Events.
	 */
	private static function events_since( int $after, string $object_type ): array {
		self::settle();

		$items = self::newer_than(
			Plugin::instance()->repository()->query(
				array(
					'object_type' => $object_type,
					'orderby'     => 'id',
					'order'       => 'desc',
				)
			)['items'],
			$after
		);

		return array_map( static fn( array $i ): array => (array) Plugin::instance()->repository()->find( $i['id'] ), array_reverse( $items ) );
	}
}
