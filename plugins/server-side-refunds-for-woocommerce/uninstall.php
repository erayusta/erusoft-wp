<?php
/**
 * Uninstall: delete options, the queue table and scheduled actions.
 * Order meta (_eru_ssr_*) and order notes are kept; see README "Sınırlamalar".
 *
 * @package server-side-refunds-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( array( 'eru_ssr_process', 'eru_ssr_sweep', 'eru_ssr_purge' ) as $eru_ssr_hook ) {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( $eru_ssr_hook );
	}
}

delete_option( 'woocommerce_eru_ssr_settings' );
delete_option( 'eru_ssr_db_version' );
delete_option( 'eru_ssr_lock' );
delete_option( 'eru_ssr_resume' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'eru\\_ssr\\_lock\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'eru_ssr_queue' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
