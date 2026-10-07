<?php
/**
 * Plugin Name:       Cluvo
 * Plugin URI:        https://www.cluvo.ch
 * Description:        Bindet Cluvo-Formulare, Veranstaltungen und News über die Cluvo REST-API in WordPress ein.
 * Version:           0.4.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Cluvo
 * Author URI:        https://www.cluvo.ch
 * License:           GPL-2.0-or-later
 * Text Domain:       cluvo
 * Update URI:        https://wp.cluvo.ch/cluvo/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'CLUVO_VERSION', '0.4.0' );
define( 'CLUVO_PLUGIN_FILE', __FILE__ );
define( 'CLUVO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CLUVO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once CLUVO_PLUGIN_DIR . 'includes/class-cluvo-client.php';
require_once CLUVO_PLUGIN_DIR . 'includes/class-cluvo-settings.php';
require_once CLUVO_PLUGIN_DIR . 'includes/class-cluvo-form-renderer.php';
require_once CLUVO_PLUGIN_DIR . 'includes/class-cluvo-shortcodes.php';
require_once CLUVO_PLUGIN_DIR . 'includes/class-cluvo-rest.php';
require_once CLUVO_PLUGIN_DIR . 'includes/class-cluvo-updater.php';
require_once CLUVO_PLUGIN_DIR . 'includes/class-cluvo-plugin.php';

/**
 * Boot the plugin once all plugins are loaded.
 */
function cluvo() {
	return Cluvo_Plugin::instance();
}

cluvo();
