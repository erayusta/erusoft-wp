<?php
/**
 * Uninstall: delete options, tables, alerts, expired unused generated coupons and scheduled actions. The suppression list and
 * unexpired or used generated coupons go only with ERU_GRA_REMOVE_DATA. Order meta (reminder and consent flags) and order
 * notes are kept; see README "Sınırlamalar".
 *
 * @package growth-automations-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( array( 'eru_gra_run', 'eru_gra_run_more', 'eru_gra_notify', 'eru_gra_purge_more', 'eru_gra_cleanup_more', 'eru_gra_daily' ) as $eru_gra_hook ) {
		as_unschedule_all_actions( $eru_gra_hook, null, 'eru-gra' );
	}
}

$eru_gra_guard = 0;
do {
	$eru_gra_ids = get_posts( array( 'post_type' => 'eru_gra_alert', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 200, 'no_found_rows' => true ) );
	foreach ( $eru_gra_ids as $eru_gra_id ) {
		wp_delete_post( (int) $eru_gra_id, true );
	}
} while ( $eru_gra_ids && ++$eru_gra_guard < 500 );

// Generated coupons already e-mailed and still valid, or used by orders, stay unless ERU_GRA_REMOVE_DATA is true;
// expired, unused ones are dead and always go.
$eru_gra_all   = defined( 'ERU_GRA_REMOVE_DATA' ) && ERU_GRA_REMOVE_DATA;
$eru_gra_query = array(
	'post_type'      => 'shop_coupon',
	'post_status'    => 'any',
	'fields'         => 'ids',
	'posts_per_page' => 200,
	'no_found_rows'  => true,
	'meta_query'     => array( array( 'key' => '_eru_gra_generated', 'value' => '1' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
);
if ( ! $eru_gra_all ) {
	$eru_gra_query['meta_query'][] = array( 'key' => 'date_expires', 'value' => time(), 'compare' => '<', 'type' => 'NUMERIC' );
	$eru_gra_query['meta_query'][] = array( 'key' => 'usage_count', 'value' => '0', 'compare' => '=', 'type' => 'NUMERIC' );
}
$eru_gra_guard = 0;
do {
	$eru_gra_cids = get_posts( $eru_gra_query );
	foreach ( $eru_gra_cids as $eru_gra_cid ) {
		wp_delete_post( (int) $eru_gra_cid, true );
	}
} while ( $eru_gra_cids && ++$eru_gra_guard < 500 );

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}eru_gra_carts" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}eru_gra_log" ); // phpcs:ignore WordPress.DB

$eru_gra_opts = array( 'eru_gra_settings', 'eru_gra_db_version', 'eru_gra_has_data', 'eru_gra_reconsent' );
// Opt-outs must survive a reinstall: the suppression list goes only when ERU_GRA_REMOVE_DATA is defined as true.
if ( defined( 'ERU_GRA_REMOVE_DATA' ) && ERU_GRA_REMOVE_DATA ) {
	$eru_gra_opts[] = 'eru_gra_suppressed';
}
foreach ( $eru_gra_opts as $eru_gra_opt ) {
	delete_option( $eru_gra_opt );
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_eru\\_gra\\_%' OR option_name LIKE '\\_transient\\_timeout\\_eru\\_gra\\_%' OR option_name LIKE 'eru\\_gra\\_lock\\_%'" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'eru_gra_marketing_optin'" ); // phpcs:ignore WordPress.DB
