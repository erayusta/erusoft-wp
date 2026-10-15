<?php
/**
 * Uninstall: options, the log table, locks, scheduled actions and the plugin's product/attachment meta.
 * Order meta (_eru_dpg_*) and order notes are kept; see README "Sınırlamalar".
 *
 * @package dopigo-integration-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( array( 'eru_dpg_push_product', 'eru_dpg_push_order', 'eru_dpg_push_status', 'eru_dpg_pull', 'eru_dpg_import', 'eru_dpg_daily', 'eru_dpg_tool' ) as $eru_dpg_hook ) {
		as_unschedule_all_actions( $eru_dpg_hook );
	}
}

foreach ( array( 'eru_dpg_settings', 'eru_dpg_token', 'eru_dpg_db_version', 'eru_dpg_pull_state', 'eru_dpg_import_state', 'eru_dpg_category_names', 'eru_dpg_index', 'eru_dpg_audit', 'eru_dpg_last_test', 'eru_dpg_last_pull', 'eru_dpg_last_status_pull' ) as $eru_dpg_opt ) {
	delete_option( $eru_dpg_opt );
}
// Locks, job progress, retry counters and transients.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'eru_dpg_lock_' ) . '%', $wpdb->esc_like( 'eru_dpg_job_' ) . '%', $wpdb->esc_like( '_transient_eru_dpg_' ) . '%', $wpdb->esc_like( '_transient_timeout_eru_dpg_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Product, variation and attachment meta written by the plugin (order meta is not touched).
$wpdb->query( $wpdb->prepare( "DELETE pm FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key LIKE %s AND p.post_type IN ('product', 'product_variation', 'attachment')", $wpdb->esc_like( '_eru_dpg_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'eru_dpg_log' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
