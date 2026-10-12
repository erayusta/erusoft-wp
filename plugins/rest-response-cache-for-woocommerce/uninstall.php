<?php
/**
 * Uninstall: delete this plugin's own options and transients. User content
 * (posts, term/product/order meta, user roles) is kept on purpose.
 *
 * @package rest-response-cache-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'eru_rrc_routes', 'eru_rrc_ttl', 'eru_rrc_version' ) as $eru_rrc_option ) {
	delete_option( $eru_rrc_option );
}

// Transients with a dynamic suffix.
global $wpdb;
foreach ( array( $wpdb->esc_like( '_transient_eru_rrc_' ) . '%', $wpdb->esc_like( '_transient_timeout_eru_rrc_' ) . '%' ) as $eru_rrc_like ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $eru_rrc_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
