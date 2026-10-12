<?php
/**
 * Uninstall: delete this plugin's own options and transients. User content
 * (posts, term/product/order meta, user roles) is kept on purpose.
 *
 * @package coupon-brute-force-guard-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'eru_cbg_enabled', 'eru_cbg_attempts', 'eru_cbg_window', 'eru_cbg_lockout', 'eru_cbg_message', 'eru_cbg_uniform_errors' ) as $eru_cbg_option ) {
	delete_option( $eru_cbg_option );
}

// Transients with a dynamic suffix.
global $wpdb;
foreach ( array( $wpdb->esc_like( '_transient_eru_cbg_lock_' ) . '%', $wpdb->esc_like( '_transient_timeout_eru_cbg_lock_' ) . '%', $wpdb->esc_like( '_transient_eru_cbg_hits_' ) . '%', $wpdb->esc_like( '_transient_timeout_eru_cbg_hits_' ) . '%' ) as $eru_cbg_like ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $eru_cbg_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
