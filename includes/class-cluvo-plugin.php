<?php
/**
 * Main orchestrator: wires up settings, shortcodes, REST proxy, translations
 * and assets.
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

	/** @var Cluvo_Updater */
	public $updater;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->client   = new Cluvo_Client();
		$this->updater  = new Cluvo_Updater();
		$this->settings = new Cluvo_Settings( $this->client, $this->updater );

		new Cluvo_Shortcodes( $this->client );
		new Cluvo_Rest( $this->client );

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * German is the source language. The bundled fr_FR/it_IT files also serve
	 * Swiss and other regional locales (fr_CH, it_CH …) – WordPress itself only
	 * looks for the exact locale. Files in wp-content/languages/plugins win.
	 */
	public function load_textdomain() {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$map    = array(
			'fr' => 'fr_FR',
			'it' => 'it_IT',
		);
		$lang   = strtolower( substr( $locale, 0, 2 ) );
		$file   = CLUVO_PLUGIN_DIR . 'languages/cluvo-' . ( isset( $map[ $lang ] ) ? $map[ $lang ] : $locale ) . '.mo';

		if ( is_readable( $file ) && ! is_readable( WP_LANG_DIR . '/plugins/cluvo-' . $locale . '.mo' ) ) {
			load_textdomain( 'cluvo', $file, $locale );
			return;
		}

		load_plugin_textdomain( 'cluvo', false, dirname( plugin_basename( CLUVO_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Register front-end assets. On singular pages whose content contains a
	 * Cluvo shortcode they are enqueued right away (no flash of unstyled
	 * content); elsewhere – widgets, page builders – the shortcodes enqueue
	 * them late.
	 */
	public function register_assets() {
		wp_register_style( 'cluvo', CLUVO_PLUGIN_URL . 'assets/cluvo.css', array(), CLUVO_VERSION );

		wp_register_script( 'cluvo', CLUVO_PLUGIN_URL . 'assets/cluvo.js', array(), CLUVO_VERSION, true );
		wp_localize_script(
			'cluvo',
			'CluvoData',
			array(
				'i18n' => array(
					'submitting'    => __( 'Wird gesendet …', 'cluvo' ),
					'success'       => __( 'Vielen Dank! Deine Anmeldung wurde empfangen.', 'cluvo' ),
					'pending'       => __( 'Vielen Dank! Dein Antrag wurde empfangen und wird geprüft.', 'cluvo' ),
					'invalid'       => __( 'Bitte prüfe die markierten Angaben.', 'cluvo' ),
					'error'         => __( 'Es ist ein Fehler aufgetreten. Bitte versuche es erneut.', 'cluvo' ),
					'contactPerson' => __( 'Ansprechperson (freiwillig)', 'cluvo' ),
					/* translators: %s: row number */
					'row'           => __( 'Eintrag %s', 'cluvo' ),
				),
			)
		);

		$post = get_post();
		if ( ! is_singular() || ! $post instanceof WP_Post ) {
			return;
		}

		$has = array();
		foreach ( Cluvo_Shortcodes::TAGS as $tag ) {
			$has[ $tag ] = has_shortcode( $post->post_content, $tag );
		}

		if ( in_array( true, $has, true ) ) {
			wp_enqueue_style( 'cluvo' );
		}
		if ( $has['cluvo_form'] ) {
			wp_enqueue_script( 'cluvo' );
		}
	}
}
