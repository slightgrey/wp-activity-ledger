<?php
/**
 * Tests for the GitHub release updater.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Unit\Update;

use WPActivityLedger\Update\ReleaseSource;
use WPActivityLedger\Update\Updater;
use PHPUnit\Framework\TestCase;

/**
 * GitHub releases become WordPress update offers for this plugin only.
 */
final class UpdaterTest extends TestCase {

	private const FILE = 'wp-activity-ledger/wp-activity-ledger.php';

	/**
	 * A parsed release, as ReleaseSource::latest() returns it.
	 *
	 * @return array<string, string> Release.
	 */
	private function release(): array {
		return array(
			'version'   => '1.3.0',
			'package'   => 'https://github.com/webofd/wp-activity-ledger/releases/download/v1.3.0/wp-activity-ledger-v1.3.0.zip',
			'url'       => 'https://github.com/webofd/wp-activity-ledger/releases/tag/v1.3.0',
			'changelog' => '<h2>What\'s new</h2><p>Stuff.</p><script>alert(1)</script>',
			'published' => '2026-11-04T04:00:00Z',
		);
	}

	/**
	 * Builds an updater whose release source returns $release.
	 *
	 * @param array<string, string>|null $release Release.
	 *
	 * @return Updater Updater.
	 */
	private function updater( ?array $release ): Updater {
		return new Updater( self::FILE, 'WP Activity Ledger', 'https://github.com/webofd/wp-activity-ledger', static fn(): ?array => $release );
	}

	/**
	 * The release becomes the update data WordPress expects.
	 *
	 * @return void
	 */
	public function test_offers_release(): void {
		$update = $this->updater( $this->release() )->check_update(
			false,
			array(
				'RequiresWP'  => '6.0',
				'RequiresPHP' => '8.1',
			),
			self::FILE
		);

		$this->assertIsArray( $update );
		$this->assertSame( 'wp-activity-ledger', $update['slug'] );
		$this->assertSame( '1.3.0', $update['version'] );
		$this->assertStringEndsWith( 'wp-activity-ledger-v1.3.0.zip', $update['package'] );
		$this->assertSame( '8.1', $update['requires_php'] );
	}

	/**
	 * Other plugins on github.com and missing releases are left alone.
	 *
	 * @return void
	 */
	public function test_ignores_other_plugins_and_missing_release(): void {
		$other = array( 'version' => '9.9.9' );

		$this->assertSame( $other, $this->updater( $this->release() )->check_update( $other, array(), 'other/other.php' ) );
		$this->assertFalse( $this->updater( null )->check_update( false, array(), self::FILE ) );
	}

	/**
	 * The details popup shows the release notes, with scripts stripped.
	 *
	 * @return void
	 */
	public function test_plugin_info(): void {
		$updater = $this->updater( $this->release() );
		$info    = $updater->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'wp-activity-ledger' ) );

		$this->assertIsObject( $info );
		$this->assertSame( '1.3.0', $info->version );
		$this->assertStringContainsString( '<h2>What\'s new</h2>', $info->sections['changelog'] );
		$this->assertStringNotContainsString( '<script>', $info->sections['changelog'] );
		$this->assertFalse( $updater->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) ) );
		$this->assertFalse( $updater->plugin_info( false, 'query_plugins', (object) array( 'slug' => 'wp-activity-ledger' ) ) );
	}

	/**
	 * A GitHub release is reduced to its version, zip and notes; drafts and zip-less releases are skipped.
	 *
	 * @return void
	 */
	public function test_parse_release(): void {
		$data = array(
			'tag_name'     => 'v1.3.0',
			'html_url'     => 'https://github.com/webofd/wp-activity-ledger/releases/tag/v1.3.0',
			'body_html'    => '<p>Notes</p>',
			'published_at' => '2026-11-04T04:00:00Z',
			'assets'       => array(
				array(
					'name'                 => 'checksums.txt',
					'browser_download_url' => 'https://example.test/checksums.txt',
				),
				array(
					'name'                 => 'wp-activity-ledger-v1.3.0.zip',
					'browser_download_url' => 'https://example.test/wp-activity-ledger-v1.3.0.zip',
				),
			),
		);

		$release = ReleaseSource::parse( $data, 'wp-activity-ledger' );

		$this->assertSame( '1.3.0', $release['version'] ?? null );
		$this->assertSame( 'https://example.test/wp-activity-ledger-v1.3.0.zip', $release['package'] ?? null );
		$this->assertNull( ReleaseSource::parse( array_merge( $data, array( 'draft' => true ) ), 'wp-activity-ledger' ) );
		$this->assertNull( ReleaseSource::parse( array_merge( $data, array( 'assets' => array() ) ), 'wp-activity-ledger' ) );
	}

	/**
	 * The repository comes from the plugin's Update URI header.
	 *
	 * @return void
	 */
	public function test_repository_from_update_uri(): void {
		$this->assertSame( 'webofd/wp-activity-ledger', Updater::repository( 'https://github.com/webofd/wp-activity-ledger' ) );
		$this->assertSame( 'webofd/wp-activity-ledger', Updater::repository( 'https://github.com/webofd/wp-activity-ledger/' ) );
		$this->assertNull( Updater::repository( 'https://example.com/webofd/wp-activity-ledger' ) );
		$this->assertNull( Updater::repository( 'https://github.com/webofd' ) );
	}
}
