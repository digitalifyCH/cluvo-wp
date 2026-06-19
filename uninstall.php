<?php
/**
 * Remove plugin data on uninstall.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'cluvo_settings' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cluvo_%' OR option_name LIKE '_transient_timeout_cluvo_%'" );
