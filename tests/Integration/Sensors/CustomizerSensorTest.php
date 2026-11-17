<?php
/**
 * Tests for the CustomizerSensor.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Sensors;

use WPActivityLedger\Plugin;
use WPActivityLedger\Sensors\CustomizerSensor;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * @covers \WPActivityLedger\Sensors\CustomizerSensor
 */
final class CustomizerSensorTest extends IntegrationTestCase {

	/**
	 * Returns the newest event of a type, with changes.
	 *
	 * @param string $type Event type.
	 *
	 * @return array<string, mixed>|null Event.
	 */
	private static function latest( string $type ): ?array {
		$sensor = Plugin::instance()->core_sensors()['customizer'];
		self::assertInstanceOf( CustomizerSensor::class, $sensor );
		$sensor->record_pending();
		Plugin::instance()->recorder()->flush();

		$items = Plugin::instance()->repository()->query(
			array(
				'type'     => $type,
				'per_page' => 1,
			)
		)['items'];

		return array() === $items ? null : Plugin::instance()->repository()->find( $items[0]['id'] );
	}

	/**
	 * Options and theme mods written during a Customizer save become one event.
	 *
	 * @return void
	 */
	public function test_customizer_save(): void {
		$this->as_user( 'administrator' );
		$title = 'WPAL Site ' . wp_generate_password( 4, false );

		do_action( 'customize_save', null );
		update_option( 'blogname', $title );
		set_theme_mod( 'wpal_test_color', '#ff0000' );
		do_action( 'customize_save_after', null );

		$event = self::latest( 'customizer.saved' );

		$this->assertNotNull( $event );
		$fields = array_column( $event['changes'], null, 'field' );
		$this->assertSame( $title, $fields['blogname']['new'] );
		$this->assertSame( '#ff0000', $fields['theme_mod:wpal_test_color']['new'] );
		$this->assertSame( 'Saved 2 Customizer setting(s)', $event['message'], 'Fields: ' . implode( ', ', array_keys( $fields ) ) );
		$this->assertFalse( apply_filters( 'wpal_option_is_claimed', false, 'blogname' ), 'Outside a save, options are not claimed.' );
	}

	/**
	 * Widget option writes outside the Customizer become one widget.updated event.
	 *
	 * @return void
	 */
	public function test_widgets(): void {
		$this->as_user( 'administrator' );
		$label = 'WPAL widget ' . wp_generate_password( 4, false );

		$widgets      = get_option( 'widget_text', array() );
		$widgets      = is_array( $widgets ) ? $widgets : array();
		$widgets[901] = array(
			'title' => $label,
			'text'  => 'Hello',
		);
		update_option( 'widget_text', $widgets );

		$event = self::latest( 'widget.updated' );

		$this->assertNotNull( $event );
		$fields = array_column( $event['changes'], null, 'field' );
		$this->assertArrayHasKey( 'widget_text:901', $fields );
		$this->assertSame( $label, $fields['widget_text:901']['new']['title'] );
		$this->assertTrue( apply_filters( 'wpal_option_is_claimed', false, 'widget_text' ) );
	}
}
