<?php
/**
 * Uninstall: drops the lead table, deletes this plugin's options and its cron event.
 * Order data is untouched.
 *
 * @package abandoned-checkout-call-list-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}eru_accl_leads" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
delete_option( 'eru_accl_settings' );
delete_option( 'eru_accl_db_version' );
wp_clear_scheduled_hook( 'eru_accl_purge' );
