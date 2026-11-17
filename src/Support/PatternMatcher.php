<?php
/**
 * Glob-style name matching.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Support;

/**
 * Matches names (option names, meta keys, field keys) against glob patterns such as
 * "_transient_*" or "*pass*". "*" matches any run of characters, "?" exactly one.
 * Matching is case-insensitive and anchored to the whole name.
 */
final class PatternMatcher {

	/**
	 * Compiled regular expressions.
	 *
	 * @var array<int, string>
	 */
	private array $regexes;

	/**
	 * Creates a matcher.
	 *
	 * @param array<int, string> $patterns Glob patterns; empty strings are ignored.
	 */
	public function __construct( array $patterns ) {
		$patterns      = array_filter( array_map( 'trim', $patterns ), static fn( string $p ): bool => '' !== $p );
		$this->regexes = array_values( array_map( array( self::class, 'to_regex' ), array_unique( $patterns ) ) );
	}

	/**
	 * Tells whether a name matches any pattern.
	 *
	 * @param string $name Name to test.
	 *
	 * @return bool True on a match.
	 */
	public function matches( string $name ): bool {
		foreach ( $this->regexes as $regex ) {
			if ( 1 === preg_match( $regex, $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tells whether the matcher has no patterns.
	 *
	 * @return bool True when empty.
	 */
	public function is_empty(): bool {
		return array() === $this->regexes;
	}

	/**
	 * Converts one glob pattern to an anchored, case-insensitive regex.
	 *
	 * @param string $pattern Glob pattern.
	 *
	 * @return string Regex.
	 */
	private static function to_regex( string $pattern ): string {
		$quoted = preg_quote( $pattern, '/' );

		return '/^' . str_replace( array( '\*', '\?' ), array( '.*', '.' ), $quoted ) . '$/i';
	}
}
