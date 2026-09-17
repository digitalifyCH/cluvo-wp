<?php
/**
 * Remove plugin data on uninstall.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'cluvo_settings' );
delete_option( 'cluvo_cache_generation' );

// Cached API responses, rate limits and the cached release info.
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_cluvo\\_%' OR option_name LIKE '\\_transient\\_timeout\\_cluvo\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
