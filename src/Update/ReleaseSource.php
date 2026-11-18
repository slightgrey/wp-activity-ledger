<?php
/**
 * Reads the latest GitHub release of the plugin.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Update;

/**
 * Fetches the latest release from the GitHub API and caches it in a transient, so
 * update checks don't call GitHub on every request.
 *
 * @phpstan-type Release array{version: string, package: string, url: string, changelog: string, published: string}
 */
class ReleaseSource {

	/**
	 * Transient holding the last fetched release (or an empty array after a failed fetch).
	 */
	public const CACHE_KEY = 'wpal_github_release';

	/**
	 * How long a fetched release is cached.
	 */
	private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * How long a failed fetch is cached before GitHub is asked again.
	 */
	private const FAILURE_TTL = HOUR_IN_SECONDS;

	/**
	 * Creates the source.
	 *
	 * @param string $repository GitHub "owner/name".
	 * @param string $asset_slug Release zip names start with this, e.g. "wp-activity-ledger".
	 */
	public function __construct(
		private readonly string $repository,
		private readonly string $asset_slug
	) {
	}

	/**
	 * Returns the latest release, from cache when fresh.
	 *
	 * @return array<string, string>|null Release, or null when GitHub has none with a zip.
	 * @phpstan-return Release|null
	 */
	public function latest(): ?array {
		$cached = $this->force_check() ? false : get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) ) {
			return array() === $cached ? null : $cached;
		}

		$release = $this->fetch();
		set_transient( self::CACHE_KEY, $release ?? array(), null === $release ? self::FAILURE_TTL : self::CACHE_TTL );

		return $release;
	}

	/**
	 * Asks GitHub for the latest release.
	 *
	 * @return array<string, string>|null Release, or null on any error.
	 * @phpstan-return Release|null
	 */
	private function fetch(): ?array {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . $this->repository . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github.html+json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? self::parse( $data, $this->asset_slug ) : null;
	}

	/**
	 * Picks the fields the updater needs out of a GitHub release.
	 *
	 * @param array<string, mixed> $data       GitHub release JSON.
	 * @param string               $asset_slug Expected start of the zip's file name.
	 *
	 * @return array<string, string>|null Release, or null for drafts, pre-releases and releases without the zip.
	 * @phpstan-return Release|null
	 */
	public static function parse( array $data, string $asset_slug ): ?array {
		$version = ltrim( (string) ( $data['tag_name'] ?? '' ), 'vV' );

		if ( '' === $version || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}

		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			$name = is_array( $asset ) ? (string) ( $asset['name'] ?? '' ) : '';

			if ( str_starts_with( $name, $asset_slug ) && str_ends_with( $name, '.zip' ) ) {
				return array(
					'version'   => $version,
					'package'   => (string) ( $asset['browser_download_url'] ?? '' ),
					'url'       => (string) ( $data['html_url'] ?? '' ),
					'changelog' => (string) ( $data['body_html'] ?? '' ),
					'published' => (string) ( $data['published_at'] ?? '' ),
				);
			}
		}

		return null;
	}

	/**
	 * Tells whether an admin pressed "Check again" on Dashboard → Updates.
	 *
	 * @return bool True to skip the cache.
	 */
	private function force_check(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag set by core's "Check again" link; it only bypasses a cache.
		return is_admin() && isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' );
	}
}
