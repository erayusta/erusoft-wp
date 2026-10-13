<?php
/**
 * Uninstall: delete the stored per-term orders (plugin-owned term meta).
 *
 * @package brand-product-sorting-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->delete( $wpdb->termmeta, array( 'meta_key' => '_eru_bps_order' ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
