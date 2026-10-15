<?php
/**
 * Uninstall: delete options, subscribers, expired unused generated coupons and scheduled actions. Unexpired or used generated
 * coupons and order data are kept unless ERU_CB_REMOVE_DATA is true; see README "Sınırlamalar".
 *
 * @package conversion-boosters-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'eru_cb_daily', array(), 'eru-cb' );
	as_unschedule_all_actions( 'eru_cb_cleanup_more', array(), 'eru-cb' );
	as_unschedule_all_actions( 'eru_cb_purge_more', array(), 'eru-cb' );
}

foreach ( get_posts( array( 'post_type' => 'eru_cb_subscriber', 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => -1 ) ) as $eru_cb_id ) {
	wp_delete_post( (int) $eru_cb_id, true );
}

// Generated coupons a customer may still hold (unexpired) or that orders used stay, unless ERU_CB_REMOVE_DATA is true;
// expired, unused ones are dead and always go.
$eru_cb_all   = defined( 'ERU_CB_REMOVE_DATA' ) && ERU_CB_REMOVE_DATA;
$eru_cb_query = array(
	'post_type'      => 'shop_coupon',
	'post_status'    => 'any',
	'fields'         => 'ids',
	'posts_per_page' => 200,
	'no_found_rows'  => true,
	'meta_query'     => array( array( 'key' => '_eru_cb_generated', 'value' => '1' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
);
if ( ! $eru_cb_all ) {
	$eru_cb_query['meta_query'][] = array( 'key' => 'date_expires', 'value' => time(), 'compare' => '<', 'type' => 'NUMERIC' );
	$eru_cb_query['meta_query'][] = array( 'key' => 'usage_count', 'value' => '0', 'compare' => '=', 'type' => 'NUMERIC' );
}
$eru_cb_guard = 0;
do {
	$eru_cb_cids = get_posts( $eru_cb_query );
	foreach ( $eru_cb_cids as $eru_cb_cid ) {
		wp_delete_post( (int) $eru_cb_cid, true );
	}
} while ( $eru_cb_cids && ++$eru_cb_guard < 500 );

foreach ( array( 'eru_cb_settings', 'eru_cb_cache_ver', 'eru_cb_has_data' ) as $eru_cb_opt ) {
	delete_option( $eru_cb_opt );
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_eru\_cb\_%' OR option_name LIKE '\_transient\_timeout\_eru\_cb\_%' OR option_name LIKE 'eru\_cb\_lock\_%'" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = '_eru_cb_timed_cooldown'" ); // phpcs:ignore WordPress.DB
