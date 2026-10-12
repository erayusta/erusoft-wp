<?php
/**
 * Uninstall: delete this plugin's own options. User content
 * (posts, term/product/order meta, user roles) is kept on purpose.
 *
 * @package order-risk-score-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'eru_ors_band_medium', 'eru_ors_band_high', 'eru_ors_high_amount', 'eru_ors_manual_amount', 'eru_ors_manual_gateways', 'eru_ors_disposable_domains', 'eru_ors_risky_countries', 'eru_ors_watchlist' ) as $eru_ors_option ) {
	delete_option( $eru_ors_option );
}

// Per-signal weight options.
global $wpdb;
foreach ( array( $wpdb->esc_like( 'eru_ors_weight_' ) . '%' ) as $eru_ors_like ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $eru_ors_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
