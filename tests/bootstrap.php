<?php
/**
 * PHPUnit bootstrap for WP Activity Ledger unit tests.
 *
 * Unit tests run without a database. WordPress hook functions are replaced by
 * stubs that record calls in $GLOBALS['wpal_test_hooks'] (registrations) and
 * $GLOBALS['wpal_test_actions'] (fired actions), filters registered by tests live
 * in $GLOBALS['wpal_test_filters'], options come from $GLOBALS['wpal_test_options'],
 * and WordPress core's formatting and kses files are loaded straight from wp-includes.
 *
 * Set WPAL_WP_INCLUDES to point at a different wp-includes directory.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 4 ) . '/' );
}

/**
 * Hook registrations: list of [type, hook, callback, priority, accepted_args].
 *
 * @var array<int, array{0: string, 1: string, 2: callable, 3: int, 4: int}> $GLOBALS['wpal_test_hooks']
 */
$GLOBALS['wpal_test_hooks'] = array();

/**
 * Fired actions: list of [hook, args].
 *
 * @var array<int, array{0: string, 1: array<int, mixed>}> $GLOBALS['wpal_test_actions']
 */
$GLOBALS['wpal_test_actions'] = array();

/**
 * Filters registered by tests: hook name => callback.
 *
 * @var array<string, callable> $GLOBALS['wpal_test_filters']
 */
$GLOBALS['wpal_test_filters'] = array();

/**
 * Option values returned by the get_option() stub.
 *
 * @var array<string, mixed> $GLOBALS['wpal_test_options']
 */
$GLOBALS['wpal_test_options'] = array(
	'blog_charset' => 'UTF-8',
	'admin_email'  => 'admin@example.test',
);

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Runs a test-registered filter, or returns the value unchanged.
	 *
	 * @param string $hook    Hook name.
	 * @param mixed  $value   Value to filter.
	 * @param mixed  ...$args Extra arguments.
	 *
	 * @return mixed Filtered value.
	 */
	function apply_filters( $hook, $value, ...$args ) {
		if ( isset( $GLOBALS['wpal_test_filters'][ $hook ] ) ) {
			return $GLOBALS['wpal_test_filters'][ $hook ]( $value, ...$args );
		}

		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Records a fired action.
	 *
	 * @param string $hook    Hook name.
	 * @param mixed  ...$args Arguments.
	 *
	 * @return void
	 */
	function do_action( $hook, ...$args ) {
		$GLOBALS['wpal_test_actions'][] = array( $hook, $args );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Records an action registration.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted args.
	 *
	 * @return bool Always true.
	 */
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['wpal_test_hooks'][] = array( 'action', $hook, $callback, $priority, $accepted_args );
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Records a filter registration.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted args.
	 *
	 * @return bool Always true.
	 */
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['wpal_test_hooks'][] = array( 'filter', $hook, $callback, $priority, $accepted_args );
		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Returns a value from $GLOBALS['wpal_test_options'].
	 *
	 * @param string $option        Option name.
	 * @param mixed  $default_value Fallback.
	 *
	 * @return mixed Option value.
	 */
	function get_option( $option, $default_value = false ) {
		return $GLOBALS['wpal_test_options'][ $option ] ?? $default_value;
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	/**
	 * Returns the test locale.
	 *
	 * @return string Locale.
	 */
	function get_locale() {
		return 'en_US';
	}
}

if ( ! function_exists( 'is_utf8_charset' ) ) {
	/**
	 * Tells whether a charset name means UTF-8 (functions.php is too heavy to load).
	 *
	 * @param string|null $blog_charset Charset name.
	 *
	 * @return bool True for UTF-8.
	 */
	function is_utf8_charset( $blog_charset = null ) {
		return in_array( strtolower( (string) ( $blog_charset ?? 'utf-8' ) ), array( 'utf-8', 'utf8' ), true );
	}
}

if ( ! function_exists( '_canonical_charset' ) ) {
	/**
	 * Returns the canonical charset name.
	 *
	 * @param string $charset Charset name.
	 *
	 * @return string Canonical name.
	 */
	function _canonical_charset( $charset ) {
		return is_utf8_charset( $charset ) ? 'UTF-8' : (string) $charset;
	}
}

if ( ! function_exists( 'wp_allowed_protocols' ) ) {
	/**
	 * Returns WordPress's default list of allowed URL protocols (used by kses).
	 *
	 * @return array<int, string> Protocols.
	 */
	function wp_allowed_protocols() {
		return array( 'http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'irc6', 'ircs', 'gopher', 'nntp', 'feed', 'telnet', 'mms', 'rtsp', 'sms', 'svn', 'tel', 'fax', 'xmpp', 'webcal', 'urn' );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Returns the text untranslated.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 *
	 * @return string Text.
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName -- WordPress core name.
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Returns the text escaped for HTML, untranslated.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 *
	 * @return string Escaped text.
	 */
	function esc_html__( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName -- WordPress core name.
		unset( $domain );
		return esc_html( $text );
	}
}

if ( ! function_exists( '_n' ) ) {
	/**
	 * Picks the singular or plural text, untranslated.
	 *
	 * @param string $single Singular.
	 * @param string $plural Plural.
	 * @param int    $number Count.
	 * @param string $domain Text domain.
	 *
	 * @return string Text.
	 */
	function _n( $single, $plural, $number, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName -- WordPress core name.
		unset( $domain );
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Encodes JSON like WordPress does (no sanity checks needed in tests).
	 *
	 * @param mixed $value Value.
	 * @param int   $flags json_encode flags.
	 * @param int   $depth Depth.
	 *
	 * @return string|false JSON.
	 */
	function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
		return json_encode( $value, $flags, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This is the stub.
	}
}

if ( ! function_exists( 'is_serialized' ) ) {
	/**
	 * Tells whether a value is a serialized string (simplified from functions.php).
	 *
	 * @param mixed $data Value.
	 *
	 * @return bool True when serialized.
	 */
	function is_serialized( $data ) {
		if ( ! is_string( $data ) ) {
			return false;
		}

		$data = trim( $data );

		if ( 'N;' === $data || 'b:0;' === $data ) {
			return true;
		}

		return (bool) preg_match( '/^([adObisCE]):/', $data ) && false !== @unserialize( $data, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Test stub.
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	/**
	 * Merges arguments into defaults.
	 *
	 * @param array<string, mixed>|object $args     Arguments.
	 * @param array<string, mixed>        $defaults Defaults.
	 *
	 * @return array<string, mixed> Merged.
	 */
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( $defaults, is_object( $args ) ? get_object_vars( $args ) : (array) $args );
	}
}

$wpal_wp_includes = getenv( 'WPAL_WP_INCLUDES' ) ? rtrim( (string) getenv( 'WPAL_WP_INCLUDES' ), '/\\' ) . '/' : ABSPATH . 'wp-includes/';

// The HTML API, in wp-settings.php order (kses uses the tag processor).
require_once $wpal_wp_includes . 'class-wp-token-map.php';
foreach ( array( 'html5-named-character-references', 'class-wp-html-attribute-token', 'class-wp-html-span', 'class-wp-html-doctype-info', 'class-wp-html-text-replacement', 'class-wp-html-decoder', 'class-wp-html-tag-processor' ) as $wpal_html_api_file ) {
	require_once $wpal_wp_includes . 'html-api/' . $wpal_html_api_file . '.php';
}

require_once $wpal_wp_includes . 'compat-utf8.php';
require_once $wpal_wp_includes . 'utf8.php';
require_once $wpal_wp_includes . 'formatting.php';
require_once $wpal_wp_includes . 'kses.php';
