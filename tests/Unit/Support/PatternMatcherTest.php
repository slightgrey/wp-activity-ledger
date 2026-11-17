<?php
/**
 * Tests for the glob pattern matcher.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPActivityLedger\Support\PatternMatcher;

/**
 * @covers \WPActivityLedger\Support\PatternMatcher
 */
final class PatternMatcherTest extends TestCase {

	/**
	 * Cases: pattern, name, expected.
	 *
	 * @return array<string, array{0: string, 1: string, 2: bool}> Cases.
	 */
	public static function cases(): array {
		return array(
			'prefix match'          => array( '_transient_*', '_transient_feed_123', true ),
			'prefix no match'       => array( '_transient_*', '_site_transient_x', false ),
			'contains'              => array( '*pass*', 'smtp_password', true ),
			'contains mixed case'   => array( '*api_key*', 'Stripe_API_Key_live', true ),
			'exact'                 => array( 'cron', 'cron', true ),
			'exact is anchored'     => array( 'cron', 'cron_backup', false ),
			'suffix'                => array( '*_lock', '_edit_lock', true ),
			'question mark'         => array( 'widget_?', 'widget_a', true ),
			'question mark one'     => array( 'widget_?', 'widget_ab', false ),
			'regex chars are plain' => array( 'a.b(c)', 'a.b(c)', true ),
			'dot is not wildcard'   => array( 'a.b', 'axb', false ),
		);
	}

	/**
	 * Each case matches as expected.
	 *
	 * @param string $pattern  Pattern.
	 * @param string $name     Name.
	 * @param bool   $expected Expected result.
	 *
	 * @return void
	 */
	#[DataProvider( 'cases' )]
	public function test_matching( string $pattern, string $name, bool $expected ): void {
		$this->assertSame( $expected, ( new PatternMatcher( array( $pattern ) ) )->matches( $name ) );
	}

	/**
	 * Any of several patterns can match; blank patterns are ignored.
	 *
	 * @return void
	 */
	public function test_multiple_and_blank_patterns(): void {
		$matcher = new PatternMatcher( array( '', '  ', 'foo', 'bar*' ) );

		$this->assertTrue( $matcher->matches( 'barn' ) );
		$this->assertFalse( $matcher->matches( '' ) );
		$this->assertFalse( $matcher->is_empty() );
		$this->assertTrue( ( new PatternMatcher( array( '' ) ) )->is_empty() );
	}
}
