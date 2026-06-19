<?php
/**
 * Thin HTTP client for the Cluvo REST API.
 *
 * Supports multiple Cluvo accounts (one per Verein) on a single site. Each
 * account has a stable numeric id and its own API key; the shared base URL is
 * the same for all. All GET requests are cached per account. API keys never
 * leave the server.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cluvo_Client {

	const OPTION_KEY = 'cluvo_settings';

	/**
	 * @return array{base_url:string, cache_ttl:int, accounts:array, next_id:int}
	 */
	public function get_settings() {
		$defaults = array(
			'base_url'  => '',
			'cache_ttl' => 600,
			'accounts'  => array(),
			'next_id'   => 1,
		);
		return wp_parse_args( get_option( self::OPTION_KEY, array() ), $defaults );
	}

	/**
	 * @return array<int,array{id:int,name:string,api_key:string}>
	 */
	public function get_accounts() {
		$settings = $this->get_settings();
		return is_array( $settings['accounts'] ) ? $settings['accounts'] : array();
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
		$settings = $this->get_settings();
		return ! empty( $settings['base_url'] ) && ! empty( $settings['accounts'] );
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
	 * Submit a form. Not cached. Returns array{status,body} or WP_Error.
	 */
	public function submit_form( $slug, $data, $account_id ) {
		$key = $this->key_for( $account_id );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		return $this->request( 'POST', 'forms/' . rawurlencode( $slug ) . '/submit', $key, array( 'body' => $data ) );
	}

	/**
	 * Verify a key/base-url pair (used by the settings "test" buttons, also for
	 * not-yet-saved values).
	 *
	 * @return array{status:int,body:array}|WP_Error
	 */
	public function ping( $base_url, $api_key ) {
		return $this->request( 'GET', 'forms', $api_key, array( 'base_url' => $base_url ) );
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------- */

	private function key_for( $account_id ) {
		$account = $this->get_account( $account_id );
		if ( ! $account || empty( $account['api_key'] ) ) {
			return new WP_Error( 'cluvo_unknown_account', __( 'Unbekannter oder unvollständiger Verein.', 'cluvo' ) );
		}
		return $account['api_key'];
	}

	/**
	 * Cached GET. Returns the decoded body or WP_Error.
	 */
	private function get( $path, $params, $account_id ) {
		$key = $this->key_for( $account_id );
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$settings  = $this->get_settings();
		$cache_key = 'cluvo_' . md5( $account_id . '|' . $path . '|' . wp_json_encode( $params ) );

		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		if ( ! empty( $params ) ) {
			$path .= '?' . http_build_query( $params );
		}

		$response = $this->request( 'GET', $path, $key );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response['status'] >= 400 ) {
			return new WP_Error(
				'cluvo_api_error',
				sprintf( __( 'Cluvo-API antwortete mit Status %d.', 'cluvo' ), $response['status'] ),
				$response['body']
			);
		}

		$ttl = max( 30, (int) $settings['cache_ttl'] );
		set_transient( $cache_key, $response['body'], $ttl );

		return $response['body'];
	}

	/**
	 * Low-level request with an explicit API key.
	 *
	 * @param array $args Optional: body, base_url (override).
	 * @return array{status:int,body:array}|WP_Error
	 */
	private function request( $method, $path, $api_key, $args = array() ) {
		$settings = $this->get_settings();
		$base_url = isset( $args['base_url'] ) ? $args['base_url'] : $settings['base_url'];

		if ( empty( $base_url ) || empty( $api_key ) ) {
			return new WP_Error( 'cluvo_not_configured', __( 'Cluvo ist noch nicht konfiguriert.', 'cluvo' ) );
		}

		$url = trailingslashit( $base_url ) . ltrim( $path, '/' );

		$request_args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Accept'        => 'application/json',
			),
		);

		if ( isset( $args['body'] ) ) {
			$request_args['body'] = $args['body'];
		}

		$response = wp_remote_request( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		return array(
			'status' => $status,
			'body'   => is_array( $body ) ? $body : array(),
		);
	}

	/**
	 * Clear all cached Cluvo responses (called when settings change).
	 */
	public static function flush_cache() {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cluvo_%' OR option_name LIKE '_transient_timeout_cluvo_%'" );
	}
}
