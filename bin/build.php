<?php
/**
 * Release builder for the Cluvo WordPress plugin.
 *
 * Produces, in dist/:
 *   - cluvo-<version>.zip   the installable plugin (top-level folder: cluvo/)
 *   - info.json             what the in-plugin updater reads from wp.cluvo.ch
 *   - icon-*.png            icons for the WordPress update screens
 *   - release-notes.md      the current version's changelog (GitHub Release body)
 *
 * The GitHub Action uploads the first three to wp.cluvo.ch/cluvo/ and creates a
 * GitHub Release with the ZIP (for installations ≤ 0.2.0, which still ask GitHub).
 *
 * Usage:  php bin/build.php
 *         CLUVO_UPDATE_BASE=https://staging.example.ch/cluvo php bin/build.php
 */

$root = dirname( __DIR__ );
$dist = $root . '/dist';
$base = rtrim( getenv( 'CLUVO_UPDATE_BASE' ) ?: 'https://wp.cluvo.ch/cluvo', '/' );

// Files/folders that go into the plugin ZIP (dev files like bin/.github excluded).
$include = array( 'cluvo.php', 'uninstall.php', 'readme.txt', 'includes', 'assets', 'languages' );

/* ---- Version and sanity checks --------------------------------------- */

$main   = file_get_contents( $root . '/cluvo.php' );
$readme = file_get_contents( $root . '/readme.txt' );

$version = header_value( $main, 'Version' );
if ( '' === $version || ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
	fail( "Could not read a MAJOR.MINOR.PATCH Version from cluvo.php (got \"{$version}\")" );
}

if ( preg_match( "/define\(\s*'CLUVO_VERSION',\s*'([^']+)'/", $main, $cm ) && $cm[1] !== $version ) {
	fail( "Version mismatch: header {$version} vs CLUVO_VERSION {$cm[1]}" );
}

if ( header_value( $readme, 'Stable tag' ) !== $version ) {
	fail( "readme.txt: Stable tag must be {$version}" );
}

// Fix wp.cluvo.ch – the updater listens on this host, a staging server is chosen on the site (CLUVO_UPDATE_URL)
if ( 'https://wp.cluvo.ch/cluvo/' !== header_value( $main, 'Update URI' ) ) {
	fail( 'cluvo.php: Update URI must be https://wp.cluvo.ch/cluvo/ – otherwise WordPress never asks the update server' );
}

$changelog = version_changelog( $readme, $version );
if ( array() === $changelog ) {
	fail( "readme.txt: no changelog entries under \"= {$version} =\"" );
}

echo "Building Cluvo {$version}…\n";

if ( ! is_dir( $dist ) ) {
	mkdir( $dist, 0755, true );
}
foreach ( glob( "{$dist}/*" ) as $old ) {
	unlink( $old );
}

/* ---- ZIP ------------------------------------------------------------- */

$zip_name = "cluvo-{$version}.zip";
$zip_path = "{$dist}/{$zip_name}";

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
	fail( "Cannot create {$zip_path}" );
}

foreach ( $include as $item ) {
	$path = "{$root}/{$item}";
	if ( is_file( $path ) ) {
		$zip->addFile( $path, "cluvo/{$item}" );
	} elseif ( is_dir( $path ) ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( '.DS_Store' === $file->getFilename() ) {
				continue;
			}
			$local = 'cluvo/' . substr( $file->getPathname(), strlen( $root ) + 1 );
			$zip->addFile( $file->getPathname(), $local );
		}
	}
}
$zip->close();
echo "  → {$zip_path}\n";

/* ---- Icons ------------------------------------------------------------- */

$icons = array();
foreach ( array( '1x' => 'icon-128x128.png', '2x' => 'icon-256x256.png' ) as $size => $icon ) {
	if ( is_file( "{$root}/release-assets/{$icon}" ) ) {
		copy( "{$root}/release-assets/{$icon}", "{$dist}/{$icon}" );
		$icons[ $size ] = "{$base}/{$icon}";
	}
}

/* ---- info.json (read by the in-plugin updater) ------------------------ */

$info = array(
	'name'         => 'Cluvo',
	'slug'         => 'cluvo',
	'version'      => $version,
	'download_url' => "{$base}/{$zip_name}",
	'sha256'       => hash_file( 'sha256', $zip_path ),
	'homepage'     => 'https://www.cluvo.ch',
	'requires'     => header_value( $readme, 'Requires at least' ),
	'tested'       => header_value( $readme, 'Tested up to' ),
	'requires_php' => header_value( $readme, 'Requires PHP' ),
	'last_updated' => gmdate( 'Y-m-d H:i:s' ),
	'icons'        => $icons,
	'sections'     => array(
		'description'  => readme_to_html( readme_section( $readme, 'Description' ) ),
		'installation' => readme_to_html( readme_section( $readme, 'Installation' ) ),
		'changelog'    => readme_to_html( readme_section( $readme, 'Changelog' ) ),
	),
);

file_put_contents( "{$dist}/info.json", json_encode( $info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
echo "  → {$dist}/info.json\n";

/* ---- Release notes (current version's changelog) --------------------- */

file_put_contents( "{$dist}/release-notes.md", implode( "\n", array_map( fn ( $line ) => "- {$line}", $changelog ) ) . "\n" );
echo "  → {$dist}/release-notes.md\n";

// For the GitHub Action
if ( getenv( 'GITHUB_OUTPUT' ) ) {
	file_put_contents( getenv( 'GITHUB_OUTPUT' ), "version={$version}\n", FILE_APPEND );
}

echo "Done.\n";

/* --------------------------------------------------------------------- */

function fail( $message ) {
	fwrite( STDERR, "Error: {$message}\n" );
	exit( 1 );
}

/** "Version: 0.3.0" in the plugin header or "Stable tag: 0.3.0" in the readme. */
function header_value( $text, $name ) {
	return preg_match( '/^[\s*]*' . preg_quote( $name, '/' ) . ':\s*(.+)$/mi', $text, $m ) ? trim( $m[1] ) : '';
}

/** Body of "== Name ==" in readme.txt. */
function readme_section( $readme, $name ) {
	return preg_match( '/^==\s*' . preg_quote( $name, '/' ) . '\s*==\s*$(.*?)(?=^==\s|\z)/msi', $readme, $m ) ? trim( $m[1] ) : '';
}

/** The "* item" lines under "= <version> =" in the changelog. */
function version_changelog( $readme, $version ) {
	$section = readme_section( $readme, 'Changelog' );
	if ( ! preg_match( '/^=\s*' . preg_quote( $version, '/' ) . '\s*=\s*$(.*?)(?=^=\s|\z)/ms', $section, $m ) ) {
		return array();
	}

	$lines = array();
	foreach ( preg_split( '/\r?\n/', trim( $m[1] ) ) as $line ) {
		if ( preg_match( '/^\*\s*(.+)$/', trim( $line ), $li ) ) {
			$lines[] = $li[1];
		}
	}

	return $lines;
}

/**
 * readme.txt markup → HTML for the "View details" modal: "= Title =" headings,
 * "* " and "1. " lists, paragraphs, `code` and [text](url) links.
 */
function readme_to_html( $text ) {
	$html  = '';
	$list  = '';
	$para  = array();
	$flush = function () use ( &$html, &$list, &$para ) {
		if ( $para ) {
			$html .= '<p>' . inline_markup( implode( ' ', $para ) ) . "</p>\n";
			$para  = array();
		}
		if ( $list ) {
			$html .= "</{$list}>\n";
			$list  = '';
		}
	};

	foreach ( preg_split( '/\r?\n/', $text ) as $line ) {
		$line = rtrim( $line );

		if ( preg_match( '/^=\s*(.+?)\s*=$/', $line, $m ) ) {
			$flush();
			$html .= '<h4>' . inline_markup( $m[1] ) . "</h4>\n";
		} elseif ( preg_match( '/^(\*|\d+\.)\s+(.+)$/', $line, $m ) ) {
			$type = '*' === $m[1] ? 'ul' : 'ol';
			if ( $para || $list !== $type ) {
				$flush();
				$html .= "<{$type}>\n";
				$list  = $type;
			}
			$html .= '<li>' . inline_markup( $m[2] ) . "</li>\n";
		} elseif ( '' === trim( $line ) ) {
			$flush();
		} elseif ( $list && preg_match( '/^\s+\S/', $line ) ) {
			// Continuation of the previous list item
			$html = preg_replace( '#</li>\n$#', ' ' . inline_markup( trim( $line ) ) . "</li>\n", $html );
		} else {
			if ( $list ) {
				$flush();
			}
			$para[] = trim( $line );
		}
	}
	$flush();

	return trim( $html );
}

function inline_markup( $text ) {
	$text = htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
	$text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );

	return preg_replace( '/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/', '<a href="$2">$1</a>', $text );
}
