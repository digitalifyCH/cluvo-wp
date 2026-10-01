<?php
/**
 * Release builder for the Cluvo WordPress plugin.
 *
 * Produces, in dist/:
 *   - cluvo-<version>.zip   the installable plugin (top-level folder: cluvo/)
 *   - info.json             what the in-plugin updater reads from wp.cluvo.ch
 *   - icon-*.png            icons for the WordPress update screens
 *   - index.html            download page for people (wp.cluvo.ch/cluvo/)
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
	'size'         => filesize( $zip_path ),
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

/* ---- Download page (wp.cluvo.ch/cluvo/) -------------------------------- */

file_put_contents( "{$dist}/index.html", landing_page( $info ) );
echo "  → {$dist}/index.html\n";

/* ---- Release notes (current version's changelog) --------------------- */

file_put_contents( "{$dist}/release-notes.md", implode( "\n", array_map( fn ( $line ) => "- {$line}", $changelog ) ) . "\n" );
echo "  → {$dist}/release-notes.md\n";

// For the GitHub Action
if ( getenv( 'GITHUB_OUTPUT' ) ) {
	file_put_contents( getenv( 'GITHUB_OUTPUT' ), "version={$version}\n", FILE_APPEND );
}

echo "Done.\n";

/* --------------------------------------------------------------------- */

/**
 * The page people see on wp.cluvo.ch: version, download, installation, changelog. Plain HTML
 * with inline styles – no fonts, scripts or requests to other servers.
 */
function landing_page( array $info ) {
	$e        = fn ( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	$version  = $e( $info['version'] );
	$download = $e( $info['download_url'] );
	$icon     = isset( $info['icons']['2x'] ) ? '<img src="' . $e( $info['icons']['2x'] ) . '" alt="" width="56" height="56">' : '';
	$date     = $e( gmdate( 'd.m.Y', strtotime( $info['last_updated'] . ' UTC' ) ) );
	$size     = $e( number_format( (float) ( $info['size'] ?? 0 ) / 1024, 0, '.', "'" ) );

	return <<<HTML
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cluvo für WordPress {$version}</title>
<meta name="description" content="Formulare, Veranstaltungen und News aus Cluvo auf deiner WordPress-Webseite. Version {$version} herunterladen.">
<link rel="icon" href="icon-128x128.png">
<style>
:root { --bg: #f4f5f3; --card: #fff; --text: #0b1512; --muted: #5d6470; --line: #e3e6e1; --accent: #009966; --accent-text: #fff; --code: #eef1ec; }
@media (prefers-color-scheme: dark) {
  :root { --bg: #0d1412; --card: #151e1b; --text: #e8efeb; --muted: #9aa7a0; --line: #26322d; --accent: #2fbf8a; --accent-text: #04140e; --code: #1d2925; }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
main { max-width: 760px; margin: 0 auto; padding: 48px 16px 64px; }
.card { background: var(--card); border: 1px solid var(--line); border-radius: 20px; padding: 32px; }
.card + .card { margin-top: 20px; }
header { display: flex; gap: 16px; align-items: center; }
header img { border-radius: 14px; flex: none; }
h1 { font-size: 26px; line-height: 1.2; margin: 0; letter-spacing: -0.01em; }
.lead { margin: 4px 0 0; color: var(--muted); }
.download { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 20px; margin-top: 28px; }
.button { display: inline-block; background: var(--accent); color: var(--accent-text); text-decoration: none; font-weight: 600; padding: 12px 22px; border-radius: 999px; }
.button:hover { filter: brightness(1.08); }
.meta { color: var(--muted); font-size: 14px; }
h2 { font-size: 18px; margin: 0 0 12px; }
h4 { font-size: 15px; margin: 22px 0 6px; }
h4:first-child { margin-top: 0; }
ol, ul { padding-left: 22px; margin: 8px 0; }
li { margin: 4px 0; }
p { margin: 8px 0; }
code { background: var(--code); border-radius: 6px; padding: 1px 6px; font-size: 0.92em; }
a { color: var(--accent); }
.changelog { max-height: 420px; overflow: auto; padding-right: 8px; }
footer { margin-top: 28px; text-align: center; color: var(--muted); font-size: 14px; }
footer a { color: inherit; }
</style>
</head>
<body>
<main>
  <section class="card">
    <header>
      {$icon}
      <div>
        <h1>Cluvo für WordPress</h1>
        <p class="lead">Formulare, Veranstaltungen und News aus Cluvo auf deiner WordPress-Webseite.</p>
      </div>
    </header>
    <div class="download">
      <a class="button" href="{$download}">Version {$version} herunterladen</a>
      <span class="meta">ZIP, {$size} KB · veröffentlicht am {$date} · WordPress ab {$e( $info['requires'] )}, PHP ab {$e( $info['requires_php'] )}</span>
    </div>
  </section>

  <section class="card">
    <h2>Installation</h2>
    {$info['sections']['installation']}
    <p>Das Plugin braucht Cluvo Pro – in Cluvo Basic ist die Schnittstelle für WordPress gesperrt.</p>
  </section>

  <section class="card">
    <h2>Updates</h2>
    <p>Ist das Plugin installiert, holt WordPress neue Versionen von selbst hier ab. Sie erscheinen unter Dashboard → Aktualisierungen, auf Wunsch auch automatisch. Unter Einstellungen → Cluvo siehst du die installierte Version und kannst sofort nach Updates suchen.</p>
  </section>

  <section class="card">
    <h2>Was ist neu</h2>
    <div class="changelog">{$info['sections']['changelog']}</div>
  </section>

  <footer>
    <a href="https://www.cluvo.ch">Cluvo</a> · Vereinssoftware aus der Schweiz · <a href="https://www.cluvo.ch/hilfe">Hilfe</a> · <a href="https://www.cluvo.ch/impressum">Impressum</a>
  </footer>
</main>
</body>
</html>

HTML;
}

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

	$text = preg_replace( '/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/', '<a href="$2">$1</a>', $text );

	// Nackte Adressen klickbar machen (nicht die eben erzeugten Links)
	return preg_replace( '/(?<!["\'>])\b(https?:\/\/[^\s<]+[^\s<.,;:!?)])/', '<a href="$1">$1</a>', $text );
}
