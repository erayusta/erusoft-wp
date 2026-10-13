<?php
/**
 * Uninstall: drop the log and delivery tables and delete this plugin's options (settings, credentials, rules).
 * Order notes written by the plugin belong to the orders and are kept.
 *
 * @package order-sms-delivery-codes-for-ileti-merkezi
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}eru_osms_log" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}eru_osms_delivery" ); // phpcs:ignore WordPress.DB

foreach ( array( 'eru_osms_settings', 'eru_osms_credentials', 'eru_osms_rules', 'eru_osms_db_version' ) as $option ) {
	delete_option( $option );
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'eru_osms_send' );
}
