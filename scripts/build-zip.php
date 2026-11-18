<?php
/**
 * Builds the release zip: wp-activity-ledger-v{VERSION}.zip in the plugin root.
 *
 * Usage (from the plugin root): php scripts/build-zip.php
 *
 * Builds the admin app first (npm run build; set SKIP_NPM_BUILD=1 to use the current dist/).
 *
 * The build runs in a temporary staging copy: the shipped files plus
 * composer.json/.lock are copied there and `composer install --no-dev` runs in
 * the copy, so the working vendor/ (and the live dev site using it) is never
 * touched. Set COMPOSER_BIN to override the composer command.
 *
 * @package WPActivityLedger
 */

// phpcs:disable -- Build tool, not shipped in the release zip.

declare( strict_types=1 );

const WPAL_BUILD_SLUG    = 'wp-activity-ledger';
const WPAL_BUILD_SOURCES  = array( 'wp-activity-ledger.php', 'composer.json', 'composer.lock', 'src', 'dist', 'languages', 'README.md', 'LICENSE' );
const WPAL_BUILD_OPTIONAL = array( 'uninstall.php' );
const WPAL_BUILD_SHIPPED  = array( 'wp-activity-ledger.php', 'composer.json', 'src', 'dist', 'languages', 'README.md', 'LICENSE', 'vendor' );

/**
 * Prints a message and stops with a failure code.
 *
 * @param string $message Error.
 *
 * @return void
 */
function wpal_build_fail( string $message ): void {
	fwrite( STDERR, "Build failed: {$message}\n" );
	exit( 1 );
}

/**
 * Reads the plugin version from the main file header.
 *
 * @param string $root Plugin root.
 *
 * @return string Version.
 */
function wpal_build_version( string $root ): string {
	$header = (string) file_get_contents( $root . '/' . WPAL_BUILD_SLUG . '.php', false, null, 0, 2048 );

	if ( ! preg_match( '/^\s*\*\s*Version:\s*([0-9][0-9A-Za-z.\-]*)/m', $header, $match ) ) {
		wpal_build_fail( 'no Version header in the main plugin file.' );
	}

	return $match[1];
}

/**
 * Lists every file under the given paths, relative to $root.
 *
 * @param string             $root  Base directory.
 * @param array<int, string> $paths Files or directories relative to $root.
 *
 * @return array<int, string> Relative paths with forward slashes, sorted.
 */
function wpal_build_files( string $root, array $paths ): array {
	$files = array();

	foreach ( $paths as $path ) {
		$full = $root . '/' . $path;

		if ( is_file( $full ) ) {
			$files[] = $path;
			continue;
		}

		if ( ! is_dir( $full ) ) {
			wpal_build_fail( "missing {$path}." );
		}

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $full, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$files[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
			}
		}
	}

	sort( $files );

	return $files;
}

/**
 * Copies the source files into a fresh staging directory.
 *
 * @param string $root Plugin root.
 *
 * @return string Staging directory.
 */
function wpal_build_stage( string $root ): string {
	$stage = str_replace( '\\', '/', sys_get_temp_dir() ) . '/wpal-build-' . bin2hex( random_bytes( 4 ) );

	$optional = array_values( array_filter( WPAL_BUILD_OPTIONAL, static fn( string $path ): bool => file_exists( $root . '/' . $path ) ) );

	foreach ( wpal_build_files( $root, array_merge( WPAL_BUILD_SOURCES, $optional ) ) as $file ) {
		$target = $stage . '/' . $file;

		if ( ! is_dir( dirname( $target ) ) && ! mkdir( dirname( $target ), 0777, true ) ) {
			wpal_build_fail( "cannot create {$target}." );
		}

		copy( $root . '/' . $file, $target );
	}

	return $stage;
}

/**
 * Runs `composer install --no-dev` in the staging directory.
 *
 * @param string $stage Staging directory.
 *
 * @return void
 */
function wpal_build_composer( string $stage ): void {
	$composer = getenv( 'COMPOSER_BIN' ) ?: 'composer';
	$command  = 'cd ' . escapeshellarg( $stage ) . ' && ' . $composer . ' install --no-dev --optimize-autoloader --no-interaction --quiet';

	passthru( $command, $code );

	if ( 0 !== $code ) {
		wpal_build_fail( "composer install exited with {$code}." );
	}
}

/**
 * Builds the admin app (dist/admin) unless SKIP_NPM_BUILD is set.
 *
 * @param string $root Plugin root.
 *
 * @return void
 */
function wpal_build_assets( string $root ): void {
	if ( ! getenv( 'SKIP_NPM_BUILD' ) ) {
		passthru( 'cd ' . escapeshellarg( $root ) . ' && npm run build --silent', $code );

		if ( 0 !== $code ) {
			wpal_build_fail( "npm run build exited with {$code}." );
		}
	}

	if ( ! is_file( $root . '/dist/admin/.vite/manifest.json' ) ) {
		wpal_build_fail( 'dist/admin is missing; run npm run build.' );
	}
}

/**
 * Writes the zip with every shipped file under a top-level plugin folder.
 *
 * @param string $stage Staging directory.
 * @param string $zip   Zip path.
 *
 * @return int Number of files added.
 */
function wpal_build_zip( string $stage, string $zip ): int {
	$optional = array_values( array_filter( WPAL_BUILD_OPTIONAL, static fn( string $path ): bool => file_exists( $stage . '/' . $path ) ) );
	$files    = wpal_build_files( $stage, array_merge( WPAL_BUILD_SHIPPED, $optional ) );

	if ( file_exists( $zip ) ) {
		unlink( $zip );
	}

	$archive = new ZipArchive();

	if ( true !== $archive->open( $zip, ZipArchive::CREATE ) ) {
		wpal_build_fail( "cannot create {$zip}." );
	}

	foreach ( $files as $file ) {
		$archive->addFile( $stage . '/' . $file, WPAL_BUILD_SLUG . '/' . $file );
	}

	$archive->close();

	return count( $files );
}

/**
 * Deletes a directory tree.
 *
 * @param string $dir Directory.
 *
 * @return void
 */
function wpal_build_remove( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );

	foreach ( $iterator as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}

	rmdir( $dir );
}

if ( ! class_exists( 'ZipArchive' ) ) {
	wpal_build_fail( 'the PHP zip extension is not enabled.' );
}

$wpal_root    = str_replace( '\\', '/', dirname( __DIR__ ) );
$wpal_version = wpal_build_version( $wpal_root );
$wpal_zip     = $wpal_root . '/' . WPAL_BUILD_SLUG . '-v' . $wpal_version . '.zip';

echo 'Building ' . basename( $wpal_zip ) . "\n";
wpal_build_assets( $wpal_root );
$wpal_stage = wpal_build_stage( $wpal_root );

try {
	wpal_build_composer( $wpal_stage );
	$wpal_count = wpal_build_zip( $wpal_stage, $wpal_zip );
} finally {
	wpal_build_remove( $wpal_stage );
}

printf( "Done: %s (%d files, %s KB)\n", basename( $wpal_zip ), $wpal_count, number_format( filesize( $wpal_zip ) / 1024, 1 ) );
