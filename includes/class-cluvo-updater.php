<?php
/**
 * Updates from Cluvo's own update server (wp.cluvo.ch).
 *
 * The plugin header carries `Update URI: https://wp.cluvo.ch/cluvo/`, so
 * WordPress asks us – not wordpress.org – about new versions (filter
 * update_plugins_wp.cluvo.ch, WordPress 5.8+). That also keeps a plugin that
 * happens to be called "cluvo" on wordpress.org from ever replacing this one.
 *
 * The server publishes https://wp.cluvo.ch/cluvo/info.json (built by
 * bin/build.php, uploaded by the GitHub Action) and the versioned ZIPs next to
 * it. New versions appear in the dashboard with "Update now", the changelog in
 * "View details", and WordPress' automatic updates work as usual.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cluvo_Updater {

	const INFO_URL = 'https://wp.cluvo.ch/cluvo/info.json';

	const CACHE_KEY = 'cluvo_update_info';

	/** WordPress itself checks every 12 hours; this keeps our answer fresh in between. */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/** A failed check is retried after this long, so an outage doesn't slow every admin page. */
	const ERROR_TTL = HOUR_IN_SECONDS;

	/** Release info of the old GitHub-based updater (≤ 0.2.0). */
	const LEGACY_CACHE_KEY = 'cluvo_update_release';

	/** @var string e.g. cluvo/cluvo.php */
	private $basename;

	/** @var string e.g. cluvo */
	private $slug;

	public function __construct() {
		$this->basename = plugin_basename( CLUVO_PLUGIN_FILE );
		$this->slug     = dirname( $this->basename );

		add_filter( 'update_plugins_' . self::host(), array( $this, 'update_check' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'keep_folder_name' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_on_update' ), 10, 2 );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
		add_action( 'admin_post_cluvo_check_updates', array( $this, 'handle_check' ) );
		add_action( 'admin_notices', array( $this, 'plugins_screen_notice' ) );
	}

	/**
	 * Result of "Check for updates" from the plugin list (the settings page shows its own).
	 */
	public function plugins_screen_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'plugins' === $screen->id ) {
			Cluvo_Settings::update_check_notice();
		}
	}

	/**
	 * Address of info.json. Override for a staging server in wp-config.php:
	 *
	 *   define( 'CLUVO_UPDATE_URL', 'https://staging.example.ch/cluvo/info.json' );
	 */
	public static function info_url() {
		$url = defined( 'CLUVO_UPDATE_URL' ) ? CLUVO_UPDATE_URL : self::INFO_URL;

		return (string) apply_filters( 'cluvo_update_info_url', $url );
	}

	/**
	 * Host of the update server – must match the Update URI header, otherwise
	 * WordPress never calls update_check().
	 */
	private static function host() {
		return (string) wp_parse_url( self::INFO_URL, PHP_URL_HOST );
	}

	/**
	 * Latest release as published on the update server, cached.
	 *
	 * @return array{info:array, checked:int, error:string}
	 *   info: version, download_url, requires, requires_php, tested,
	 *   last_updated, homepage, icons, sections – empty if unknown.
	 */
	public function get_status( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) && isset( $cached['info'], $cached['checked'] ) ) {
				return $cached + array( 'error' => '' );
			}
		}

		$status = array(
			'info'    => array(),
			'checked' => time(),
			'error'   => '',
		);

		$response = wp_remote_get(
			self::info_url(),
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'user-agent'  => 'Cluvo-WordPress/' . CLUVO_VERSION . '; ' . home_url( '/' ),
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$status['error'] = $response->get_error_message();
		} elseif ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			/* translators: %d: HTTP status code */
			$status['error'] = sprintf( __( 'Der Update-Server antwortete mit Status %d.', 'cluvo' ), (int) wp_remote_retrieve_response_code( $response ) );
		} else {
			$status['info'] = self::validate( json_decode( wp_remote_retrieve_body( $response ), true ) );
			if ( empty( $status['info'] ) ) {
				$status['error'] = __( 'Der Update-Server lieferte keine gültigen Angaben.', 'cluvo' );
			}
		}

		set_transient( self::CACHE_KEY, $status, '' === $status['error'] ? self::CACHE_TTL : self::ERROR_TTL );

		return $status;
	}

	/**
	 * Only take what we understand. The package must come from the update
	 * server itself – a tampered info.json must not point WordPress elsewhere.
	 */
	private static function validate( $data ) {
		if ( ! is_array( $data ) || empty( $data['version'] ) || empty( $data['download_url'] ) ) {
			return array();
		}

		$version  = (string) $data['version'];
		$download = (string) $data['download_url'];
		$base     = trailingslashit( dirname( self::info_url() ) );

		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) || 0 !== strpos( $download, $base ) || ! preg_match( '/\.zip$/i', $download ) ) {
			return array();
		}

		$text = static function ( $key ) use ( $data ) {
			return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? sanitize_text_field( (string) $data[ $key ] ) : '';
		};

		$icons = array();
		if ( isset( $data['icons'] ) && is_array( $data['icons'] ) ) {
			foreach ( $data['icons'] as $size => $url ) {
				if ( is_string( $url ) && 0 === strpos( $url, $base ) ) {
					$icons[ sanitize_key( $size ) ] = esc_url_raw( $url );
				}
			}
		}

		$sections = array();
		if ( isset( $data['sections'] ) && is_array( $data['sections'] ) ) {
			foreach ( array( 'description', 'installation', 'changelog' ) as $section ) {
				if ( isset( $data['sections'][ $section ] ) && is_string( $data['sections'][ $section ] ) ) {
					$sections[ $section ] = wp_kses_post( $data['sections'][ $section ] );
				}
			}
		}

		return array(
			'version'      => $version,
			'download_url' => esc_url_raw( $download ),
			'requires'     => $text( 'requires' ),
			'requires_php' => $text( 'requires_php' ),
			'tested'       => $text( 'tested' ),
			'last_updated' => $text( 'last_updated' ),
			'homepage'     => isset( $data['homepage'] ) && is_string( $data['homepage'] ) ? esc_url_raw( $data['homepage'] ) : 'https://www.cluvo.ch',
			'icons'        => $icons,
			'sections'     => $sections,
		);
	}

	/**
	 * Is a newer version than the installed one published?
	 */
	public function update_available() {
		$status = $this->get_status();

		return ! empty( $status['info']['version'] ) && version_compare( CLUVO_VERSION, $status['info']['version'], '<' );
	}

	/**
	 * Answer WordPress' update check for this plugin (filter update_plugins_{host}).
	 * WordPress compares the version itself and puts us into "response" or "no_update".
	 */
	public function update_check( $update, $plugin_data, $plugin_file ) {
		if ( $plugin_file !== $this->basename ) {
			return $update;
		}

		$info = $this->get_status()['info'];
		if ( empty( $info['version'] ) ) {
			return $update;
		}

		return array(
			'slug'         => $this->slug,
			'version'      => $info['version'],
			'url'          => $info['homepage'],
			'package'      => $info['download_url'],
			'icons'        => $info['icons'],
			'tested'       => $info['tested'],
			'requires'     => $info['requires'],
			'requires_php' => $info['requires_php'],
		);
	}

	/**
	 * Data for the "View details" modal.
	 */
	public function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$info = $this->get_status()['info'];
		if ( empty( $info['version'] ) ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Cluvo',
			'slug'          => $this->slug,
			'version'       => $info['version'],
			'author'        => '<a href="https://www.cluvo.ch">Cluvo</a>',
			'homepage'      => $info['homepage'],
			'requires'      => $info['requires'],
			'requires_php'  => $info['requires_php'],
			'tested'        => $info['tested'],
			'last_updated'  => $info['last_updated'],
			'download_link' => $info['download_url'],
			'icons'         => $info['icons'],
			'sections'      => $info['sections'],
		);
	}

	/**
	 * "Check for updates" in the plugin list.
	 */
	public function row_meta( $links, $file ) {
		if ( $file !== $this->basename || ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}

		$links[] = '<a href="' . esc_url( self::check_url( 'plugins.php' ) ) . '">' . esc_html__( 'Nach Updates suchen', 'cluvo' ) . '</a>';

		return $links;
	}

	/**
	 * Link that asks the update server right away and returns to $return_to.
	 */
	public static function check_url( $return_to ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'cluvo_check_updates',
					'return' => rawurlencode( $return_to ),
				),
				admin_url( 'admin-post.php' )
			),
			'cluvo_check_updates'
		);
	}

	/**
	 * Forget every cached answer, ask again and refresh WordPress' own list,
	 * so "Update now" appears immediately.
	 */
	public function handle_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'cluvo' ), 403 );
		}
		check_admin_referer( 'cluvo_check_updates' );

		delete_transient( self::LEGACY_CACHE_KEY );
		$status = $this->get_status( true );

		delete_site_transient( 'update_plugins' );
		if ( ! function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . WPINC . '/update.php';
		}
		wp_update_plugins();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked above.
		$return = isset( $_GET['return'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['return'] ) ) ) : 'plugins.php';
		$return = in_array( $return, array( 'plugins.php', 'options-general.php?page=cluvo' ), true ) ? $return : 'plugins.php';

		$result = '' !== $status['error'] ? 'error' : ( $this->update_available() ? 'available' : 'current' );

		wp_safe_redirect( add_query_arg( 'cluvo_update_check', $result, admin_url( $return ) ) );
		exit;
	}

	/**
	 * The ZIP contains cluvo/. If the plugin lives in another folder (e.g. a git
	 * checkout named cluvo-wp), rename the unpacked source so the update
	 * replaces it instead of installing a second copy.
	 */
	public function keep_folder_name( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename || ! $wp_filesystem ) {
			return $source;
		}

		$target = trailingslashit( $remote_source ) . $this->slug . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $target ) ) {
			return $source;
		}

		return $wp_filesystem->move( $source, $target, true ) ? $target : $source;
	}

	/**
	 * Drop the cached release info right after a plugin update.
	 */
	public function flush_on_update( $upgrader, $options ) {
		if ( isset( $options['action'], $options['type'] ) && 'update' === $options['action'] && 'plugin' === $options['type'] ) {
			delete_transient( self::CACHE_KEY );
			delete_transient( self::LEGACY_CACHE_KEY );
		}
	}
}
