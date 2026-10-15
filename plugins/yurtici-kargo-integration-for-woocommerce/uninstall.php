<?php
/**
 * Uninstall: settings, both tables, locks, transients and scheduled actions.
 * Order meta (_eru_yk_*) and order notes are kept; see README "Sınırlamalar".
 *
 * @package yurtici-kargo-integration-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( array( 'eru_yk_dispatch', 'eru_yk_track', 'eru_yk_daily' ) as $eru_yk_hook ) {
		as_unschedule_all_actions( $eru_yk_hook );
	}
}

foreach ( array( 'eru_yk_settings', 'eru_yk_db_version' ) as $eru_yk_opt ) {
	delete_option( $eru_yk_opt );
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'eru_yk_lock_' ) . '%', $wpdb->esc_like( '_transient_eru_yk_' ) . '%', $wpdb->esc_like( '_transient_timeout_eru_yk_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'eru_yk_log' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'eru_yk_events' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
