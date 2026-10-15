<?php
/**
 * Uninstall: options, caches, locks and scheduled actions. The plugin's product/variation meta goes only with
 * ERU_PPT_REMOVE_DATA. Order data is never touched (the plugin does not write any); see README "Kaldırma".
 *
 * @package product-push-for-trendyol
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( array( 'eru_ppt_poll', 'eru_ppt_push', 'eru_ppt_refresh' ) as $eru_ppt_hook ) {
		as_unschedule_all_actions( $eru_ppt_hook );
	}
}

foreach ( array( 'eru_ppt_settings', 'eru_ppt_batches', 'eru_ppt_log', 'eru_ppt_notice', 'eru_ppt_attr_index' ) as $eru_ppt_opt ) {
	delete_option( $eru_ppt_opt );
}
// Locks, open batch rows and transients (category tree, attribute caches, bulk results).
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'eru_ppt_lock_' ) . '%', $wpdb->esc_like( 'eru_ppt_batch_' ) . '%', $wpdb->esc_like( '_transient_eru_ppt_' ) . '%', $wpdb->esc_like( '_transient_timeout_eru_ppt_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Product and variation meta (typed barcodes, attribute answers, the approval-state link to Trendyol) is the merchant's
// data: without it a reinstall cannot tell which products already exist on Trendyol. It goes only with ERU_PPT_REMOVE_DATA.
if ( defined( 'ERU_PPT_REMOVE_DATA' ) && ERU_PPT_REMOVE_DATA ) {
	$wpdb->query( $wpdb->prepare( "DELETE pm FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key LIKE %s AND p.post_type IN ('product', 'product_variation')", $wpdb->esc_like( '_eru_ppt_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
