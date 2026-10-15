<?php
/**
 * Uninstall: delete options, tables, transients and scheduled actions.
 * Order meta (_eru_dti_*) and order notes are kept; see README "Sınırlamalar".
 *
 * @package device-trade-in-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'eru_dti_daily', array(), 'eru-dti' );
}

foreach ( array( 'eru_dti_settings', 'eru_dti_db_version', 'eru_dti_cache_ver', 'eru_dti_hold_used', 'eru_dti_settings_hash', 'eru_dti_has_data' ) as $eru_dti_opt ) {
	delete_option( $eru_dti_opt );
}
delete_transient( 'eru_dti_lock' );

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_eru\_dti\_%' OR option_name LIKE '\_transient\_timeout\_eru\_dti\_%'" ); // phpcs:ignore WordPress.DB

foreach ( array( 'devices', 'tradeins', 'leads' ) as $eru_dti_t ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'eru_dti_' . $eru_dti_t ); // phpcs:ignore WordPress.DB
}
