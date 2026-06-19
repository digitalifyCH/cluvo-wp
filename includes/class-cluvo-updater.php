<?php
/**
 * Self-hosted plugin updater backed by GitHub Releases.
 *
 * Polls the repository's "latest release" via the public GitHub API and feeds
 * the result into WordPress' native update system, so new versions show up in
 * the dashboard with a working "Update now" button and the release notes as
 * changelog in the "View details" modal.
 *
 * Requirements:
 *  - The GitHub repository must be PUBLIC (no token is embedded).
 *  - Each release must attach the BUILT plugin zip (top-level folder cluvo/),
 *    produced by bin/build.php. The source zipball is not installable as-is.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cluvo_Updater {

	/** GitHub repository in "owner/repo" form. Adjust if the repo moves. */
	const GITHUB_REPO = 'digitalifyCH/cluvo-wp';

	const CACHE_KEY = 'cluvo_update_release';
	const CACHE_TTL = 43200; // 12 hours.

	/** @var string e.g. cluvo/cluvo.php */
	private $basename;

	/** @var string e.g. cluvo */
	private $slug;

	public function __construct() {
		$this->basename = plugin_basename( CLUVO_PLUGIN_FILE );
		$this->slug     = dirname( $this->basename );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 20, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_on_update' ), 10, 2 );
	}

	/**
	 * Normalised info about the latest release, cached.
	 *
	 * @return array{version:string, download_url:string, changelog:string, published:string}|array
	 */
	private function get_release() {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : array();
		}

		$repo = apply_filters( 'cluvo_update_github_repo', self::GITHUB_REPO );
		$url  = "https://api.github.com/repos/{$repo}/releases/latest";

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'Cluvo-WordPress-Plugin',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cache the miss briefly so a hiccup doesn't slow every admin page.
			set_transient( self::CACHE_KEY, array(), HOUR_IN_SECONDS );
			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_transient( self::CACHE_KEY, array(), HOUR_IN_SECONDS );
			return array();
		}

		$release = array(
			'version'      => ltrim( $body['tag_name'], 'vV' ),
			'download_url' => $this->find_zip_asset( $body ),
			'changelog'    => isset( $body['body'] ) ? self::markdown_to_html( $body['body'] ) : '',
			'published'    => isset( $body['published_at'] ) ? gmdate( 'Y-m-d', strtotime( $body['published_at'] ) ) : '',
		);

		set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

		return $release;
	}

	/**
	 * Pick the built plugin zip from the release assets (e.g. cluvo-0.2.0.zip).
	 */
	private function find_zip_asset( $body ) {
		if ( empty( $body['assets'] ) || ! is_array( $body['assets'] ) ) {
			return '';
		}
		foreach ( $body['assets'] as $asset ) {
			if ( isset( $asset['name'], $asset['browser_download_url'] ) && preg_match( '/\.zip$/i', $asset['name'] ) ) {
				return $asset['browser_download_url'];
			}
		}
		return '';
	}

	/**
	 * Add our plugin to the update transient when a newer release exists.
	 */
	public function inject_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_release();
		if ( empty( $release['version'] ) || empty( $release['download_url'] ) ) {
			return $transient;
		}

		if ( version_compare( CLUVO_VERSION, $release['version'], '<' ) ) {
			$transient->response[ $this->basename ] = (object) array(
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => $release['version'],
				'url'         => 'https://github.com/' . self::GITHUB_REPO,
				'package'     => $release['download_url'],
			);
		} else {
			$transient->no_update[ $this->basename ] = (object) array(
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => CLUVO_VERSION,
				'url'         => 'https://github.com/' . self::GITHUB_REPO,
				'package'     => '',
			);
		}

		return $transient;
	}

	/**
	 * Provide data for the "View version details" modal.
	 */
	public function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_release();
		if ( empty( $release['version'] ) ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Cluvo',
			'slug'          => $this->slug,
			'version'       => $release['version'],
			'author'        => '<a href="https://cluvo.ch">Cluvo</a>',
			'homepage'      => 'https://github.com/' . self::GITHUB_REPO,
			'last_updated'  => $release['published'],
			'download_link' => $release['download_url'],
			'sections'      => array(
				'changelog' => $release['changelog'],
			),
		);
	}

	/**
	 * Drop the cached release right after this plugin updates.
	 */
	public function flush_on_update( $upgrader, $options ) {
		if ( isset( $options['action'], $options['type'] ) && 'update' === $options['action'] && 'plugin' === $options['type'] ) {
			delete_transient( self::CACHE_KEY );
		}
	}

	/**
	 * Tiny Markdown→HTML converter for release notes (headings, bullets, bold,
	 * inline code). Good enough for the "View details" changelog.
	 */
	private static function markdown_to_html( $markdown ) {
		$out   = '';
		$inul  = false;
		$lines = preg_split( '/\r?\n/', (string) $markdown );

		foreach ( $lines as $line ) {
			$line = rtrim( $line );

			if ( preg_match( '/^#{1,6}\s*(.+)$/', $line, $m ) ) {
				if ( $inul ) {
					$out  .= "</ul>\n";
					$inul  = false;
				}
				$out .= '<h4>' . self::inline( $m[1] ) . "</h4>\n";
			} elseif ( preg_match( '/^\s*[-*]\s+(.+)$/', $line, $m ) ) {
				if ( ! $inul ) {
					$out  .= "<ul>\n";
					$inul  = true;
				}
				$out .= '<li>' . self::inline( $m[1] ) . "</li>\n";
			} elseif ( '' === trim( $line ) ) {
				if ( $inul ) {
					$out  .= "</ul>\n";
					$inul  = false;
				}
			} else {
				if ( $inul ) {
					$out  .= "</ul>\n";
					$inul  = false;
				}
				$out .= '<p>' . self::inline( $line ) . "</p>\n";
			}
		}

		if ( $inul ) {
			$out .= "</ul>\n";
		}

		return trim( $out );
	}

	private static function inline( $text ) {
		$text = esc_html( $text );
		$text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
		$text = preg_replace( '/`(.+?)`/', '<code>$1</code>', $text );
		return $text;
	}
}
