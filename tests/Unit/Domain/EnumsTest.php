<?php
/**
 * Tests for the Severity and Category enums.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use WPActivityLedger\Domain\Category;
use WPActivityLedger\Domain\Severity;

/**
 * @covers \WPActivityLedger\Domain\Severity
 * @covers \WPActivityLedger\Domain\Category
 */
final class EnumsTest extends TestCase {

	/**
	 * Severities map to the stored integers and back, with slugs and labels.
	 *
	 * @return void
	 */
	public function test_severity_values_slugs_and_labels(): void {
		$this->assertSame( Severity::Critical, Severity::from( 3 ) );
		$this->assertNull( Severity::tryFrom( 9 ) );
		$this->assertSame( 'warning', Severity::Warning->slug() );
		$this->assertSame( Severity::Notice, Severity::from_slug( 'NOTICE' ) );
		$this->assertNull( Severity::from_slug( 'fatal' ) );
		$this->assertSame( 'Info', Severity::Info->label() );
	}

	/**
	 * There are 16 categories, each fits the column and has a label.
	 *
	 * @return void
	 */
	public function test_categories(): void {
		$this->assertCount( 16, Category::cases() );
		$this->assertSame( Category::WooCommerce, Category::from( 'woocommerce' ) );
		$this->assertNull( Category::tryFrom( 'nope' ) );

		foreach ( Category::cases() as $category ) {
			$this->assertLessThanOrEqual( 32, strlen( $category->value ) );
			$this->assertNotSame( '', $category->label() );
		}
	}
}
