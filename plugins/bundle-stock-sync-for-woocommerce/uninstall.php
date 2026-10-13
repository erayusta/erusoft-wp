<?php
/**
 * Uninstall: removes the plugin settings. Bundle definitions (product meta) and
 * order item snapshots are kept because they belong to products and orders.
 *
 * @package bundle-stock-sync-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_bss_settings' );
