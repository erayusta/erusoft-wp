<?php
/**
 * Uninstall: settings, flags, the cached tokens, list caches, rate-limit counters, lock and verification
 * claim rows and the scheduled actions always go. The instruction table is kept unless ERU_VNV_REMOVE_DATA
 * is defined as true. Order meta (`_eru_vnv_*`) and order notes are always kept.
 * See README "Kaldırma".
 *
 * @package vakifbank-vinov-integration-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( array( 'woocommerce_eru_vnv_settings', 'eru_vnv_active', 'eru_vnv_rl', 'eru_vnv_db_version', 'eru_vnv_conn_result', 'eru_vnv_rearm' ) as $eru_vnv_opt ) {
	delete_option( $eru_vnv_opt );
}

// Tokens (eru_vnv_tok_*), per-identity counters (eru_vnv_rlt_*), list caches (eru_vnv_cache_*), the schedule
// throttle, lock rows (eru_vnv_lock_*) and verification claim rows (eru_vnv_vused_*). One statement per
// pattern, bounded by LIMIT and a fixed number of rounds.
foreach ( array( '_transient_eru_vnv_', '_transient_timeout_eru_vnv_', 'eru_vnv_lock_', 'eru_vnv_vused_' ) as $eru_vnv_prefix ) {
	for ( $eru_vnv_i = 0; $eru_vnv_i < 50; $eru_vnv_i++ ) {
		$eru_vnv_n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1000", $wpdb->esc_like( $eru_vnv_prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $eru_vnv_n < 1000 ) {
			break;
		}
	}
}
wp_cache_delete( 'alloptions', 'options' );
wp_cache_delete( 'notoptions', 'options' );

// The instruction table is a business record (which order has which bank instruction): it goes only with
// ERU_VNV_REMOVE_DATA. Order meta (`_eru_vnv_*`) and order notes are always kept, with or without the constant.
if ( defined( 'ERU_VNV_REMOVE_DATA' ) && ERU_VNV_REMOVE_DATA ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'eru_vnv_instructions' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
}

// Last, so nothing deleted above can re-arm a job.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( array( 'eru_vnv_sync', 'eru_vnv_daily', 'eru_vnv_conn_test', 'eru_vnv_bank_cancel' ) as $eru_vnv_hook ) {
		as_unschedule_all_actions( $eru_vnv_hook, null, 'eru-vnv' );
	}
}
