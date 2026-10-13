<?php
/**
 * Uninstall: delete this plugin's options (locations, settings). Order meta
 * (`_eru_ccl_*`) belongs to the orders and is kept; WooCommerce zone instances are managed by WooCommerce.
 *
 * @package click-collect-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_ccl_locations' );
delete_option( 'eru_ccl_settings' );
