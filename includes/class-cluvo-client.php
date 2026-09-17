<?php
/**
 * Thin HTTP client for the Cluvo REST API.
 *
 * Supports multiple Cluvo accounts (one per Verein) on a single site. Each
 * account has a stable numeric id and its own API key. All requests go to the
 * Cluvo API (app.cluvo.ch) – for local development the base URL can be
 * overridden in wp-config.php:
 *
 *   define( 'CLUVO_API_BASE_URL', 'https://app.cluvo.ddev.site/api/v1' );
 *
 * GET requests are cached per account, key, locale and query. When Cluvo is
 * unreachable, the last good response is served for up to a day. API keys
 * never leave the server.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cluvo_Client {

	const OPTION_KEY = 'cluvo_settings';

	const GENERATION_OPTION = 'cluvo_cache_generation';

	const DEFAULT_BASE_URL = 'https://app.cluvo.ch/api/v1';

	/** Transient prefix of cached responses (flush_cache() deletes by it). */
	const CACHE_PREFIX = 'cluvo_c';

	/** Failed requests are remembered this long, so an outage doesn't slow every page view. */
	const ERROR_TTL = 60;

	/** Last good response, served while Cluvo is unreachable. */
	const STALE_TTL = 86400;

	/**
	 * API base URL – identical for every Verein, so it is no longer a setting.
	 */
	public static function base_url() {
		return defined( 'CLUVO_API_BASE_URL' ) ? CLUVO_API_BASE_URL : self::DEFAULT_BASE_URL;
	}

	/**
	 * @return array{cache_ttl:int, accounts:array, next_id:int}
	 */
	public function get_settings() {
		$defaults = array(
			'cache_ttl' => 600,
			'accounts'  => array(),
			'next_id'   => 1,
		);
		$settings = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $settings ) ? $settings : array(), $defaults );
	}

	/**
	 * @return array<int,array{id:int,name:string,api_key:string}>
	 */
	public function get_accounts() {
		$settings = $this->get_settings();
		return is_array( $settings['accounts'] ) ? array_values( $settings['accounts'] ) : array();
	}

	/**
	 * @return array{id:int,name:string,api_key:string}|null
	 */
	public function get_account( $id ) {
		foreach ( $this->get_accounts() as $account ) {
			if ( (int) $account['id'] === (int) $id ) {
				return $account;
			}
		}
		return null;
	}

	/**
	 * Resolve which account a shortcode refers to.
	 *
	 * - explicit id  → that account (or WP_Error if unknown)
	 * - no id, one account configured → that account
	 * - no id, several accounts → WP_Error asking to specify one
	 *
	 * @return array{id:int,name:string,api_key:string}|WP_Error
	 */
	public function resolve_account( $requested_id = 0 ) {
		$accounts = $this->get_accounts();

		if ( empty( $accounts ) ) {
			return new WP_Error( 'cluvo_no_accounts', __( 'Es ist noch kein Cluvo-Verein hinterlegt.', 'cluvo' ) );
		}

		if ( $requested_id ) {
			$account = $this->get_account( $requested_id );
			if ( ! $account ) {
				/* translators: %s: account id from the shortcode */
				return new WP_Error( 'cluvo_unknown_account', sprintf( __( 'Unbekannter Verein (account="%s").', 'cluvo' ), $requested_id ) );
			}
			return $account;
		}

		if ( count( $accounts ) === 1 ) {
			return $accounts[0];
		}

		return new WP_Error( 'cluvo_ambiguous_account', __( 'Es sind mehrere Vereine hinterlegt – bitte account="…" im Shortcode angeben.', 'cluvo' ) );
	}

	public function is_configured() {
		return ! empty( $this->get_accounts() );
	}

	/* ---------------------------------------------------------------------
	 * Public API methods (each scoped to an account id)
	 * ------------------------------------------------------------------- */

	public function get_forms( $account_id ) {
		return $this->get( 'forms', array(), $account_id );
	}

	public function get_form( $slug, $account_id ) {
		return $this->get( 'forms/' . rawurlencode( $slug ), array(), $account_id );
	}

	public function get_events( $params, $account_id ) {
		return $this->get( 'events', $params, $account_id );
	}

	public function get_news( $params, $account_id ) {
		return $this->get( 'news', $params, $account_id );
	}

	public function get_article( $slug, $account_id ) {
		return $this->get( 'news/' . rawurlencode( $slug ), array(), $account_id );
	}

	/**
	 * Submit a form. Not cached.
	 *
	 * @return array{status:int,body:array}|WP_Error
	 */
	public function submit_form( $slug, $data, $account_id ) {
		$account = $this->get_account( $account_id );
		if ( ! $account || empty( $account['api_key'] ) ) {
			return new WP_Error( 'cluvo_unknown_account', __( 'Unbekannter oder unvollständiger Verein.', 'cluvo' ) );
		}
		return $this->request( 'POST', 'forms/' . rawurlencode( $slug ) . '/submit', $account['api_key'], array( 'body' => $data ) );
	}

	/**
	 * Verify an API key (used by the settings "test" button, also for
	 * not-yet-saved keys).
	 *
	 * @return array{status:int,body:array}|WP_Error
	 */
	public function ping( $api_key ) {
		return $this->request( 'GET', 'forms', $api_key );
	}

	/**
	 * Turn a failed response into a WP_Error with an understandable message.
	 * Returns null for 2xx responses. The HTTP status is in the error data
	 * (0 when Cluvo could not be reached at all).
	 *
	 * @param array{status:int,body:array}|WP_Error $response
	 * @return WP_Error|null
	 */
	public static function response_error( $response ) {
		if ( is_wp_error( $response ) ) {
			if ( 'cluvo_not_configured' === $response->get_error_code() ) {
				return $response;
			}
			return new WP_Error(
				'cluvo_unreachable',
				/* translators: %s: technical error message */
				sprintf( __( 'Cluvo ist nicht erreichbar (%s).', 'cluvo' ), $response->get_error_message() ),
				array( 'status' => 0 )
			);
		}

		$status = (int) $response['status'];
		if ( $status >= 200 && $status < 300 ) {
			return null;
		}

		$api_message = isset( $response['body']['message'] ) && is_string( $response['body']['message'] ) ? $response['body']['message'] : '';

		if ( 401 === $status ) {
			$message = __( 'Der API-Key ist ungültig oder wurde in Cluvo widerrufen.', 'cluvo' );
		} elseif ( 403 === $status ) {
			$message = '' !== $api_message ? $api_message : __( 'Kein Zugriff auf die Cluvo-API.', 'cluvo' );
		} elseif ( 404 === $status ) {
			$message = __( 'Nicht gefunden. Ist der Slug richtig und das Formular bzw. der Artikel in Cluvo aktiv und öffentlich?', 'cluvo' );
		} elseif ( 429 === $status ) {
			$message = __( 'Zu viele Anfragen an Cluvo. Bitte versuche es gleich nochmals.', 'cluvo' );
		} elseif ( $status >= 500 ) {
			/* translators: %d: HTTP status code */
			$message = sprintf( __( 'Cluvo meldet einen Serverfehler (Status %d).', 'cluvo' ), $status );
		} else {
			/* translators: %d: HTTP status code */
			$message = sprintf( __( 'Cluvo-API antwortete mit Status %d.', 'cluvo' ), $status );
		}

		return new WP_Error( 'cluvo_api_error', $message, array( 'status' => $status ) );
	}

	/**
	 * HTTP status stored in a WP_Error from response_error() (0 if unknown).
	 */
	public static function error_status( WP_Error $error ) {
		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
	}

	/**
	 * Clear all cached Cluvo responses (called when settings change). Bumping
	 * the generation also invalidates entries held in a persistent object cache.
	 */
	public static function flush_cache() {
		global $wpdb;

		update_option( self::GENERATION_OPTION, (int) get_option( self::GENERATION_OPTION, 0 ) + 1 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . self::CACHE_PREFIX ) . '%'
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------- */

	/**
	 * Cached GET. Returns the decoded body or WP_Error.
	 */
	private function get( $path, $params, $account_id ) {
		$account = $this->get_account( $account_id );
		if ( ! $account || empty( $account['api_key'] ) ) {
			return new WP_Error( 'cluvo_unknown_account', __( 'Unbekannter oder unvollständiger Verein.', 'cluvo' ) );
		}

		if ( ! empty( $params ) ) {
			ksort( $params );
			$path .= '?' . http_build_query( $params );
		}

		$cache_key = $this->cache_key( $account, $path );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) && array_key_exists( 'body', $cached ) ) {
			return $cached['body'];
		}
		if ( is_array( $cached ) && isset( $cached['error'] ) ) {
			return new WP_Error( 'cluvo_api_error', $cached['error'], array( 'status' => $cached['status'] ) );
		}

		$response = $this->request( 'GET', $path, $account['api_key'] );
		$error    = self::response_error( $response );

		if ( ! $error ) {
			$ttl = max( 30, (int) $this->get_settings()['cache_ttl'] );
			set_transient( $cache_key, array( 'body' => $response['body'] ), $ttl );
			set_transient( $cache_key . '_s', array( 'body' => $response['body'] ), max( self::STALE_TTL, $ttl ) );
			return $response['body'];
		}

		$status = self::error_status( $error );

		if ( 0 === $status || 429 === $status || $status >= 500 ) {
			$stale = get_transient( $cache_key . '_s' );
			if ( is_array( $stale ) && array_key_exists( 'body', $stale ) ) {
				set_transient( $cache_key, $stale, self::ERROR_TTL );
				return $stale['body'];
			}
		} else {
			// Deactivated form, revoked key …: never resurrect the old copy.
			delete_transient( $cache_key . '_s' );
		}

		set_transient(
			$cache_key,
			array(
				'error'  => $error->get_error_message(),
				'status' => $status,
			),
			self::ERROR_TTL
		);

		return $error;
	}

	private function cache_key( $account, $path ) {
		$generation = (int) get_option( self::GENERATION_OPTION, 0 );
		$hash       = md5(
			wp_json_encode(
				array(
					(int) $account['id'],
					md5( $account['api_key'] ),
					self::base_url(),
					self::locale(),
					$path,
				)
			)
		);

		return self::CACHE_PREFIX . $generation . '_' . $hash;
	}

	private static function locale() {
		return function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
	}

	/**
	 * Low-level request with an explicit API key.
	 *
	 * @param array $args Optional: body.
	 * @return array{status:int,body:array}|WP_Error
	 */
	private function request( $method, $path, $api_key, $args = array() ) {
		if ( empty( $api_key ) ) {
			return new WP_Error( 'cluvo_not_configured', __( 'Cluvo ist noch nicht konfiguriert.', 'cluvo' ) );
		}

		$url = trailingslashit( self::base_url() ) . ltrim( $path, '/' );

		$request_args = array(
			'method'      => $method,
			'timeout'     => 'GET' === $method ? 8 : 15,
			// Never follow redirects: the bearer token would be sent along.
			'redirection' => 0,
			'user-agent'  => 'Cluvo-WordPress/' . CLUVO_VERSION . '; ' . home_url( '/' ),
			'headers'     => array(
				'Authorization'   => 'Bearer ' . $api_key,
				'Accept'          => 'application/json',
				'Accept-Language' => str_replace( '_', '-', self::locale() ),
			),
		);

		if ( isset( $args['body'] ) ) {
			$request_args['body'] = $args['body'];
		}

		/**
		 * Filters the HTTP arguments for requests to the Cluvo API, e.g. to
		 * disable SSL verification against a local development instance.
		 */
		$request_args = apply_filters( 'cluvo_http_request_args', $request_args, $method, $path );

		$response = wp_remote_request( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => is_array( $body ) ? $body : array(),
		);
	}
}
