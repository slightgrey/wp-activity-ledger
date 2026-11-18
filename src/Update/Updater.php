<?php
/**
 * Offers GitHub releases as WordPress plugin updates.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Update;

/**
 * Answers WordPress's update check for this plugin (the "Update URI" header points at
 * github.com) and fills the "View version details" popup.
 */
class Updater {

	/**
	 * Returns the latest release, or null.
	 *
	 * @var callable(): (array<string, string>|null)
	 */
	private $release;

	/**
	 * Creates the updater.
	 *
	 * @param string   $plugin_file Plugin basename, e.g. "wp-activity-ledger/wp-activity-ledger.php".
	 * @param string   $name        Plugin name, for the details popup.
	 * @param string   $homepage    Repository URL.
	 * @param callable $release     Returns the latest release (see ReleaseSource::latest()), or null.
	 */
	public function __construct(
		private readonly string $plugin_file,
		private readonly string $name,
		private readonly string $homepage,
		callable $release
	) {
		$this->release = $release;
	}

	/**
	 * Builds the updater from the plugin's "Update URI" header (https://github.com/owner/repo).
	 *
	 * @return self|null Updater, or null when the header doesn't name a GitHub repository.
	 */
	public static function create(): ?self {
		$repository = self::repository( (string) get_file_data( WPAL_FILE, array( 'uri' => 'Update URI' ) )['uri'] );

		if ( null === $repository ) {
			return null;
		}

		$source = new ReleaseSource( $repository, 'wp-activity-ledger' );

		return new self( plugin_basename( WPAL_FILE ), 'WP Activity Ledger', 'https://github.com/' . $repository, array( $source, 'latest' ) );
	}

	/**
	 * Returns "owner/repo" from a GitHub URL.
	 *
	 * @param string $url URL, e.g. "https://github.com/webofd/wp-activity-ledger".
	 *
	 * @return string|null Repository, or null for other URLs.
	 */
	public static function repository( string $url ): ?string {
		return 1 === preg_match( '#^https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?)/?$#', trim( $url ), $match ) ? $match[1] : null;
	}

	/**
	 * Filters update_plugins_github.com: returns the latest release for this plugin.
	 *
	 * WordPress compares the version itself and only offers it when it is newer.
	 *
	 * @param array<string, mixed>|false $update      Update found so far (another plugin's filter may have set one).
	 * @param array<string, mixed>       $plugin_data Plugin headers.
	 * @param string                     $plugin_file Plugin basename being checked.
	 *
	 * @return array<string, mixed>|false Update data, or $update unchanged.
	 */
	public function check_update( $update, array $plugin_data, string $plugin_file ) {
		if ( $plugin_file !== $this->plugin_file ) {
			return $update;
		}

		$release = ( $this->release )();

		if ( null === $release || '' === $release['package'] ) {
			return $update;
		}

		return array(
			'slug'         => $this->slug(),
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => (string) ( $plugin_data['RequiresWP'] ?? '' ),
			'requires_php' => (string) ( $plugin_data['RequiresPHP'] ?? '' ),
		);
	}

	/**
	 * Filters plugins_api: fills the "View version details" popup from the release.
	 *
	 * @param false|object|array<string, mixed> $result Result so far.
	 * @param string                            $action API action.
	 * @param object                            $args   Request arguments (slug...).
	 *
	 * @return false|object|array<string, mixed> Plugin information, or $result unchanged.
	 */
	public function plugin_info( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || $this->slug() !== ( $args->slug ?? '' ) ) {
			return $result;
		}

		$release = ( $this->release )();

		if ( null === $release ) {
			return $result;
		}

		return (object) array(
			'name'          => $this->name,
			'slug'          => $this->slug(),
			'version'       => $release['version'],
			'author'        => '<a href="https://vincelayte.me/">Web of D</a>',
			'homepage'      => $this->homepage,
			'download_link' => $release['package'],
			'last_updated'  => $release['published'],
			'sections'      => array(
				'changelog' => '' === $release['changelog'] ? esc_html__( 'See the release page on GitHub.', 'wp-activity-ledger' ) : wp_kses_post( $release['changelog'] ),
			),
		);
	}

	/**
	 * Returns the plugin's folder name, which WordPress uses as its slug.
	 *
	 * @return string Slug.
	 */
	private function slug(): string {
		return dirname( $this->plugin_file );
	}
}
