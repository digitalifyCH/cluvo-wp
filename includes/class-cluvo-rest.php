<?php
/**
 * REST proxy so the browser can submit forms without ever seeing the API key.
 *
 * Route: POST /wp-json/cluvo/v1/accounts/{account}/forms/{slug}/submit
 * Forwards the payload to Cluvo using the account's API key and passes its
 * status/body straight back, so 422 validation errors reach the front-end
 * script unchanged.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cluvo_Rest {

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
				'methods'             => 'POST',
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function submit( WP_REST_Request $request ) {
		$slug    = $request->get_param( 'slug' );
		$account = (int) $request->get_param( 'account' );

		// Everything except the route params is forwarded as the submission body.
		$data = $request->get_params();
		unset( $data['slug'], $data['account'] );

		$result = $this->client->submit_form( $slug, $data, $account );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array( 'message' => $result->get_error_message() ),
				502
			);
		}

		return new WP_REST_Response( $result['body'], $result['status'] );
	}
}
