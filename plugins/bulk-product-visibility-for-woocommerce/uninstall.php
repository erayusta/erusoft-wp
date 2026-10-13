<?php
/**
 * Uninstall: nothing persistent to remove (jobs live in expiring transients).
 *
 * @package bulk-product-visibility-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_eru\_bpv\_%' OR option_name LIKE '\_transient\_timeout\_eru\_bpv\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
