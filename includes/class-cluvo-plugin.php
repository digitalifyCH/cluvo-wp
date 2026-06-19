<?php
/**
 * Main orchestrator: wires up settings, shortcodes, REST proxy and assets.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cluvo_Plugin {

	private static $instance = null;

	/** @var Cluvo_Client */
	public $client;

	/** @var Cluvo_Settings */
	public $settings;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->client   = new Cluvo_Client();
		$this->settings = new Cluvo_Settings( $this->client );

		new Cluvo_Shortcodes( $this->client );
		new Cluvo_Rest( $this->client );
		new Cluvo_Updater();

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register front-end assets. They are only enqueued by shortcodes that need them.
	 */
	public function register_assets() {
		wp_register_style( 'cluvo', CLUVO_PLUGIN_URL . 'assets/cluvo.css', array(), CLUVO_VERSION );

		wp_register_script( 'cluvo', CLUVO_PLUGIN_URL . 'assets/cluvo.js', array(), CLUVO_VERSION, true );
		wp_localize_script(
			'cluvo',
			'CluvoData',
			array(
				'restUrl' => esc_url_raw( rest_url( 'cluvo/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'submitting' => __( 'Wird gesendet …', 'cluvo' ),
					'error'      => __( 'Es ist ein Fehler aufgetreten. Bitte versuche es erneut.', 'cluvo' ),
				),
			)
		);
	}
}
