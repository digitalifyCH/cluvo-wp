<?php
/**
 * REST proxy so the browser can submit forms without ever seeing the API key.
 *
 * Route: POST /wp-json/cluvo/v1/accounts/{account}/forms/{slug}/submit
 * Forwards only the known form fields to Cluvo using the account's API key.
 * 201 and 422 responses reach the front-end script unchanged; other failures
 * are mapped to messages that don't expose details of the Cluvo account.
 *
 * The route is public by design (anonymous visitors submit forms). A honeypot
 * field and a per-IP limit keep bots from using it to flood the Verein.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cluvo_Rest {

	/** Name of the honeypot input rendered by Cluvo_Form_Renderer. */
	const HONEYPOT = 'cluvo_hp';

	/** Fields Cluvo accepts on a submission (besides custom_fields). */
	const FIELDS = array(
		'contact_type',
		'first_name',
		'last_name',
		'company_name',
		'email',
		'street',
		'number',
		'zip',
		'city',
		'membership_fee',
		'membership_interval',
		'membership_type_id',
	);

	const MAX_PAYLOAD_BYTES = 65536;

	/** @var Cluvo_Client */
	private $client;

	public function __construct( Cluvo_Client $client ) {
		$this->client = $client;
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'cluvo/v1',
			'/accounts/(?P<account>\d+)/forms/(?P<slug>[a-zA-Z0-9\-_]+)/submit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function submit( WP_REST_Request $request ) {
		$slug    = (string) $request->get_param( 'slug' );
		$account = (int) $request->get_param( 'account' );

		if ( ! $this->client->get_account( $account ) ) {
			return $this->response( array( 'message' => __( 'Dieses Formular ist nicht mehr verfügbar.', 'cluvo' ) ), 404 );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}

		// Bots fill every field – pretend success and drop the submission.
		if ( ! empty( $params[ self::HONEYPOT ] ) ) {
			return $this->response( array( 'status' => 'created' ), 201 );
		}

		if ( $this->too_many_attempts( $account ) ) {
			return $this->response( array( 'message' => __( 'Zu viele Anmeldungen in kurzer Zeit. Bitte versuche es in ein paar Minuten nochmals.', 'cluvo' ) ), 429 );
		}

		$data = self::payload( $params );

		if ( strlen( (string) wp_json_encode( $data ) ) > self::MAX_PAYLOAD_BYTES ) {
			return $this->response( array( 'message' => __( 'Die Angaben sind zu umfangreich.', 'cluvo' ) ), 413 );
		}

		$result = $this->client->submit_form( $slug, $data, $account );
		$error  = Cluvo_Client::response_error( $result );

		if ( ! $error ) {
			return $this->response( $result['body'], $result['status'] );
		}

		$status = Cluvo_Client::error_status( $error );

		if ( 422 === $status ) {
			return $this->response( $result['body'], 422 );
		}
		if ( 404 === $status ) {
			return $this->response( array( 'message' => __( 'Dieses Formular ist nicht mehr verfügbar.', 'cluvo' ) ), 404 );
		}
		if ( 429 === $status ) {
			return $this->response( array( 'message' => __( 'Zu viele Anmeldungen in kurzer Zeit. Bitte versuche es in ein paar Minuten nochmals.', 'cluvo' ) ), 429 );
		}

		return $this->response( array( 'message' => __( 'Das Formular konnte gerade nicht gesendet werden. Bitte versuche es später nochmals.', 'cluvo' ) ), 503 );
	}

	/**
	 * Keep only the fields Cluvo knows. Repeater rows are re-indexed so they
	 * are stored as a list, not as an object with gaps.
	 *
	 * @param array $params Raw request body.
	 * @return array
	 */
	public static function payload( $params ) {
		$data = array();

		foreach ( self::FIELDS as $key ) {
			if ( isset( $params[ $key ] ) && is_scalar( $params[ $key ] ) ) {
				$data[ $key ] = trim( (string) $params[ $key ] );
			}
		}

		if ( isset( $params['custom_fields'] ) && is_array( $params['custom_fields'] ) ) {
			foreach ( $params['custom_fields'] as $id => $value ) {
				if ( ! preg_match( '/^\d+$/', (string) $id ) ) {
					continue;
				}

				if ( is_scalar( $value ) ) {
					$data['custom_fields'][ (int) $id ] = trim( (string) $value );
					continue;
				}

				if ( ! is_array( $value ) ) {
					continue;
				}

				$rows = array();
				foreach ( $value as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$clean = array();
					foreach ( $row as $sub_key => $sub_value ) {
						if ( is_scalar( $sub_value ) && preg_match( '/^[A-Za-z0-9_\-]+$/', (string) $sub_key ) ) {
							$clean[ $sub_key ] = trim( (string) $sub_value );
						}
					}
					if ( $clean ) {
						$rows[] = $clean;
					}
				}
				$data['custom_fields'][ (int) $id ] = $rows;
			}
		}

		return $data;
	}

	/**
	 * Count submissions per IP and account. Filters:
	 * - cluvo_submit_rate_limit  (int, default 20, 0 disables)
	 * - cluvo_submit_rate_window (seconds, default 600)
	 * - cluvo_client_ip          (e.g. to read the IP from a trusted proxy header)
	 */
	private function too_many_attempts( $account_id ) {
		$limit  = (int) apply_filters( 'cluvo_submit_rate_limit', 20 );
		$window = (int) apply_filters( 'cluvo_submit_rate_window', 10 * MINUTE_IN_SECONDS );

		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip     = (string) apply_filters( 'cluvo_client_ip', $remote );

		if ( $limit <= 0 || $window <= 0 || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		$key  = 'cluvo_rl_' . md5( $account_id . '|' . $ip );
		$hits = (int) get_transient( $key );

		if ( $hits >= $limit ) {
			return true;
		}

		set_transient( $key, $hits + 1, $window );

		return false;
	}

	private function response( $body, $status ) {
		$response = new WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
