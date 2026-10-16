<?php
/**
 * Uninstall: settings, per-marketplace options (credentials included), locks, migration state and every scheduled
 * job always go, and so do the working tables (raw payloads, listings, sync queue). The order map
 * (eru_mpo_orders) and the log are business/audit records: they are kept unless ERU_MPO_REMOVE_DATA is defined as
 * true. Order meta (_eru_mpo_*) and order notes are always kept, with or without the constant, and so is the
 * legacy plugins' data (never touched). See README "Kaldırma".
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Jobs first, so nothing below can be re-armed by a running action.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'eru-mpo' );
}

foreach ( array( 'eru_mpo_settings', 'eru_mpo_db_version', 'eru_mpo_migration', 'eru_mpo_legacy_present', 'eru_mpo_rearm' ) as $eru_mpo_opt ) {
	delete_option( $eru_mpo_opt );
}

// Per-marketplace options (eru_mpo_mp_<key>: credentials, stock bridge switches and rules), run state
// (eru_mpo_sync_<kind>_<key>: order/lifecycle pulls, stock bridge, listings, full sync, connection test), lock rows
// (eru_mpo_lock_<key>) and admin notice transients: bounded batches.
foreach ( array( 'eru_mpo_mp_', 'eru_mpo_sync_', 'eru_mpo_lock_', '_transient_eru_mpo_', '_transient_timeout_eru_mpo_' ) as $eru_mpo_prefix ) {
	for ( $eru_mpo_i = 0; $eru_mpo_i < 50; $eru_mpo_i++ ) {
		$eru_mpo_n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1000", $wpdb->esc_like( $eru_mpo_prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $eru_mpo_n < 1000 ) {
			break;
		}
	}
}
wp_cache_delete( 'alloptions', 'options' );
wp_cache_delete( 'notoptions', 'options' );

foreach ( array( 'raw', 'listings', 'sync_queue' ) as $eru_mpo_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'eru_mpo_' . $eru_mpo_table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
}

if ( defined( 'ERU_MPO_REMOVE_DATA' ) && true === ERU_MPO_REMOVE_DATA ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'eru_mpo_orders' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'eru_mpo_log' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
}
