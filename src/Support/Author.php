<?php
/**
 * Author contact details.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Support;

/**
 * Where integration requests and "buy me a coffee" donations go. Change them here only;
 * the admin app reads them from window.wpalConfig.author.
 */
final class Author {

	/**
	 * Address integration requests are emailed to.
	 */
	public const REQUEST_EMAIL = 'slightgrey+ledger@gmail.com';

	/**
	 * PayPal.Me name (https://paypal.me/<name>/<amount>USD).
	 */
	public const PAYPAL_ME = 'vlayte';

	/**
	 * Smallest donation amount, in US dollars.
	 */
	public const MIN_DONATION = 1;
}
