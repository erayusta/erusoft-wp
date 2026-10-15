<?php
/**
 * Uninstall: settings, the log table, locks, transients and scheduled actions. The used-transaction ledger goes only with
 * ERU_GHA_REMOVE_DATA. Order meta (_eru_gha_*) and order notes are kept; see README "Sınırlamalar".
 *
 * @package bank-transfer-auto-approval-for-garanti-bbva
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( array( 'eru_gha_check', 'eru_gha_run_now', 'eru_gha_fetch', 'eru_gha_daily' ) as $eru_gha_hook ) {
		as_unschedule_all_actions( $eru_gha_hook );
	}
}

foreach ( array( 'eru_gha_settings', 'eru_gha_db_version', 'eru_gha_last_run' ) as $eru_gha_opt ) {
	delete_option( $eru_gha_opt );
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'eru_gha_lock_' ) . '%', $wpdb->esc_like( '_transient_eru_gha_' ) . '%', $wpdb->esc_like( '_transient_timeout_eru_gha_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'eru_gha_log' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
// The used-transaction ledger is an audit record: without it a reinstall could approve a second order with a transfer
// that was already used. It stays unless ERU_GHA_REMOVE_DATA is defined as true.
if ( defined( 'ERU_GHA_REMOVE_DATA' ) && ERU_GHA_REMOVE_DATA ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'eru_gha_used' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
}
