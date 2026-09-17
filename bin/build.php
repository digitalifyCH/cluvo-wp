<?php
/**
 * Release builder for the Cluvo WordPress plugin.
 *
 * Produces, in dist/:
 *   - cluvo-<version>.zip   the installable plugin (top-level folder: cluvo/)
 *   - release-notes.md      the current version's changelog (GitHub Release body)
 *
 * In CI these become a GitHub Release: the zip is the downloadable asset the
 * in-plugin updater installs, the notes become the release description.
 *
 * Usage:  php bin/build.php
 */

$root = dirname( __DIR__ );
$dist = $root . '/dist';

// Files/folders that go into the plugin ZIP (dev files like bin/.github excluded).
$include = array( 'cluvo.php', 'uninstall.php', 'readme.txt', 'includes', 'assets', 'languages' );

/* --------------------------------------------------------------------- */

$main = file_get_contents( $root . '/cluvo.php' );
if ( ! preg_match( '/^\s*\*\s*Version:\s*(.+)$/m', $main, $m ) ) {
	fwrite( STDERR, "Could not read Version from cluvo.php\n" );
	exit( 1 );
}
$version = trim( $m[1] );

// Sanity-check the header version matches the CLUVO_VERSION constant.
if ( preg_match( "/define\(\s*'CLUVO_VERSION',\s*'([^']+)'/", $main, $cm ) && $cm[1] !== $version ) {
	fwrite( STDERR, "Version mismatch: header {$version} vs CLUVO_VERSION {$cm[1]}\n" );
	exit( 1 );
}

echo "Building Cluvo {$version}…\n";

if ( ! is_dir( $dist ) ) {
	mkdir( $dist, 0755, true );
}

/* ---- ZIP ------------------------------------------------------------- */

$zip_path = "{$dist}/cluvo-{$version}.zip";
@unlink( $zip_path );

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Cannot create {$zip_path}\n" );
	exit( 1 );
}

foreach ( $include as $item ) {
	$path = "{$root}/{$item}";
	if ( is_file( $path ) ) {
		$zip->addFile( $path, "cluvo/{$item}" );
	} elseif ( is_dir( $path ) ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$local = 'cluvo/' . substr( $file->getPathname(), strlen( $root ) + 1 );
			$zip->addFile( $file->getPathname(), $local );
		}
	}
}
$zip->close();
echo "  → {$zip_path}\n";

/* ---- Release notes (current version's changelog) --------------------- */

$readme = file_get_contents( $root . '/readme.txt' );
$notes  = extract_version_changelog( $readme, $version );
if ( '' === $notes ) {
	$notes = "Cluvo {$version}";
}
file_put_contents( "{$dist}/release-notes.md", $notes . "\n" );
echo "  → {$dist}/release-notes.md\n";
echo "Done.\n";

/* --------------------------------------------------------------------- */

/**
 * Pull the "* item" lines under "= <version> =" from the readme changelog and
 * return them as Markdown bullets.
 */
function extract_version_changelog( $readme, $version ) {
	if ( ! preg_match( '/==\s*Changelog\s*==(.*?)(\n==\s|\z)/s', $readme, $m ) ) {
		return '';
	}

	$section = $m[1];
	$pattern = '/^=\s*' . preg_quote( $version, '/' ) . '\s*=\s*$(.*?)(^\=\s|\z)/ms';
	if ( ! preg_match( $pattern, $section, $vm ) ) {
		return '';
	}

	$lines = array();
	foreach ( preg_split( '/\r?\n/', trim( $vm[1] ) ) as $line ) {
		$line = trim( $line );
		if ( preg_match( '/^\*\s*(.+)$/', $line, $li ) ) {
			$lines[] = '- ' . $li[1];
		}
	}

	return implode( "\n", $lines );
}
